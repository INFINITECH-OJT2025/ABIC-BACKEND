<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\TransactionInstrument;
use Illuminate\Validation\ValidationException;
use Exception;

class TransactionInstrumentController extends Controller
{
    /**
     * List instruments per transaction.
     */
    public function index($transactionId)
    {
        $transaction = Transaction::find($transactionId);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
                'data' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Instruments retrieved successfully',
            'data' => $transaction->instruments
        ]);
    }

    /**
     * Create instrument.
     */
    public function store(Request $request, $transactionId)
    {
        try {
            $transaction = Transaction::findOrFail($transactionId);

            if ($transaction->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'transaction' => ['Cannot modify inactive transaction']
                ]);
            }

            $validated = $request->validate([
                'instrument_type' => ['required', 'in:CHEQUE,DEPOSIT SLIP'],
                'instrument_no'   => ['nullable', 'string', 'max:255'],
                'notes'           => ['nullable', 'string'],
            ]);

            $instrument = $transaction->instruments()->create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Instrument added successfully',
                'data' => $instrument
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create instrument'
            ], 500);
        }
    }

    /**
     * Delete instrument.
     */
    public function destroy($transactionId, $id)
    {
        $instrument = TransactionInstrument::where('transaction_id', $transactionId)->find($id);

        if (!$instrument) {
            return response()->json([
                'success' => false,
                'message' => 'Instrument not found'
            ], 404);
        }

        if ($instrument->transaction->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete instrument of inactive transaction'
            ], 403);
        }

        $instrument->delete();

        return response()->json([
            'success' => true,
            'message' => 'Instrument deleted successfully'
        ]);
    }
}
