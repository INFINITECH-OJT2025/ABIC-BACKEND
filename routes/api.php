<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccountantController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankContactController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionInstrumentController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\SavedReceiptController;
use App\Http\Controllers\ActivityLogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip())->response(function () {
        return response()->json([
            'success' => false,
            'message' => 'Too many authentication attempts. Please try again later.',
            'errors' => null,
            'retry_after' => 60
        ], 429);
    });
});

RateLimiter::for('api', function (Request $request) {
    return $request->user()
        ? Limit::perMinute(60)->by($request->user()->id)
        : Limit::perMinute(20)->by($request->ip());
});

Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/login', [AuthController::class, 'loginInfo'])->name('login');
});

Route::get('/test-simple', [AuthController::class, 'testSimple']);
Route::get('/test-auth', [AuthController::class, 'testAuth'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::middleware(['role:employee'])->prefix('employees')->group(function () {
        Route::get('/me', [EmployeeController::class, 'me']);
        Route::put('/me', [EmployeeController::class, 'updateMe']);
    });
    

    Route::middleware(['role:super_admin'])->group(function () {
        Route::get('/admin-only', function () {
            return response()->json([
                'message' => 'Super Admin Access Granted'
            ]);
        });

        Route::prefix('admin/accounts')->group(function () {
            Route::get('/', [AdminController::class, 'index']);
            Route::post('/', [AdminController::class, 'store']);
            Route::post('/promote-from-employee', [AdminController::class, 'promoteFromEmployee']);
            Route::post('/{id}/revert-to-employee', [AdminController::class, 'revertToEmployee']);
            Route::get('/{id}', [AdminController::class, 'show']);
            Route::put('/{id}', [AdminController::class, 'update']);
            Route::delete('/{id}', [AdminController::class, 'destroy']);
        });

        Route::prefix('employees')->group(function () {
            Route::get('/', [EmployeeController::class, 'index']);
            Route::post('/', [EmployeeController::class, 'store']);
            Route::get('/{id}', [EmployeeController::class, 'show']);
            Route::put('/{id}', [EmployeeController::class, 'update']);
        });
    });
    
    Route::middleware(['role:super_admin'])->prefix('accountant')->group(function () {
        Route::get('/', [AccountantController::class, 'index']);
        Route::post('/', [AccountantController::class, 'store']);
        Route::post('/promote-from-employee', [AccountantController::class, 'promoteFromEmployee']);
        
        // Activity logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        // Saved receipts routes - must come before /{id} route to avoid route conflicts
        Route::prefix('saved-receipts')->group(function () {
            Route::get('/', [SavedReceiptController::class, 'index']);
            Route::post('/', [SavedReceiptController::class, 'store']);
            Route::get('/{id}', [SavedReceiptController::class, 'show']);
            Route::get('/{id}/file', [SavedReceiptController::class, 'getFile']);
            Route::delete('/{id}', [SavedReceiptController::class, 'destroy']);
        });
        
        Route::get('/{id}', [AccountantController::class, 'show']);
        Route::put('/{id}', [AccountantController::class, 'update']);
        Route::delete('/{id}', [AccountantController::class, 'destroy']);
        Route::post('/{id}/revert-to-employee', [AccountantController::class, 'revertToEmployee']);
        Route::post('/{id}/resend-credentials', [AccountantController::class, 'resendCredentials']);
        Route::post('/{id}/suspend', [AccountantController::class, 'suspend']);
        Route::post('/{id}/unsuspend', [AccountantController::class, 'unsuspend']);

        Route::prefix('maintenance/banks')->group(function () {
            Route::get('/', [BankController::class, 'index']);
            Route::post('/', [BankController::class, 'store']);
            Route::get('/{id}', [BankController::class, 'show']);
            Route::put('/{id}', [BankController::class, 'update']);
            Route::delete('/{id}', [BankController::class, 'destroy']);

            Route::prefix('{bankId}/contacts')->group(function () {
                Route::get('/', [BankContactController::class, 'index']);
                Route::post('/', [BankContactController::class, 'store']);
            });
        });

        Route::prefix('maintenance/bank-contacts')->group(function () {
            Route::get('/', [BankContactController::class, 'index']);
            Route::post('/', [BankContactController::class, 'store']);
            Route::get('/{id}', [BankContactController::class, 'show']);
            Route::put('/{id}', [BankContactController::class, 'update']);
            Route::delete('/{id}', [BankContactController::class, 'destroy']);
        });

        Route::prefix('maintenance/owners')->group(function () {
            Route::get('/', [OwnerController::class, 'index']);
            Route::post('/create-owner', [OwnerController::class, 'createOwner']);
            Route::get('/{id}', [OwnerController::class, 'show']);
            Route::put('/{id}', [OwnerController::class, 'update']);
            Route::post('/{id}/inactive', [OwnerController::class, 'inactive']);
            Route::post('/{id}/restore', [OwnerController::class, 'restore']);
        });

        Route::prefix('maintenance/properties')->group(function () {
            Route::get('/', [PropertyController::class, 'index']);
            Route::post('/create-property', [PropertyController::class, 'createProperty']);
            Route::get('/{id}', [PropertyController::class, 'show']);
            Route::put('/{id}', [PropertyController::class, 'update']);
        });

        Route::prefix('maintenance/units')->group(function () {
            Route::get('/', [UnitController::class, 'index']);
            Route::post('/', [UnitController::class, 'store']);
            Route::get('/{id}', [UnitController::class, 'show']);
            Route::put('/{id}', [UnitController::class, 'update']);
            Route::delete('/{id}', [UnitController::class, 'destroy']);
        });

        Route::prefix('maintenance/bank-accounts')->group(function () {
            Route::get('/', [BankAccountController::class, 'index']);
            Route::post('/', [BankAccountController::class, 'store']);
            Route::get('/{id}', [BankAccountController::class, 'show']);
            Route::put('/{id}', [BankAccountController::class, 'update']);
            Route::post('/{id}/inactive', [BankAccountController::class, 'inactive']);
            Route::post('/{id}/restore', [BankAccountController::class, 'restore']);
        });

        Route::prefix('transactions')->group(function () {
            Route::post('/deposit', [TransactionController::class, 'storeDeposit']);
            Route::post('/withdrawal', [TransactionController::class, 'storeWithdrawal']);
            Route::post('/check-duplicate-files', [TransactionController::class, 'checkDuplicateFileNames']);
            Route::prefix('{transactionId}/instruments')->group(function () {
                Route::get('/', [TransactionInstrumentController::class, 'index']);
                Route::post('/', [TransactionInstrumentController::class, 'store']);
                Route::delete('/{id}', [TransactionInstrumentController::class, 'destroy']);
            });
            Route::prefix('{transactionId}/attachments')->group(function () {
                Route::get('/{attachmentId}', [TransactionController::class, 'getAttachment']);
            });
        });

        Route::prefix('ledger')->group(function () {
            Route::get('/mains', [LedgerController::class, 'mains']);
            Route::get('/clients', [LedgerController::class, 'clients']);
            Route::get('/company', [LedgerController::class, 'company']);
            Route::get('/system', [LedgerController::class, 'system']);
        });
    });
});


