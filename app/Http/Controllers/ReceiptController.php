<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Helpers\ExcelExportHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericExcelExport;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use App\Models\ReceiptModel;
use App\Models\MumineenModel;
use App\Models\EstablishmentModel;
use App\Models\YearModel;
use App\Models\CounterModel;
use App\Models\MumineenSabeelModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\AdvancePaidModel;
use Mpdf\Mpdf;

class ReceiptController extends Controller
{
    //
    use ApiResponse;

    /**
     * CREATE
     * POST /receipt/create
     * Body:
     * {
     *   "type":"family|establishment",
     *   "family_id": "",
     *   "establishment_id": "",
     *   "mode": "cash|cheque|neft",
     *   "amount": 2100,
     *   "remarks": "",
     *   "trans_id": "",
     *   "trans_date": "YYYY-MM-DD",
     *   "bank": "",
     *   "cheque_no": "",
     *   "cheque_date": "YYYY-MM-DD",
     *   "ifsc": ""
     * }
     */
    public function create(Request $request)
    {
        try {
            DB::beginTransaction();

            $validator = Validator::make($request->all(), [
                'type' => 'required|in:family,establishment',
                'family_id'        => 'required_if:type,family|nullable|integer',
                'establishment_id' => 'required_if:type,establishment|nullable|integer',
                'mode'   => 'required|in:cash,cheque,neft',
                'amount' => 'required|numeric|min:0',
                'remarks'    => 'nullable|string',
                'trans_id'   => 'nullable|string|max:255',
                'trans_date' => 'nullable|date',
                'bank'       => 'nullable|string|max:255',
                'cheque_no'  => 'nullable|string|max:255',
                'cheque_date'=> 'nullable|date',
                'ifsc'       => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return $this->validation($validator);
            }

            $type = $request->type;
            $familyId = $type === 'family' ? (int) $request->family_id : null;
            $establishmentId = $type === 'establishment' ? (int) $request->establishment_id : null;
            $mode = $request->mode;
            $amount = (float) $request->amount;
            $date = now()->toDateString();

            // Validate family/establishment exists
            if ($type === 'family') {
                $hof = MumineenModel::where('family_id', $familyId)
                    ->where('hof_type', 'HOF')
                    ->where('status', 'active')
                    ->first();
                if (!$hof) {
                    DB::rollBack();
                    return $this->error('Invalid family_id. Family not found.', 404);
                }
            } else {
                $est = EstablishmentModel::where('establishment_id', $establishmentId)->first();
                if (!$est) {
                    DB::rollBack();
                    return $this->error('Invalid establishment_id. Establishment not found.', 404);
                }
            }

            // Calculate total due (including advance_paid)
            $totalDue = $this->calculateTotalDue($type, $familyId, $establishmentId);
            
            if ($amount > $totalDue) {
                DB::rollBack();
                return $this->error("Amount ({$amount}) cannot exceed total due ({$totalDue}).", 422);
            }

            // Get year-wise dues (oldest first)
            $yearWiseDues = $this->getYearWiseDues($type, $familyId, $establishmentId);

            $createdReceipts = [];
            $remainingAmount = $amount;

            // Process year-wise (oldest first)
            foreach ($yearWiseDues as $yearData) {
                if ($remainingAmount <= 0) break;

                $year = $yearData['year'];
                $due = $yearData['due'];

                if ($due <= 0) continue;

                $amountForYear = min($remainingAmount, $due);

                // Handle cash mode splitting
                if ($mode === 'cash' && $amountForYear > 10000) {
                    $receipts = $this->createCashSplitReceipts($type, $familyId, $establishmentId, $year, $amountForYear, $request, $date);
                    $createdReceipts = array_merge($createdReceipts, $receipts);
                    $remainingAmount -= $amountForYear;
                } else {
                    // Non-cash mode or amount <= 10000
                    $receipt = $this->createReceiptForYear($type, $familyId, $establishmentId, $year, $amountForYear, $request, $date);
                    if ($receipt) {
                        $createdReceipts[] = $receipt;
                        $remainingAmount -= $amountForYear;
                    }
                }
            }

            // If amount left after all dues paid, save to advance_paid
            $advancePaidEntry = null;
            if ($remainingAmount > 0) {
                $advancePaidEntry = $this->saveToAdvancePaid($type, $familyId, $establishmentId, $remainingAmount, $mode, $date, $request->remarks ?? null);
            }

            DB::commit();

            return $this->success('Receipt(s) created successfully', [
                'receipts' => $createdReceipts,
                'advance_paid' => $advancePaidEntry,
                'total_receipts' => count($createdReceipts),
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->serverError($e, 'Receipt create failed');
        }
    }

    /**
     * Process Advance Paid entries
     * POST /receipt/process-advance-paid
     * Body: { "date": "YYYY-MM-DD" } (optional, defaults to yesterday)
     */
    public function processAdvancePaid(Request $request)
    {
        try {
            DB::beginTransaction();

            // Get date (default to yesterday)
            $processDate = $request->input('date', now()->subDay()->toDateString());

            // Get pending entries for the specified date
            $entries = AdvancePaidModel::where('status', 'pending')
                ->whereDate('date', $processDate)
                ->get();

            $processed = 0;
            $failed = 0;
            $remaining = 0;
            $createdReceipts = [];
            $errors = [];

            foreach ($entries as $entry) {
                try {
                    $type = $entry->type;
                    $familyId = $entry->family_id;
                    $establishmentId = $entry->establishment_id;
                    $amount = (float) $entry->amount;
                    $mode = $entry->mode;
                    $date = $entry->date->toDateString();

                    // Calculate current due (including advance_paid)
                    $totalDue = $this->calculateTotalDue($type, $familyId, $establishmentId);

                    if ($amount > $totalDue) {
                        // Amount exceeds due, mark as failed
                        $entry->status = 'failed';
                        $entry->save();
                        $failed++;
                        $errors[] = "Entry {$entry->id}: Amount ({$amount}) exceeds total due ({$totalDue})";
                        continue;
                    }

                    // Get year-wise dues
                    $yearWiseDues = $this->getYearWiseDues($type, $familyId, $establishmentId);

                    if (empty($yearWiseDues)) {
                        // No dues, mark as failed
                        $entry->status = 'failed';
                        $entry->save();
                        $failed++;
                        $errors[] = "Entry {$entry->id}: No dues found";
                        continue;
                    }

                    $remainingAmount = $amount;
                    $entryReceipts = [];

                    // Process year-wise (oldest first)
                    foreach ($yearWiseDues as $yearData) {
                        if ($remainingAmount <= 0) break;

                        $year = $yearData['year'];
                        $due = $yearData['due'];

                        if ($due <= 0) continue;

                        $amountForYear = min($remainingAmount, $due);

                        // Create request object for receipt creation
                        $receiptRequest = new Request([
                            'mode' => $mode,
                            'remarks' => $entry->remarks,
                        ]);

                        // Handle cash mode splitting
                        if ($mode === 'cash' && $amountForYear > 10000) {
                            $receipts = $this->createCashSplitReceipts($type, $familyId, $establishmentId, $year, $amountForYear, $receiptRequest, $date);
                            $entryReceipts = array_merge($entryReceipts, $receipts);
                            $remainingAmount -= $amountForYear;
                        } else {
                            // Non-cash mode or amount <= 10000
                            $receipt = $this->createReceiptForYear($type, $familyId, $establishmentId, $year, $amountForYear, $receiptRequest, $date);
                            if ($receipt) {
                                $entryReceipts[] = $receipt;
                                $remainingAmount -= $amountForYear;
                            }
                        }
                    }

                    if ($remainingAmount > 0) {
                        // Still has remaining amount, update entry amount and keep as pending
                        $entry->amount = $remainingAmount;
                        $entry->save();
                        $remaining++;
                    } else {
                        // Fully processed
                        $entry->status = 'processed';
                        $entry->save();
                        $processed++;
                    }

                    $createdReceipts = array_merge($createdReceipts, $entryReceipts);

                } catch (\Throwable $e) {
                    // Mark as failed and log error
                    $entry->status = 'failed';
                    $entry->save();
                    $failed++;
                    $errors[] = "Entry {$entry->id}: " . $e->getMessage();
                }
            }

            DB::commit();

            return $this->success('Advance paid processing completed', [
                'processed' => $processed,
                'failed' => $failed,
                'remaining' => $remaining,
                'total_entries' => $entries->count(),
                'created_receipts' => count($createdReceipts),
                'receipts' => $createdReceipts,
                'errors' => $errors,
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->serverError($e, 'Advance paid processing failed');
        }
    }

    /**
     * FETCH
     * POST /receipts/retrieve/{id?}
     * Body:
     * {
     *   "type":"family|establishment",
     *   "family_id": null,
     *   "establishment_id": null,
     *   "mode": "cash|cheque|neft",
     *   "date_from":"YYYY-MM-DD",
     *   "date_to":"YYYY-MM-DD",
     *   "limit":10,
     *   "offset":0
     * }
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            // SINGLE
            if ($id !== null) {
                $r = ReceiptModel::find($id);
                if (!$r) return $this->error('Receipt not found.', 404);

                $item = $this->mapReceipt($r);

                return $this->success('Data fetched successfully', [$item], 200);
            }

            $validator = Validator::make($request->all(), [
                'type' => 'nullable|in:family,establishment',

                'family_id'        => 'nullable|integer',
                'establishment_id' => 'nullable|integer',

                'mode' => 'nullable|in:cash,cheque,neft',

                'date_from' => 'nullable|date',
                'date_to'   => 'nullable|date',

                'limit'  => 'nullable|integer|min:1',
                'offset' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $limit  = max(1, (int) $request->input('limit', 10));
            $offset = max(0, (int) $request->input('offset', 0));

            $type = $request->input('type');

            $q = ReceiptModel::query()
                ->orderBy('id', 'desc');

            // Adjust the query based on the `type`
            // If no type is passed, return both family and establishment receipts
            if ($type === 'family') {
                $q->whereNotNull('family_id');
            } elseif ($type === 'establishment') {
                $q->whereNotNull('establishment_id');
            } else {
                // If `type` is null or not provided, include both family and establishment receipts
                $q->where(function($query) {
                    $query->whereNotNull('family_id')
                        ->orWhereNotNull('establishment_id');
                });
            }

            if ($request->filled('family_id')) {
                $q->where('family_id', (int)$request->family_id);
            }

            if ($request->filled('establishment_id')) {
                $q->where('establishment_id', (int)$request->establishment_id);
            }

            if ($request->filled('mode')) {
                $q->where('mode', $request->mode);
            }

            if ($request->filled('date_from')) {
                $q->whereDate('date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $q->whereDate('date', '<=', $request->date_to);
            }

            $total = (clone $q)->count();

            $rows = $q->skip($offset)->take($limit)->get();

            $data = $rows->map(fn($r) => $this->mapReceipt($r))->values()->all();

            return $this->success('Data fetched successfully', $data, 200, [
                'pagination' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                    'count'  => count($data),
                    'total'  => $total,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Receipt fetch failed');
        }
    }

    /**
     * UPDATE
     * POST /receipts/update/{id}
     * Body:
     * {
     *   "amount":"",
     *   "remarks":"",
     *   "trans_id":"",
     *   "trans_date":"",
     *   "bank":"",
     *   "cheque_no":"",
     *   "cheque_date":"",
     *   "ifsc":""
     * }
     */
    public function edit(Request $request, $id)
    {
        try {
            $r = ReceiptModel::find($id);
            if (!$r) return $this->error('Receipt not found.', 404);

            $validator = Validator::make($request->all(), [
                'amount'      => 'required|numeric|min:0',
                'remarks'     => 'nullable|string',

                'trans_id'    => 'nullable|string|max:255',
                'trans_date'  => 'nullable|date',

                'bank'        => 'nullable|string|max:255',
                'cheque_no'   => 'nullable|string|max:255',
                'cheque_date' => 'nullable|date',
                'ifsc'        => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $r->amount = $request->amount;
            $r->comment = $request->remarks ?? null;

            $r->transaction_no = $request->trans_id ?? null;
            $r->transaction_date = $request->trans_date ?? null;

            $r->bank = $request->bank ?? null;
            $r->cheque_no = $request->cheque_no ?? null;
            $r->cheque_date = $request->cheque_date ?? null;
            $r->ifsc = $request->ifsc ?? null;

            $r->updated_by = (int) Auth::id();
            $r->save();

            return $this->success('Data saved successfully', $r, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Receipt update failed');
        }
    }

    /**
     * DELETE (soft style by status)
     * DELETE /receipts/delete/{id}
     */
    public function delete($id)
    {
        try {
            $r = ReceiptModel::find($id);
            if (!$r) return $this->error('Receipt not found.', 404);

            // keep record but mark cancelled
            $r->status = 'cancelled';
            $r->updated_by = (int) Auth::id();
            $r->save();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Receipt delete failed');
        }
    }

    // export
    public function export(Request $request)
    {
        try {
            $type     = $request->input('type'); // family | establishment | null
            $dateFrom = $request->input('date_from');
            $dateTo   = $request->input('date_to');

            $q = ReceiptModel::query()
                ->where('status', 'active')
                ->orderBy('receipt_no', 'desc');

            // ✅ SAME TYPE LOGIC as fetch()
            if ($type === 'family') {
                $q->whereNotNull('family_id');
            } elseif ($type === 'establishment') {
                $q->whereNotNull('establishment_id');
            } else {
                $q->where(function ($query) {
                    $query->whereNotNull('family_id')
                        ->orWhereNotNull('establishment_id');
                });
            }

            // ✅ SAME filters as fetch()
            if ($request->filled('family_id')) {
                $q->where('family_id', (int) $request->family_id);
            }

            if ($request->filled('establishment_id')) {
                $q->where('establishment_id', (int) $request->establishment_id);
            }

            if (!empty($dateFrom) && strtotime($dateFrom) !== false) {
                $q->whereDate('date', '>=', $dateFrom);
            }

            if (!empty($dateTo) && strtotime($dateTo) !== false) {
                $q->whereDate('date', '<=', $dateTo);
            }

            // ✅ NO pagination
            $rows = $q->get();

            if ($rows->isEmpty()) {
                return $this->error('No data found for export.', 404);
            }

            $excelRows = [];
            $sn = 1;

            foreach ($rows as $r) {
                $excelRows[] = [
                    $sn++,
                    $r->receipt_no,
                    optional($r->date)->format('d-m-Y'),
                    $r->name,
                    (float) $r->amount,
                    $r->mode,
                    ucfirst($r->status),
                ];
            }

            $export = new GenericExcelExport(
                $excelRows,
                ['SN','Receipt No','Date','Name','Amount','Mode','Status'],
                [
                    'A' => Alignment::HORIZONTAL_CENTER,
                    'B' => Alignment::HORIZONTAL_CENTER,
                    'C' => Alignment::HORIZONTAL_CENTER,
                    'D' => Alignment::HORIZONTAL_LEFT,
                    'E' => Alignment::HORIZONTAL_RIGHT,
                    'F' => Alignment::HORIZONTAL_CENTER,
                    'G' => Alignment::HORIZONTAL_CENTER,
                ]
            );

            return ExcelExportHelper::store($export, 'receipt', 'receipt_export');

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Receipt export failed');
        }
    }

   /**
     * Generate and return receipt PDF
     * 
     * @param int $id Receipt ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateReceipt($id)
    {
        try {
            // Find the receipt by ID
            $receipt = ReceiptModel::find($id);

            // Check if receipt exists
            if (!$receipt) {
                return $this->error('Receipt not found', 404);
            }

            // Prepare data for the blade template
            $data = [
                'receiptNumber' => $receipt->receipt_no,
                'date' => date('d-m-Y', strtotime($receipt->date)),
                'receivedFrom' => $receipt->name,
                'itsNumber' => $receipt->its,
                'amount' => 'Rs. ' . number_format($receipt->amount, 2),
                'amountInWords' => $this->convertNumberToWords($receipt->amount),
                'paymentMode' => ucfirst($receipt->mode),
                'chequeNo' => $receipt->mode === 'cheque' ? $receipt->cheque_no : $receipt->transaction_no,
                'year' => $receipt->year,
                'bankName' => $receipt->bank ?? '',
                'receivedBy' => strtoupper($receipt->establishment_id ?? 'ADMINISTRATION'),
                'chequeDate' => $receipt->mode === 'cheque' 
                    ? date('d-m-Y', strtotime($receipt->cheque_date)) 
                    : date('d-m-Y', strtotime($receipt->transaction_date)),
            ];

            // Render blade view to HTML
            $html = view('receipt', $data)->render();

            // Initialize mPDF
            // $mpdf = new Mpdf([
            //     'mode' => 'utf-8',
            //     'format' => 'A4',
            //     'orientation' => 'P',
            //     'margin_left' => 0,
            //     'margin_right' => 0,
            //     'margin_top' => 0,
            //     'margin_bottom' => 0,
            // ]);
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => [210, 148],  // A5 Landscape (width, height)
    'margin_left' => 6.5,    // ~25px
    'margin_right' => 6.5,   // ~25px
    'margin_top' => 5,       // ~20px
    'margin_bottom' => 5,    // ~20px
]);



            // Write HTML to PDF
            $mpdf->WriteHTML($html);

            // Create filename
            $filename = 'receipt_' . $receipt->receipt_no . '_' . time() . '.pdf';
            
            // Ensure directory exists
            $directory = 'uploads/receipt/print';
            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory, 0755, true);
            }

            // Save PDF to storage
            $path = $directory . '/' . $filename;
            $pdfOutput = $mpdf->Output('', 'S'); // Get PDF as string
            Storage::disk('public')->put($path, $pdfOutput);

            // Generate public URL
            $fullUrl = asset('storage/' . $path);

            // Return success response with PDF URL
            return $this->success('Receipt generated successfully', [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_no,
                'pdf_url' => $fullUrl,
                'pdf_path' => $path,
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Receipt generation error');
        }
    }

    /**
     * Convert number to words (Indian format)
     * 
     * @param float $number
     * @return string
     */
    private function convertNumberToWords($number)
    {
        $amount = number_format($number, 2, '.', '');
        list($rupees, $paise) = explode('.', $amount);
        
        $words = $this->numberToWords((int)$rupees);
        
        if ((int)$paise > 0) {
            $paiseWords = $this->numberToWords((int)$paise);
            return "Rupees " . ucwords($words) . " and " . ucwords($paiseWords) . " Paise Only";
        }
        
        return "Rupees " . ucwords($words) . " Only";
    }

    /**
     * Helper function to convert number to words
     * 
     * @param int $number
     * @return string
     */
    private function numberToWords($number)
    {
        $ones = array(
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
            5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
            14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
            18 => 'Eighteen', 19 => 'Nineteen'
        );

        $tens = array(
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
        );

        if ($number < 20) {
            return $ones[$number];
        }

        if ($number < 100) {
            return $tens[intval($number / 10)] . ' ' . $ones[$number % 10];
        }

        if ($number < 1000) {
            return $ones[intval($number / 100)] . ' Hundred ' . $this->numberToWords($number % 100);
        }

        if ($number < 100000) {
            return $this->numberToWords(intval($number / 1000)) . ' Thousand ' . $this->numberToWords($number % 1000);
        }

        if ($number < 10000000) {
            return $this->numberToWords(intval($number / 100000)) . ' Lakh ' . $this->numberToWords($number % 100000);
        }

        return $this->numberToWords(intval($number / 10000000)) . ' Crore ' . $this->numberToWords($number % 10000000);
    }

    /* ---------------- Helpers ---------------- */

    private function mapReceipt(ReceiptModel $r): array
    {
        $type = $r->family_id ? 'family' : 'establishment';

        return [
            'id'         => (string) $r->id,
            'receipt_no' => (string) $r->receipt_no,
            'date'       => (string) optional($r->date)->format('Y-m-d'),
            'status'     => (string) $r->status,
            'year'       => (string) $r->year,

            'name' => (string) ($r->name ?? ''),
            'its'  => (string) ($r->its ?? ''),

            'type'             => $type,
            'family_id'        => $r->family_id ? (string)$r->family_id : '',
            'establishment_id' => $r->establishment_id ? (string)$r->establishment_id : '',

            'mode' => (string) $r->mode,

            'trans_id'   => (string) ($r->transaction_no ?? ''),
            'trans_date' => $r->transaction_date ? (string) $r->transaction_date->format('Y-m-d') : '',

            'bank'       => (string) ($r->bank ?? ''),
            'cheque_no'  => (string) ($r->cheque_no ?? ''),
            'cheque_date'=> $r->cheque_date ? (string) $r->cheque_date->format('Y-m-d') : '',
            'ifsc'       => (string) ($r->ifsc ?? ''),

            'amount' => (string) $r->amount,
        ];
    }

    /* ==================== Helper Methods ==================== */

    /**
     * Calculate total due across all years (including advance_paid)
     */
    private function calculateTotalDue(string $type, ?int $familyId, ?int $establishmentId): float
    {
        $totalDue = 0;

        if ($type === 'family') {
            // Get total sabeel
            $totalSabeel = MumineenSabeelModel::where('family_id', $familyId)->sum('sabeel');
            
            // Get total receipts
            $totalReceipts = ReceiptModel::where('family_id', $familyId)
                ->where('status', 'active')
                ->sum('amount');

            // Get total advance_paid (pending only)
            $totalAdvancePaid = AdvancePaidModel::where('family_id', $familyId)
                ->where('type', 'family')
                ->where('status', 'pending')
                ->sum('amount');

            $totalDue = max(0, (float) $totalSabeel - (float) $totalReceipts - (float) $totalAdvancePaid);
        } else {
            // Get total sabeel
            $totalSabeel = EstablishmentSabeelModel::where('establishment_id', $establishmentId)->sum('sabeel');
            
            // Get total receipts
            $totalReceipts = ReceiptModel::where('establishment_id', $establishmentId)
                ->where('status', 'active')
                ->sum('amount');

            // Get total advance_paid (pending only)
            $totalAdvancePaid = AdvancePaidModel::where('establishment_id', $establishmentId)
                ->where('type', 'establishment')
                ->where('status', 'pending')
                ->sum('amount');

            $totalDue = max(0, (float) $totalSabeel - (float) $totalReceipts - (float) $totalAdvancePaid);
        }

        return $totalDue;
    }

    /**
     * Get dues for each year (oldest first) - advance_paid is not year-specific, so we exclude it here
     */
    private function getYearWiseDues(string $type, ?int $familyId, ?int $establishmentId): array
    {
        $yearDues = [];

        if ($type === 'family') {
            $sabeelEntries = MumineenSabeelModel::where('family_id', $familyId)
                ->orderBy('year', 'asc')
                ->get();
            
            $receipts = ReceiptModel::where('family_id', $familyId)
                ->where('status', 'active')
                ->select('year', DB::raw('SUM(amount) as paid'))
                ->groupBy('year')
                ->get()
                ->keyBy('year');

            foreach ($sabeelEntries as $entry) {
                $year = $entry->year;
                $sabeel = (float) $entry->sabeel;
                $paid = (float) ($receipts->get($year)->paid ?? 0);
                $due = max(0, $sabeel - $paid);
                if ($due > 0) {
                    $yearDues[] = ['year' => $year, 'due' => $due];
                }
            }
        } else {
            $sabeelEntries = EstablishmentSabeelModel::where('establishment_id', $establishmentId)
                ->orderBy('year', 'asc')
                ->get();
            
            $receipts = ReceiptModel::where('establishment_id', $establishmentId)
                ->where('status', 'active')
                ->select('year', DB::raw('SUM(amount) as paid'))
                ->groupBy('year')
                ->get()
                ->keyBy('year');

            foreach ($sabeelEntries as $entry) {
                $year = $entry->year;
                $sabeel = (float) $entry->sabeel;
                $paid = (float) ($receipts->get($year)->paid ?? 0);
                $due = max(0, $sabeel - $paid);
                if ($due > 0) {
                    $yearDues[] = ['year' => $year, 'due' => $due];
                }
            }
        }

        return $yearDues;
    }

    /**
     * Split cash amount into chunks of 9,000-10,000 (multiples of 100)
     */
    private function splitCashAmount(float $amount): array
    {
        $chunks = [];
        $remaining = $amount;

        while ($remaining > 0) {
            if ($remaining <= 10000) {
                // Last chunk - can be any amount <= 10000
                $chunks[] = $remaining;
                break;
            } else {
                // Chunk of 9,000-10,000 (preferably 10,000, but must be multiples of 100)
                // Round down to nearest 100, but ensure at least 9000
                $chunk = floor($remaining / 100) * 100;
                if ($chunk > 10000) {
                    $chunk = 10000;
                } elseif ($chunk < 9000) {
                    $chunk = 10000;
                }
                $chunks[] = $chunk;
                $remaining -= $chunk;
            }
        }

        return $chunks;
    }

    /**
     * Get family members (HOF + FMs) ordered by age DESC
     */
    private function getFamilyMembersForReceipts(int $familyId): array
    {
        $hof = MumineenModel::where('family_id', $familyId)
            ->where('hof_type', 'HOF')
            ->where('status', 'active')
            ->first();

        $fms = MumineenModel::where('family_id', $familyId)
            ->where('hof_type', 'FM')
            ->where('status', 'active')
            ->orderBy('age', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        $members = [];
        if ($hof) {
            $members[] = $hof;
        }
        foreach ($fms as $fm) {
            $members[] = $fm;
        }

        return $members;
    }

    /**
     * Get establishment partners
     */
    private function getEstablishmentPartners(int $establishmentId): array
    {
        $partners = MumineenEstablishmentModel::where('establishment_id', $establishmentId)
            ->get();

        $partnerFamilies = [];
        foreach ($partners as $partner) {
            $partnerFamilies[] = (int) $partner->family_id;
        }

        return array_unique($partnerFamilies);
    }

    /**
     * Check if receipt with same name exists on date
     */
    private function checkReceiptExistsForNameAndDate(string $name, string $date): bool
    {
        return ReceiptModel::where('name', $name)
            ->whereDate('date', $date)
            ->exists();
    }

    /**
     * Create a single receipt for a year
     */
    private function createReceiptForYear(string $type, ?int $familyId, ?int $establishmentId, string $year, float $amount, Request $request, string $date): ?array
    {
        $name = null;
        $its = null;

        if ($type === 'family') {
            $hof = MumineenModel::where('family_id', $familyId)
                ->where('hof_type', 'HOF')
                ->where('status', 'active')
                ->first();
            if (!$hof) return null;
            $name = $hof->name;
            $its = $hof->its;
        } else {
            $est = EstablishmentModel::where('establishment_id', $establishmentId)->first();
            if (!$est) return null;
            $name = $est->name;
            $its = null;
        }

        // Check if receipt already exists for this name and date
        if ($this->checkReceiptExistsForNameAndDate($name, $date)) {
            return null; // Skip this receipt
        }

        $receiptNo = $this->nextReceiptNo();

        $receipt = ReceiptModel::create([
            'family_id'        => $familyId,
            'establishment_id' => $establishmentId,
            'receipt_no'       => $receiptNo,
            'date'             => $date,
            'deposit_id'       => 1, // Default
            'name'             => $name,
            'its'              => $its,
            'mode'             => $request->mode,
            'transaction_no'   => $request->trans_id ?? null,
            'transaction_date' => $request->trans_date ?? null,
            'bank'             => $request->bank ?? null,
            'cheque_no'        => $request->cheque_no ?? null,
            'cheque_date'      => $request->cheque_date ?? null,
            'ifsc'             => $request->ifsc ?? null,
            'amount'           => $amount,
            'year'             => $year,
            'comment'          => $request->remarks ?? null,
            'status'           => 'active',
            'type'             => $type,
            'updated_by'       => (int) Auth::id(),
        ]);

        return $this->mapReceipt($receipt);
    }

    /**
     * Create cash split receipts (for amounts > 10,000)
     */
    private function createCashSplitReceipts(string $type, ?int $familyId, ?int $establishmentId, string $year, float $amount, Request $request, string $date): array
    {
        $receipts = [];
        $chunks = $this->splitCashAmount($amount);
        $chunkIndex = 0;

        if ($type === 'family') {
            $members = $this->getFamilyMembersForReceipts($familyId);
            foreach ($chunks as $chunk) {
                // Find available member (one without receipt on this date)
                $memberUsed = false;
                foreach ($members as $member) {
                    if (!$this->checkReceiptExistsForNameAndDate($member->name, $date)) {
                        $receipt = $this->createReceiptForFamilyMember($familyId, $establishmentId, $year, $chunk, $member, $request, $date);
                        if ($receipt) {
                            $receipts[] = $receipt;
                            $memberUsed = true;
                            break;
                        }
                    }
                }
                if (!$memberUsed) {
                    // No available member, skip this chunk (or save to advance_paid)
                    break;
                }
            }
        } else {
            // For establishments
            $partners = $this->getEstablishmentPartners($establishmentId);
            if (!empty($partners)) {
                // Use partners' families
                $allMembers = [];
                foreach ($partners as $partnerFamilyId) {
                    $partnerMembers = $this->getFamilyMembersForReceipts($partnerFamilyId);
                    foreach ($partnerMembers as $member) {
                        $allMembers[] = $member;
                    }
                }
                foreach ($chunks as $chunk) {
                    $memberUsed = false;
                    foreach ($allMembers as $member) {
                        if (!$this->checkReceiptExistsForNameAndDate($member->name, $date)) {
                            $receipt = $this->createReceiptForFamilyMember(null, $establishmentId, $year, $chunk, $member, $request, $date, (int) $member->family_id);
                            if ($receipt) {
                                $receipts[] = $receipt;
                                $memberUsed = true;
                                break;
                            }
                        }
                    }
                    if (!$memberUsed) break;
                }
            } else {
                // No partners - use establishment name (multiple receipts)
                $est = EstablishmentModel::where('establishment_id', $establishmentId)->first();
                if ($est) {
                    foreach ($chunks as $chunk) {
                        // Check if receipt exists for this name and date
                        if (!$this->checkReceiptExistsForNameAndDate($est->name, $date)) {
                            $receipt = $this->createReceiptForYear($type, $familyId, $establishmentId, $year, $chunk, $request, $date);
                            if ($receipt) {
                                $receipts[] = $receipt;
                            }
                        }
                    }
                }
            }
        }

        return $receipts;
    }

    /**
     * Create receipt for a family member (helper for cash splitting)
     */
    private function createReceiptForFamilyMember(?int $familyId, ?int $establishmentId, string $year, float $amount, $member, Request $request, string $date, ?int $memberFamilyId = null): ?array
    {
        $actualFamilyId = $memberFamilyId ?? $familyId;
        
        // Check if receipt already exists for this name and date
        if ($this->checkReceiptExistsForNameAndDate($member->name, $date)) {
            return null;
        }

        $receiptNo = $this->nextReceiptNo();

        $receipt = ReceiptModel::create([
            'family_id'        => $actualFamilyId,
            'establishment_id' => $establishmentId,
            'receipt_no'       => $receiptNo,
            'date'             => $date,
            'deposit_id'       => 1,
            'name'             => $member->name,
            'its'              => $member->its,
            'mode'             => $request->mode,
            'transaction_no'   => $request->trans_id ?? null,
            'transaction_date' => $request->trans_date ?? null,
            'bank'             => $request->bank ?? null,
            'cheque_no'        => $request->cheque_no ?? null,
            'cheque_date'      => $request->cheque_date ?? null,
            'ifsc'             => $request->ifsc ?? null,
            'amount'           => $amount,
            'year'             => $year,
            'comment'          => $request->remarks ?? null,
            'status'           => 'active',
            'type'             => $establishmentId ? 'establishment' : 'family',
            'updated_by'       => (int) Auth::id(),
        ]);

        return $this->mapReceipt($receipt);
    }

    /**
     * Save excess amount to advance_paid table
     */
    private function saveToAdvancePaid(string $type, ?int $familyId, ?int $establishmentId, float $amount, string $mode, string $date, ?string $remarks): ?array
    {
        $advancePaid = AdvancePaidModel::create([
            'type'            => $type,
            'family_id'       => $familyId,
            'establishment_id'=> $establishmentId,
            'amount'          => $amount,
            'mode'            => $mode,
            'date'            => $date,
            'remarks'         => $remarks,
            'status'          => 'pending',
            'user_id'         => Auth::id(),
        ]);

        return [
            'id' => (string) $advancePaid->id,
            'amount' => (string) $advancePaid->amount,
            'status' => $advancePaid->status,
            'date' => (string) $advancePaid->date,
        ];
    }

    /**
     * receipt_no using t_counter row with prefix="RCP"
     * Creates row if missing.
     * Format: {prefix}{number}{postfix}
     */
    private function nextReceiptNo(): string
    {
        // If you don't want counter table, replace this with your own logic.
        $counter = CounterModel::firstOrCreate(
            ['prefix' => 'RCP'],
            ['number' => 0, 'postfix' => '']
        );

        $counter->number = (int) $counter->number + 1;
        $counter->save();

        // Optional: pad to 6 digits
        $num = str_pad((string)$counter->number, 6, '0', STR_PAD_LEFT);

        return $counter->prefix . $num . $counter->postfix;
    }
}
