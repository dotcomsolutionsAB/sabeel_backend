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
use Mpdf\Mpdf;

class ReceiptController extends Controller
{
    //
    use ApiResponse;

    /**
     * CREATE
     * POST /receipts/create
     * Body:
     * {
     *   "type":"family|establishment",
     *   "family_id": "",
     *   "establishment_id": "",
     *   "year": 2025,
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
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:family,establishment',

                'family_id'        => 'required_if:type,family|nullable|integer',
                'establishment_id' => 'required_if:type,establishment|nullable|integer',

                'year'   => 'required|integer|min:2000|max:2100',
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
                return $this->validation($validator);
            }

            // Validate family / establishment exists and derive name/its
            $type = $request->type;

            $familyId = null;
            $estId = null;
            $name = null;
            $its = null;

            if ($type === 'family') {
                $familyId = (int) $request->family_id;

                $hof = MumineenModel::where('family_id', $familyId)
                    ->where('hof_type', 'HOF')
                    ->first();

                if (!$hof) {
                    return $this->error('Invalid family_id. Family not found.', 404);
                }

                $name = $hof->name;
                $its  = $hof->its;
            } else {
                $estId = (int) $request->establishment_id;

                $est = EstablishmentModel::where('establishment_id', $estId)->first();
                if (!$est) {
                    return $this->error('Invalid establishment_id. Establishment not found.', 404);
                }

                $name = $est->name;
                $its  = null;
            }

            // Optional: validate year exists in t_year
            if (Schema::hasTable('t_year')) {
                $existsYear = YearModel::where('year', (int)$request->year)->exists();
                if (!$existsYear) {
                    return $this->error('Invalid year. Year not found in master.', 422);
                }
            }

            // Generate receipt_no
            $receiptNo = $this->nextReceiptNo(); // uses t_counter

            $row = ReceiptModel::create([
                'family_id'        => $familyId,
                'establishment_id' => $estId,

                'receipt_no' => $receiptNo,
                'date'       => now()->toDateString(),

                'name' => $name,
                'its'  => $its,

                'mode' => $request->mode,

                'transaction_no'   => $request->trans_id ?? null,
                'transaction_date' => $request->trans_date ?? null,

                'bank'       => $request->bank ?? null,
                'cheque_no'  => $request->cheque_no ?? null,
                'cheque_date'=> $request->cheque_date ?? null,
                'ifsc'       => $request->ifsc ?? null,

                'amount' => $request->amount,
                'year'   => (int) $request->year,

                'comment' => $request->remarks ?? null,
                'status'  => 'active',

                'updated_by' => (int) Auth::id(),
            ]);

            return $this->success('Data saved successfully', $row, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Receipt create failed');
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

            $type = $request->type;

            $q = ReceiptModel::query()
                ->where('status', 'active')
                ->orderBy('id', 'desc');

            // Adjust the query based on the `type`
            if ($type === 'family') {
                $q->whereNotNull('family_id');
            } elseif ($type === 'establishment') {
                $q->whereNotNull('establishment_id');
            } else {
                // If `type` is null, include both family and establishment
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
            $mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => [148, 210], // ✅ A5 exact size
    'margin_left' => 0,
    'margin_right' => 0,
    'margin_top' => 0,
    'margin_bottom' => 0,
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
