<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoucherController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'errors'  => null
                ], 401);
            }

            return Voucher::latest()->get();

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data'    => $e->getMessage()
            ], 500);
        }
    }

    public function chequevoucher(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'errors'  => null
                ], 401);
            }

            $voucher = Voucher::where('voucher_no', 'like', 'CHQ-%')
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Voucher retrieved successfully',
                'data'    => $voucher
            ], 201);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data'    => $e->getMessage()
            ], 401);
        }
    }

    public function cashvoucher(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'errors'  => null
                ], 401);
            }

            $voucher = Voucher::where('voucher_no', 'like', 'CSH-%')
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Voucher retrieved successfully',
                'data'    => $voucher
            ], 201);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data'    => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Store Cash Voucher
     */
    public function storeCash(Request $request)
    {
        return $this->createVoucher($request, 'CHS');
    }

    /**
     * Store Cheque Voucher
     */
    public function storeCheque(Request $request)
    {
        return $this->createVoucher($request, 'CHQ');
    }

    private function createVoucher(Request $request, $prefix)
    {
        try {

            $voucher = DB::transaction(function () use ($request, $prefix) {

                $year = now()->format('y');

                $validated = $request->validate([
                    'voucher_no' => [
                        'required',
                        'regex:/^' . $prefix . '-' . $year . '-\d{6}$/'
                    ],

                    'paid_to'                    => ['nullable', 'string'],
                    'date'                       => ['nullable', 'date'],
                    'project_details'            => ['nullable', 'string'],
                    'owner_client'               => ['nullable', 'string'],
                    'purpose'                    => ['nullable', 'string'],
                    'note'                       => ['nullable', 'string'],
                    'total_amount'               => ['required', 'numeric'],
                    'received_by_name'           => ['nullable', 'string'],
                    'received_by_signature_url'  => ['nullable', 'string'],
                    'received_by_date'           => ['nullable', 'date'],
                    'approved_by_name'           => ['nullable', 'string'],
                    'approved_by_signature_url'  => ['nullable', 'string'],
                    'approved_by_date'           => ['nullable', 'date'],
                ]);

                $validated['status'] = 'approved';

                return Voucher::create($validated);
            });

            return response()->json($voucher, 201);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Failed to create voucher',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function prepareCheque()
    {
        return $this->prepareVoucher('CHQ');
    }

    public function prepareCash()
    {
        return $this->prepareVoucher('CHS');
    }

    private function prepareVoucher($prefix)
    {
        $year = now()->format('y');

        $latest = Voucher::where('voucher_no', 'like', $prefix . '-%')
            ->orderByDesc('id')
            ->first();

        if ($latest) {
            $lastNumber = (int) substr($latest->voucher_no, -6);
            $nextNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = '000001';
        }

        $voucherNo = "{$prefix}-{$year}-{$nextNumber}";

        $latestNames = Voucher::latest()->first();

        return response()->json([
            'voucher_no'        => $voucherNo,
            'received_by_name'  => $latestNames?->received_by_name,
            'approved_by_name'  => $latestNames?->approved_by_name,
        ]);
    }

    public function update(Request $request, $id)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'errors'  => null
                ], 401);
            }

            $voucher = Voucher::find($id);

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher not found',
                    'errors'  => null
                ], 404);
            }

            if ($voucher->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update cancelled voucher',
                    'data'    => null
                ], 403);
            }

            $validated = $request->validate([
                'paid_to'                    => ['nullable', 'string'],
                'date'                       => ['nullable', 'date'],
                'project_details'            => ['nullable', 'string'],
                'owner_client'               => ['nullable', 'string'],
                'purpose'                    => ['nullable', 'string'],
                'note'                       => ['nullable', 'string'],
                'total_amount'               => ['required', 'numeric'],
                'received_by_name'           => ['nullable', 'string'],
                'received_by_signature_url'  => ['nullable', 'string'],
                'received_by_date'           => ['nullable', 'date'],
                'approved_by_name'           => ['nullable', 'string'],
                'approved_by_signature_url'  => ['nullable', 'string'],
                'approved_by_date'           => ['nullable', 'date'],
            ]);

            $voucher = DB::transaction(function () use ($voucher, $validated) {
                $voucher->update($validated);
                return $voucher;
            });

            return response()->json($voucher, 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data'    => $e->getMessage()
            ], 500);
        }
    }

    public function cancelVoucher(string $id)
    {
        try {

            $voucher = Voucher::find($id);

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher not found',
                    'errors'  => null
                ], 404);
            }

            $voucher = DB::transaction(function () use ($voucher) {
                $voucher->status = 'cancelled';
                $voucher->save();
                return $voucher;
            });

            return response()->json([
                'success' => true,
                'message' => 'Voucher cancelled successfully',
                'data'    => $voucher
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data'    => $e->getMessage()
            ], 500);
        }
    }

    public function approvedVoucher(Request $request, string $id)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'errors'  => null
                ], 401);
            }

            $voucher = Voucher::find($id);

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher not found',
                    'errors'  => null
                ], 404);
            }

            $voucher = DB::transaction(function () use ($voucher) {
                $voucher->status = 'approved';
                $voucher->save();
                return $voucher;
            });

            return response()->json([
                'success' => true,
                'message' => 'Voucher approved successfully',
                'data'    => $voucher
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'data'    => $e->getMessage()
            ], 500);
        }
    }
}