<?php

namespace App\Http\Controllers;
use App\Models\DepositsModel;
use App\Models\ReceiptModel;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
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

        // Start a database transaction to ensure both insertions happen atomically
        DB::beginTransaction();

        try {
            // Create the deposit record
            $deposit = DepositsModel::create([
                'date'       => $request->input('date'),
                'receipt_ids'=> $request->input('receipt_ids'),
                'amount'     => $request->input('amount'),
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

                // Return the deposit data
                return $this->success('Deposit fetched successfully.', $deposit, 200);
            }

            // LIST: Fetch list of deposits (with optional filters)
            $limit  = max(1, (int) $request->input('limit', 10)); // Default limit is 10
            $offset = max(0, (int) $request->input('offset', 0)); // Default offset is 0
            $receiptNo = trim((string) $request->input('receipt_no', '')); // Optional filter by receipt_no

            // Start the query to fetch deposits
            $query = DepositsModel::query()->orderBy('id', 'desc'); // Order by id by default

            // Apply receipt_no filter (if provided)
            if ($receiptNo !== '') {
                $query->where('receipt_ids', 'like', "%{$receiptNo}%");
            }

            // Pagination
            $total = $query->count(); // Get the total count of deposits
            $deposits = $query->skip($offset)->take($limit)->get();

            // Return success response with the data and pagination metadata
            return $this->success('Deposits fetched successfully.', $deposits, 200, [
                'pagination' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                    'count'  => count($deposits),
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
}
