<?php

namespace App\Http\Controllers;
use App\Models\DepositsModel;
use App\Models\ReceiptModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DepositsController extends Controller
{
    //
    use ApiResponse;

    // Create a new deposit
    public function create(Request $request)
    {
        // Validate the incoming request
        $request->validate([
            'date'         => 'required|date',
            'receipt_ids'  => 'required|string', // Comma-separated string of receipt IDs
            'amount'       => 'required|numeric',
            'remarks'      => 'nullable|string',
        ]);

        // Get the receipt IDs as an array
        $receiptIds = explode(',', $request->input('receipt_ids'));

        // Validate each receipt ID
        $validReceipts = ReceiptModel::whereIn('id', $receiptIds)->get();

        if (count($validReceipts) !== count($receiptIds)) {
            // If the number of valid receipts doesn't match the number of provided IDs
            return $this->error('One or more receipt IDs are invalid.', 400);
        }

        // Generate a unique 10-digit deposit_id
        $depositId = $this->generateUniqueDepositId();

        // Start a database transaction to ensure both insertions happen atomically
        DB::beginTransaction();

        try {
            // Create the deposit record
            $deposit = DepositsModel::create([
                'deposit_id' => $depositId, // Store the 10-digit unique deposit ID
                'date'       => $request->input('date'),
                'receipt_ids'=> $request->input('receipt_ids'),
                'amount'     => $request->input('amount'),
                'created_by' => (int) Auth::id(),
                'remarks'    => $request->input('remarks'),
            ]);

            // Update the receipt records with the new deposit ID
            foreach ($validReceipts as $receipt) {
                $receipt->update([
                    'deposit_id' => $deposit->id,
                ]);
            }

            // Commit the transaction
            DB::commit();

            // Return success response with the newly created deposit
            return $this->success('Deposit created successfully and receipts updated.', $deposit);
        } catch (\Throwable $e) {
            // Rollback the transaction if anything goes wrong
            DB::rollBack();

            // Log the error and return server error response
            return $this->serverError($e);
        }
    }

    // Fetch Receipts based on receipt_ids, with pagination and optional receipt_no filter
    public function fetch(Request $request, $id = null)
    {
        try {
            // SINGLE: Fetch specific deposit by ID
            if ($id !== null) {
                // Fetch the deposit by ID
                $deposit = DepositsModel::find($id);
                if (!$deposit) return $this->error('Deposit not found.', 404);

                // Split the receipt_ids string into an array
                $receiptIds = explode(',', $deposit->receipt_ids);

                // Fetch the associated receipts using the receipt_ids
                $receipts = ReceiptModel::whereIn('id', $receiptIds)->get(['id', 'receipt_no']);

                // Prepare the user data
                $user = auth()->user();
                $createdBy = [
                    'id' => $user->id,
                    'name' => $user->name,
                ];

                // Return the deposit data with the receipt details nested inside the deposit object
                return $this->success('Deposit fetched successfully.', [
                    'deposit' => [
                        'id' => $deposit->id,
                        'deposit_id' => $deposit->deposit_id,
                        'date' => $deposit->date,
                        'receipt_details' => $receipts, // This will contain the loaded receipts
                        'amount' => $deposit->amount,
                        'remarks' => $deposit->remarks,
                        'created_by' => $createdBy
                    ]
                ], 200);
            }

            // LIST: Fetch list of deposits (with optional filters)
            $limit  = max(1, (int) $request->input('limit', 10)); // Default limit is 10
            $offset = max(0, (int) $request->input('offset', 0)); // Default offset is 0
            $receiptNo = trim((string) $request->input('receipt_no', '')); // Optional filter by receipt_no

            // Start the query to fetch deposits
            $query = DepositsModel::query()->orderBy('id', 'desc'); // Order by id by default

            // Apply receipt_no filter (if provided)
            if ($receiptNo !== '') {
                // We filter based on the `receipt_no` column in the `t_receipts` table
                $query->whereHas('receipts', function ($q) use ($receiptNo) {
                    $q->where('receipt_no', 'like', "%{$receiptNo}%");
                });
            }

            // Pagination
            $total = $query->count(); // Get the total count of deposits
            $deposits = $query->skip($offset)->take($limit)->get();

            // Format the deposits with receipt details
            $depositsWithReceipts = $deposits->map(function ($deposit) {
                // Split the receipt_ids string into an array
                $receiptIds = explode(',', $deposit->receipt_ids);

                // Fetch the associated receipts using the receipt_ids
                $receipts = ReceiptModel::whereIn('id', $receiptIds)->get(['id', 'receipt_no']);

                return [
                    'id' => $deposit->id,
                    'deposit_id' => $deposit->deposit_id,
                    'date' => $deposit->date,
                    'receipt_details' => $receipts, // This will contain the loaded receipts
                    'amount' => $deposit->amount,
                    'remarks' => $deposit->remarks,
                    'created_by' => [
                        'id' => $deposit->created_by,  // Assuming `created_by` stores the user's ID
                        'name' => $deposit->createdBy->name, // Fetch user's name using the `createdBy` relationship
                    ]
                ];
            });

            // Return success response with the data and pagination metadata
            return $this->success('Deposits fetched successfully.', $depositsWithReceipts, 200, [
                'pagination' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                    'count'  => count($depositsWithReceipts),
                    'total'  => $total,
                ]
            ]);
        } catch (\Throwable $e) {
            // Handle any errors and log them
            return $this->serverError($e, 'Deposit fetch failed');
        }
    }

    // Update Deposit and related Receipts
    public function edit(Request $request, $id)
    {
        // Validate incoming request
        $request->validate([
            'receipt_ids' => 'required|string',  // Comma-separated string of receipt IDs
            'amount'      => 'required|numeric',  // Amount
            'remarks'     => 'nullable|string',   // Remarks (optional)
        ]);

        // Get the receipt_ids from the request and convert to array
        $receiptIds = explode(',', $request->input('receipt_ids'));

        // Start a database transaction to ensure both the deposit and receipts are updated atomically
        DB::beginTransaction();

        try {
            // Step 1: Remove existing deposit_id from previously mapped receipts
            // Find all receipts that are mapped to the old deposit and reset their deposit_id
            $oldReceipts = ReceiptModel::where('deposit_id', $id)->get();
            foreach ($oldReceipts as $receipt) {
                $receipt->update([
                    'deposit_id' => null,  // Remove the old deposit_id
                ]);
            }

            // Step 2: Update/Create the new deposit record
            // Update the existing deposit record with the new details (date, amount, remarks)
            $deposit = DepositsModel::findOrFail($id);
            $deposit->update([
                'receipt_ids' => $request->input('receipt_ids'),
                'amount'      => $request->input('amount'),
                'remarks'     => $request->input('remarks'),
            ]);

            // Step 3: Update the receipts with the new deposit_id
            // Now, assign the new deposit_id to the valid receipts
            $validReceipts = ReceiptModel::whereIn('id', $receiptIds)->get();

            // Ensure all receipt_ids are valid before updating
            if (count($validReceipts) !== count($receiptIds)) {
                // If the number of valid receipts doesn't match the input, return an error
                return $this->error('One or more receipt IDs are invalid.', 400);
            }

            // Update the valid receipts with the new deposit_id
            foreach ($validReceipts as $receipt) {
                $receipt->update([
                    'deposit_id' => $deposit->id,
                ]);
            }

            // Step 4: Commit the transaction
            DB::commit();

            // Return success response with the updated deposit details
            return $this->success('Deposit updated successfully and receipts updated.', $deposit);

        } catch (\Throwable $e) {
            // Rollback the transaction if anything fails
            DB::rollBack();

            // Log the error and return server error response
            return $this->serverError($e);
        }
    }

    // Delete Deposit and unmap related receipts
    public function delete(Request $request, $id)
    {
        // Start a database transaction to ensure both the deposit and receipts are updated atomically
        DB::beginTransaction();

        try {
            // Step 1: Find the deposit record by id
            $deposit = DepositsModel::find($id);
            if (!$deposit) {
                // If the deposit is not found, return an error
                return $this->error('Deposit not found.', 404);
            }

            // Step 2: Remove the deposit_id from the receipts associated with this deposit
            $receipts = ReceiptModel::where('deposit_id', $id)->get();

            // If receipts are found, reset their deposit_id to null
            foreach ($receipts as $receipt) {
                $receipt->update([
                    'deposit_id' => null,  // Remove the deposit_id mapping
                ]);
            }

            // Step 3: Delete the deposit record
            $deposit->delete();

            // Step 4: Commit the transaction
            DB::commit();

            // Return success response
            return $this->success('Deposit deleted and associated receipts updated successfully.');
        } catch (\Throwable $e) {
            // If any error occurs, rollback the transaction
            DB::rollBack();

            // Log the error and return server error response
            return $this->serverError($e);
        }
    }

    public function generateDepositPdf($id)
    {
        try {
            $deposit = DepositsModel::find($id);
            if (!$deposit) return $this->error('Deposit not found.', 404);

            $receiptIds = array_values(array_filter(array_map('trim', explode(',', (string)$deposit->receipt_ids))));
            $receiptIds = array_map('intval', $receiptIds);

            if (count($receiptIds) === 0) {
                return $this->error('No receipt_ids found for this deposit.', 422);
            }

            // Fetch receipts (keep same order as receipt_ids)
            $receipts = ReceiptModel::whereIn('id', $receiptIds)
                ->get(['id','receipt_no','date','name','amount','mode'])
                ->keyBy('id');

            $rows = [];
            $total = 0;

            foreach ($receiptIds as $rid) {
                $r = $receipts->get($rid);
                if (!$r) continue;

                $amt = (float) $r->amount;
                $total += $amt;

                $rows[] = [
                    'receipt_no' => (string) $r->receipt_no,
                    'date'       => $r->date ? $r->date->format('d-m-Y') : '',
                    'name'       => (string) $r->name,
                    'amount'     => 'Rs. ' . number_format($amt, 2),
                    'mode'       => ucfirst((string) $r->mode),
                ];
            }

            if (count($rows) === 0) {
                return $this->error('Receipts not found for provided receipt_ids.', 422);
            }

            $depositDate = $deposit->date ? date('d-m-Y', strtotime($deposit->date)) : '';

            // Render HTML
            $html = view('deposit', [
                'deposit'      => $deposit,
                'deposit_date' => $depositDate,
                'rows'         => $rows,
                'total_amount' => 'Rs. ' . number_format($total, 2),
            ])->render();

            // mPDF (A4 Portrait) - if you want A5 landscape tell me
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'orientation' => 'P',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 10,
            ]);

            $mpdf->WriteHTML($html);

            // Save PDF
            $directory = 'uploads/deposit/print';
            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory, 0755, true);
            }

            $filename = 'deposit_' . $deposit->deposit_id . '_' . time() . '.pdf';
            $path = $directory . '/' . $filename;

            Storage::disk('public')->put($path, $mpdf->Output('', 'S'));

            $fullUrl = asset('storage/' . $path);

            return $this->success('Deposit PDF generated successfully.', [
                'deposit_id'   => $deposit->id,
                'deposit_no'   => $deposit->deposit_id,
                'pdf_url'      => $fullUrl,
                'pdf_path'     => $path,
                'receipt_count'=> count($rows),
                'total'        => 'Rs. ' . number_format($total, 2),
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Deposit PDF generation failed');
        }
    }

    // helper
    /**
     * Generate a unique 10-digit deposit_id.
     * This method generates a random 10-digit deposit_id and checks its uniqueness.
     *
     * @return string
     */
    private function generateUniqueDepositId()
    {
        do {
            // Generate a random 10-digit number
            $depositId = str_pad(rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (DepositsModel::where('deposit_id', $depositId)->exists()); // Ensure uniqueness

        return $depositId;
    }
}
