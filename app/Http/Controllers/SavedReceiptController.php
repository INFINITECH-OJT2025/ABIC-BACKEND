<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SavedReceipt;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\FirebaseStorageService;

class SavedReceiptController extends Controller
{
    /**
     * Check if user has permission to access receipts.
     */
    protected function checkAuthorization(Request $request): void
    {
        $user = $request->user();
        
        if (!$user) {
            abort(401, 'Unauthorized');
        }

        $userRole = strtolower($user->role ?? '');
        $allowedRoles = ['accountant', 'super_admin', 'admin'];
        
        if (!in_array($userRole, $allowedRoles)) {
            abort(403, 'Insufficient permissions.');
        }
    }

    /**
     * Check if a file path is a Firebase Storage URL.
     */
    protected function isFirebaseUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * Get file URL for a receipt (Firebase URL or API proxy).
     */
    protected function getFileUrl(SavedReceipt $receipt): string
    {
        return $this->isFirebaseUrl($receipt->file_path) 
            ? $receipt->file_path 
            : "/api/accountant/saved-receipts/{$receipt->id}/file";
    }

    /**
     * Generate display name for receipt: toOwnerName + trans_method + date
     */
    protected function generateDisplayName(SavedReceipt $receipt): string
    {
        $parts = [];
        
        // Get toOwnerName from transaction relationship
        if ($receipt->transaction && $receipt->transaction->toOwner) {
            $parts[] = $receipt->transaction->toOwner->name;
        } elseif ($receipt->receipt_data && isset($receipt->receipt_data['toOwnerName'])) {
            // Fallback to receipt_data if relationship not loaded
            $parts[] = $receipt->receipt_data['toOwnerName'];
        } else {
            $parts[] = "Unknown Owner";
        }
        
        // Get trans_method from transaction
        if ($receipt->transaction && $receipt->transaction->trans_method) {
            $parts[] = strtoupper($receipt->transaction->trans_method);
        } elseif ($receipt->receipt_data && isset($receipt->receipt_data['transaction_type'])) {
            // Fallback: use transaction_type from receipt_data
            $parts[] = strtoupper($receipt->receipt_data['transaction_type']);
        }
        
        // Get date (prefer transaction created_at, then receipt created_at)
        $date = null;
        if ($receipt->transaction && $receipt->transaction->created_at) {
            $date = $receipt->transaction->created_at->format('M d, Y');
        } elseif ($receipt->created_at) {
            $date = $receipt->created_at->format('M d, Y');
        } else {
            $date = date('M d, Y');
        }
        $parts[] = $date;
        
        return implode(' • ', $parts);
    }

    /**
     * Save a receipt image.
     */
    public function store(Request $request)
    {
        $this->checkAuthorization($request);

        try {
            $validated = $request->validate([
                'transaction_id' => ['nullable', 'integer', 'exists:transactions,id'],
                'transaction_type' => ['required', 'string', 'in:DEPOSIT,WITHDRAWAL'],
                'receipt_image' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf,application/pdf', 'max:10240'], // 10MB max - accepts images and PDFs
                'receipt_data' => ['nullable', 'string'], // JSON string of transaction data
            ]);

            $receiptData = null;
            if ($request->filled('receipt_data')) {
                $receiptData = json_decode($request->receipt_data, true);
            }

            $file = $request->file('receipt_image');
            $basePath = 'receipts/' . date('Y/m');
            $uniqueFileName = 'receipt_' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            $firebasePath = $basePath . '/' . $uniqueFileName;
            
            // Upload to Firebase Storage
            $firebaseStorage = new FirebaseStorageService();
            $firebaseUrl = $firebaseStorage->uploadFile($file, $firebasePath);

            $savedReceipt = SavedReceipt::create([
                'transaction_id' => $validated['transaction_id'] ?? null,
                'transaction_type' => $validated['transaction_type'],
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $firebaseUrl,
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'receipt_data' => $receiptData,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Receipt saved successfully',
                'data' => $savedReceipt
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to save receipt', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to save receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all saved receipts with pagination and filtering.
     */
    public function index(Request $request)
    {
        $this->checkAuthorization($request);

        try {
            $query = SavedReceipt::with([
                'transaction' => function ($q) {
                    $q->with(['toOwner', 'fromOwner']);
                }
            ])->orderBy('created_at', 'desc');

            // Filter by transaction type if provided
            if ($request->has('transaction_type') && $request->transaction_type !== 'ALL') {
                $query->where('transaction_type', $request->transaction_type);
            }

            // Filter by owner_id if provided (filter by to_owner_id in transaction)
            if ($request->has('owner_id') && $request->owner_id) {
                $query->whereHas('transaction', function ($q) use ($request) {
                    $q->where('to_owner_id', $request->owner_id);
                });
            }

            // Filter by owner name (search in toOwner or fromOwner)
            if ($request->has('owner_name') && $request->owner_name) {
                $ownerName = $request->owner_name;
                $query->whereHas('transaction', function ($q) use ($ownerName) {
                    $q->whereHas('toOwner', function ($ownerQ) use ($ownerName) {
                        $ownerQ->where('name', 'LIKE', "%{$ownerName}%");
                    })->orWhereHas('fromOwner', function ($ownerQ) use ($ownerName) {
                        $ownerQ->where('name', 'LIKE', "%{$ownerName}%");
                    });
                });
            }

            // Filter by voucher number
            if ($request->has('voucher_no') && $request->voucher_no) {
                $voucherNo = $request->voucher_no;
                $query->whereHas('transaction', function ($q) use ($voucherNo) {
                    $q->where('voucher_no', 'LIKE', "%{$voucherNo}%");
                });
            }

            // Filter by date range
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Pagination
            $perPage = min((int)($request->get('per_page', 15)), 100); // Max 100 per page
            $page = max(1, (int)($request->get('page', 1)));
            
            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            // Add file URL and formatted display name for each receipt
            $paginated->getCollection()->transform(function ($receipt) {
                $receipt->file_url = $this->getFileUrl($receipt);
                
                // Generate display name: toOwnerName + trans_method + date
                $displayName = $this->generateDisplayName($receipt);
                $receipt->display_name = $displayName;
                
                return $receipt;
            });

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'from' => $paginated->firstItem(),
                    'to' => $paginated->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch receipts', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch receipts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single saved receipt.
     */
    public function show($id)
    {
        try {
            $receipt = SavedReceipt::with('transaction')->findOrFail($id);
            $receipt->file_url = $this->getFileUrl($receipt);

            return response()->json([
                'success' => true,
                'data' => $receipt
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found'
            ], 404);
        }
    }

    /**
     * Get a saved receipt file.
     */
    public function getFile(Request $request, $id)
    {
        $this->checkAuthorization($request);

        try {
            $receipt = SavedReceipt::findOrFail($id);

            // If Firebase URL, redirect to it
            if ($this->isFirebaseUrl($receipt->file_path)) {
                return redirect($receipt->file_path);
            }

            // Legacy local storage fallback
            if (!Storage::disk('local')->exists($receipt->file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            $file = Storage::disk('local')->get($receipt->file_path);
            $mimeType = Storage::disk('local')->mimeType($receipt->file_path) ?? 'image/png';

            return response($file, 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline; filename="' . $receipt->file_name . '"');

        } catch (\Exception $e) {
            Log::error('Failed to get receipt file', [
                'receipt_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found'
            ], 404);
        }
    }

    /**
     * Delete a saved receipt.
     */
    public function destroy($id)
    {
        $this->checkAuthorization(request());

        try {
            $receipt = SavedReceipt::findOrFail($id);

            // Delete file from storage (Firebase or local)
            if ($this->isFirebaseUrl($receipt->file_path)) {
                try {
                    $firebaseStorage = new FirebaseStorageService();
                    $firebaseStorage->deleteFile($receipt->file_path);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete Firebase file', [
                        'receipt_id' => $id,
                        'file_path' => $receipt->file_path,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with record deletion even if file deletion fails
                }
            } else {
                // Local storage fallback
                if (Storage::exists($receipt->file_path)) {
                    Storage::delete($receipt->file_path);
                }
            }

            // Delete record
            $receipt->delete();

            return response()->json([
                'success' => true,
                'message' => 'Receipt deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete receipt', [
                'receipt_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete receipt: ' . $e->getMessage()
            ], 500);
        }
    }
}
