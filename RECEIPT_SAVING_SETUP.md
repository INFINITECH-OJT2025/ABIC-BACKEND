# Receipt Saving Setup - Complete Guide

## Overview
The receipt saving functionality is fully implemented and saves transaction receipts to Firebase Storage, similar to how attachments and vouchers are saved.

## Database Table

**Table Name:** `saved_receipts`

**Migration:** `2026_02_21_000001_create_saved_receipts_table.php`

**Structure:**
- `id` - Primary key
- `transaction_id` - Foreign key to transactions table (nullable)
- `transaction_type` - DEPOSIT or WITHDRAWAL
- `file_name` - Original file name
- `file_path` - Firebase Storage URL (TEXT type for long URLs)
- `file_type` - MIME type (default: image/png)
- `file_size` - File size in bytes
- `receipt_data` - JSON data of transaction details
- `created_at` / `updated_at` - Timestamps

## Backend Implementation

### Controller: `SavedReceiptController.php`

**Location:** `app/Http/Controllers/SavedReceiptController.php`

**Key Methods:**
- `store()` - Saves receipt image to Firebase Storage and creates database record
- `index()` - Lists all saved receipts
- `show($id)` - Gets a single receipt
- `getFile($id)` - Retrieves receipt file (redirects to Firebase URL)
- `destroy($id)` - Deletes receipt from Firebase and database

**Firebase Storage Path:** `receipts/{YEAR}/{MONTH}/receipt_{UUID}.png`

### API Routes

**Location:** `routes/api.php`

```php
Route::prefix('saved-receipts')->group(function () {
    Route::get('/', [SavedReceiptController::class, 'index']);
    Route::post('/', [SavedReceiptController::class, 'store']);
    Route::get('/{id}', [SavedReceiptController::class, 'show']);
    Route::get('/{id}/file', [SavedReceiptController::class, 'getFile']);
    Route::delete('/{id}', [SavedReceiptController::class, 'destroy']);
});
```

## Frontend Implementation

### Receipt Generation

**File:** `components/accountant/transaction/ReceiptGenerator.tsx`

**Function:** `saveReceiptAsImage()`

**Process:**
1. Generates PDF from transaction data using react-pdf
2. Converts PDF to PNG image using pdfjs
3. Uploads image to backend via `/api/accountant/saved-receipts`
4. Backend uploads to Firebase Storage
5. Backend saves record to `saved_receipts` table

### Frontend API Proxy

**File:** `app/api/accountant/saved-receipts/route.ts`

- Proxies POST requests to Laravel backend
- Handles FormData with file uploads
- Includes authentication token from cookies

## Flow Diagram

```
Transaction Created Successfully
    ↓
Frontend: saveReceiptAsImage() called
    ↓
Generate PDF (react-pdf)
    ↓
Convert PDF to PNG (pdfjs)
    ↓
POST /api/accountant/saved-receipts (Frontend API)
    ↓
POST /api/accountant/saved-receipts (Laravel Backend)
    ↓
Upload to Firebase Storage (receipts/{YEAR}/{MONTH}/receipt_{UUID}.png)
    ↓
Save record to saved_receipts table
    ↓
Return success response
```

## Testing

### Check if Receipts are Being Saved

1. **Check Database:**
   ```sql
   SELECT * FROM saved_receipts ORDER BY created_at DESC LIMIT 10;
   ```

2. **Check Console Logs:**
   - Open browser console
   - Look for: "Receipt saved successfully" or error messages
   - Check Network tab for `/api/accountant/saved-receipts` requests

3. **Check Firebase Storage:**
   - Go to Firebase Console
   - Navigate to Storage
   - Check `receipts/` folder

### Common Issues

1. **Receipts not saving:**
   - Check browser console for errors
   - Verify Firebase Storage is accessible
   - Check backend logs: `storage/logs/laravel.log`
   - Ensure transaction_id is being passed correctly

2. **PDF to Image conversion failing:**
   - Check if pdfjs worker is loading
   - Verify browser supports Canvas API
   - Check console for "Error converting PDF to image"

3. **Firebase upload failing:**
   - Verify Firebase credentials are configured
   - Check network connectivity
   - Verify Firebase Storage bucket exists

## Verification Checklist

- [x] `saved_receipts` table exists and migrated
- [x] `SavedReceiptController` implements Firebase Storage upload
- [x] Frontend API proxy route exists
- [x] `saveReceiptAsImage()` function is called after transaction creation
- [x] Error logging improved for debugging
- [x] Receipt image is uploaded to Firebase Storage
- [x] Database record is created with Firebase URL

## Next Steps

If receipts are still not being saved:

1. **Enable detailed logging:**
   - Check browser console for detailed error messages
   - Check backend logs for Firebase upload errors

2. **Test manually:**
   - Create a transaction
   - Check browser console for "Receipt saved successfully" message
   - Verify database record exists
   - Verify file exists in Firebase Storage

3. **Debug Firebase:**
   - Test Firebase Storage connection
   - Verify credentials file exists
   - Check Firebase Storage bucket permissions
