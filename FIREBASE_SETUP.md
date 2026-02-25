# Firebase Storage Setup Guide

This guide will help you set up Firebase Storage for image uploads in the accounting application.

## Prerequisites

1. A Firebase project (create one at https://console.firebase.google.com/)
2. Firebase Storage enabled in your Firebase project
3. A service account key JSON file from Firebase

## Step 1: Create Firebase Project and Enable Storage

1. Go to https://console.firebase.google.com/
2. Create a new project or select an existing one
3. Navigate to **Storage** in the left sidebar
4. Click **Get Started** and follow the setup wizard
5. Choose **Start in test mode** (you can configure security rules later)

## Step 2: Create Service Account

1. Go to Firebase Console → Project Settings → Service Accounts
2. Click **Generate New Private Key**
3. Download the JSON file (it will be named something like `your-project-firebase-adminsdk-xxxxx.json`)
4. Save this file securely - **DO NOT commit it to version control**

## Step 3: Configure Backend

1. Place the downloaded Firebase service account JSON file in:
   ```
   storage/app/firebase-credentials.json
   ```

2. Make sure the file has proper permissions (readable by the web server)

3. Install PHP dependencies:
   ```bash
   composer install
   ```
   This will install the `kreait/firebase-php` package.

4. **IMPORTANT: Enable Firebase Storage in Firebase Console**
   - Go to Firebase Console → Storage
   - Click **Get Started** if you haven't already
   - Choose **Start in test mode** (you can configure security rules later)
   - This will automatically create the default storage bucket: `{project-id}.appspot.com`
   - If you have a custom bucket name, add it to your `.env` file:
     ```
     FIREBASE_STORAGE_BUCKET=your-custom-bucket-name.appspot.com
     ```

## Step 4: Configure Firebase Storage Rules

**IMPORTANT:** For backend uploads using service accounts, you need to allow public writes OR the service account will bypass rules automatically.

In Firebase Console → Storage → Rules, update the rules to:

**Option 1: Allow public writes (for development/testing)**
```javascript
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    match /{allPaths=**} {
      allow read: if true;
      allow write: if true; // Allow all writes (for backend service account)
    }
  }
}
```

**Option 2: Allow authenticated writes (for production)**
```javascript
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    match /{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null; // Requires Firebase Auth
    }
  }
}
```

**Note:** 
- Service accounts (used by backend) typically bypass security rules, but if you're getting permission errors, use Option 1
- For production, implement more restrictive rules based on your needs
- Files uploaded via service account are automatically made public readable in the code

## Step 5: Test the Integration

1. Create a deposit or withdrawal transaction with an image attachment
2. Check Firebase Console → Storage to verify the file was uploaded
3. Verify the image displays correctly in the application

## Troubleshooting

### Error: "Firebase credentials file not found"
- Ensure the file is located at `storage/app/firebase-credentials.json`
- Check file permissions

### Error: "Failed to upload file to Firebase Storage" or "The specified bucket does not exist"
- **Most Common Issue**: Firebase Storage must be enabled in Firebase Console
  1. Go to Firebase Console → Storage
  2. Click **Get Started** if Storage is not enabled
  3. This will create the default bucket: `{project-id}.appspot.com`
- Verify your Firebase project has Storage enabled
- Check that the service account has proper permissions
- If using a custom bucket name, set `FIREBASE_STORAGE_BUCKET` in `.env` file
- Review Laravel logs for detailed error messages
- The bucket name is automatically detected from your `project_id` in the credentials file

### Images not displaying
- Ensure Firebase Storage rules allow public read access
- Check that the URL is being stored correctly in the database
- Verify the Firebase Storage bucket is accessible

## File Structure in Firebase Storage

Files will be organized as follows:
- Transaction attachments: `transactions/{transaction_id}/voucher_{uuid}.{ext}`
- Transaction attachments: `transactions/{transaction_id}/attachment_{uuid}.{ext}`
- Receipt images: `receipts/{YYYY}/{MM}/receipt_{uuid}.{ext}`

## Security Notes

- The service account JSON file contains sensitive credentials - keep it secure
- Add `storage/app/firebase-credentials.json` to `.gitignore`
- Consider using environment variables for Firebase configuration in production
- Review and update Firebase Storage security rules regularly
