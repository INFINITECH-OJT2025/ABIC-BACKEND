<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Exception;

class FirebaseStorageService
{
    protected $storage;
    protected $bucket;
    protected $bucketName;

    // Configuration constants
    private const MAX_RETRIES = 5;
    private const RETRY_DELAY_SECONDS = 3;
    private const SIGNED_URL_EXPIRATION_YEARS = 10;
    private const DEFAULT_CREDENTIALS_PATH = 'app/firebase-credentials.json';

    public function __construct()
    {
        $this->initializeFirebase();
    }

    /**
     * Initialize Firebase Storage connection.
     */
    protected function initializeFirebase(): void
    {
        try {
            $credentialsPath = $this->resolveCredentialsPath();
            $this->validateCredentialsFile($credentialsPath);
            
            $credentials = $this->loadCredentials($credentialsPath);
            $this->bucketName = $this->resolveBucketName($credentials['project_id'] ?? null);
            
            $this->initializeStorage($credentialsPath);
            $this->validateBucket();
            
            Log::info('Firebase Storage initialized successfully', [
                'bucket_name' => $this->bucketName,
                'project_id' => $credentials['project_id'] ?? null
            ]);
        } catch (Exception $e) {
            Log::error('Firebase Storage initialization failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Resolve the path to Firebase credentials file.
     */
    protected function resolveCredentialsPath(): string
    {
        $pathFromEnv = env('FIREBASE_CREDENTIALS_PATH', self::DEFAULT_CREDENTIALS_PATH);
        
        // Handle absolute paths
        if (str_starts_with($pathFromEnv, '/') || preg_match('/^[A-Z]:/', $pathFromEnv)) {
            return $pathFromEnv;
        }
        
        // Remove 'storage/' prefix if present (storage_path already adds it)
        $path = preg_replace('#^storage/#', '', $pathFromEnv);
        return storage_path($path);
    }

    /**
     * Validate that credentials file exists.
     */
    protected function validateCredentialsFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new Exception(
                "Firebase credentials file not found at: {$path}. " .
                "Please set FIREBASE_CREDENTIALS_PATH in .env or place credentials at storage/app/firebase-credentials.json"
            );
        }
    }

    /**
     * Load and validate credentials JSON file.
     */
    protected function loadCredentials(string $path): array
    {
        $credentials = json_decode(file_get_contents($path), true);
        
        if (!$credentials || json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid Firebase credentials file format. Please check your firebase-credentials.json file.');
        }
        
        return $credentials;
    }

    /**
     * Resolve bucket name from environment or generate default.
     */
    protected function resolveBucketName(?string $projectId): string
    {
        $bucketName = env('FIREBASE_STORAGE_BUCKET');
        
        if (!$bucketName && $projectId) {
            $bucketName = $projectId . '.appspot.com';
            Log::warning('FIREBASE_STORAGE_BUCKET not set in .env, using default', [
                'bucket_name' => $bucketName
            ]);
        }
        
        if (!$bucketName) {
            throw new Exception(
                'Firebase Storage bucket name not configured. ' .
                'Please set FIREBASE_STORAGE_BUCKET in .env file. ' .
                'Example: FIREBASE_STORAGE_BUCKET=your-project-id.appspot.com'
            );
        }
        
        return $this->cleanBucketName($bucketName);
    }

    /**
     * Clean bucket name by removing prefixes and slashes.
     */
    protected function cleanBucketName(string $bucketName): string
    {
        $bucketName = preg_replace('#^gs://#', '', $bucketName);
        $bucketName = preg_replace('#^https?://#', '', $bucketName);
        $bucketName = trim($bucketName, '/');
        
        return $bucketName;
    }

    /**
     * Initialize Firebase Storage instance.
     */
    protected function initializeStorage(string $credentialsPath): void
    {
        $firebase = (new Factory)->withServiceAccount($credentialsPath);
        $this->storage = $firebase->createStorage();
        $this->bucket = $this->storage->getBucket($this->bucketName);
    }

    /**
     * Validate that the bucket exists and is accessible.
     */
    protected function validateBucket(): void
    {
        try {
            $bucketInfo = $this->bucket->info();
            Log::debug('Firebase Storage bucket validated', [
                'bucket_name' => $this->bucketName,
                'location' => $bucketInfo['location'] ?? 'unknown',
                'storage_class' => $bucketInfo['storageClass'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            Log::error('Firebase Storage bucket validation failed', [
                'bucket_name' => $this->bucketName,
                'error' => $e->getMessage()
            ]);
            throw new Exception(
                "Failed to access Firebase Storage bucket '{$this->bucketName}'. " .
                "Please verify the bucket exists in Firebase Console. Error: {$e->getMessage()}"
            );
        }
    }

    /**
     * Upload a file to Firebase Storage.
     *
     * @param UploadedFile $file
     * @param string $path Path in Firebase Storage (e.g., 'transactions/123/voucher.png')
     * @return string Public URL of the uploaded file
     */
    public function uploadFile(UploadedFile $file, string $path): string
    {
        $fileContents = file_get_contents($file->getRealPath());
        $mimeType = $file->getMimeType();
        
        return $this->uploadContent($fileContents, $path, $mimeType);
    }

    /**
     * Upload file content directly (for blob data).
     *
     * @param string $content File content
     * @param string $path Path in Firebase Storage
     * @param string $mimeType MIME type of the file
     * @return string Public URL of the uploaded file
     */
    public function uploadContent(string $content, string $path, string $mimeType = 'image/png'): string
    {
        $attempt = 0;
        
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            
            try {
                Log::debug('Firebase Storage upload attempt', [
                    'attempt' => $attempt,
                    'path' => $path,
                    'bucket' => $this->bucketName,
                    'file_size' => strlen($content)
                ]);

                $object = $this->bucket->upload($content, [
                    'name' => $path,
                    'metadata' => ['contentType' => $mimeType],
                ]);

                // Make the file publicly accessible
                $object->update(['acl' => [['entity' => 'allUsers', 'role' => 'READER']]]);

                // Get public URL with long expiration
                $expiration = new \DateTime('+' . self::SIGNED_URL_EXPIRATION_YEARS . ' years');
                $publicUrl = $this->bucket->object($path)->signedUrl($expiration);

                Log::info('Firebase Storage upload successful', [
                    'path' => $path,
                    'url_length' => strlen($publicUrl)
                ]);

                return $publicUrl;
            } catch (\Exception $e) {
                if ($this->shouldRetry($e, $attempt)) {
                    Log::warning('Firebase Storage upload retry', [
                        'attempt' => $attempt,
                        'max_retries' => self::MAX_RETRIES,
                        'error' => $e->getMessage()
                    ]);
                    sleep(self::RETRY_DELAY_SECONDS);
                    continue;
                }
                
                $this->handleUploadError($e, $attempt, $path);
            }
        }
        
        // This should never be reached, but PHP requires it
        throw new Exception('Upload failed after all retry attempts');
    }

    /**
     * Determine if an error should trigger a retry.
     */
    protected function shouldRetry(\Exception $e, int $attempt): bool
    {
        if ($attempt >= self::MAX_RETRIES) {
            return false;
        }
        
        $errorMessage = $e->getMessage();
        $networkErrors = [
            'Could not resolve host',
            'cURL error 6',
            'Connection timed out',
            'Failed to connect',
            'Network is unreachable',
            'Operation timed out',
            'timed out',
            'Timeout',
            'cURL error 28',
            'Resolving timed out',
            'Connection reset',
            'SSL connection'
        ];
        
        foreach ($networkErrors as $error) {
            if (str_contains($errorMessage, $error)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Handle upload errors with appropriate logging and exception.
     */
    protected function handleUploadError(\Exception $e, int $attempt, string $path): void
    {
        $errorMessage = $e->getMessage();
        $isNetworkError = $this->isNetworkError($errorMessage);
        
        Log::error('Firebase Storage upload failed', [
            'attempts' => $attempt,
            'error' => $errorMessage,
            'path' => $path,
            'bucket' => $this->bucketName,
            'is_network_error' => $isNetworkError
        ]);
        
        if ($isNetworkError) {
            throw new Exception(
                "Network connectivity issue: Cannot reach Firebase Storage servers after {$attempt} attempts. " .
                "Please check your internet connection and DNS settings. Error: {$errorMessage}"
            );
        }
        
        throw new Exception("Failed to upload file to Firebase Storage: {$errorMessage}");
    }

    /**
     * Check if error message indicates a network issue.
     */
    protected function isNetworkError(string $errorMessage): bool
    {
        $networkErrors = [
            'Could not resolve host',
            'cURL error 6',
            'Connection timed out',
            'Failed to connect',
            'Network is unreachable',
            'Operation timed out',
            'timed out',
            'Timeout',
            'cURL error 28',
            'Resolving timed out',
            'Connection reset',
            'SSL connection'
        ];
        
        foreach ($networkErrors as $error) {
            if (str_contains($errorMessage, $error)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Delete a file from Firebase Storage.
     *
     * @param string $path Path in Firebase Storage or full URL
     * @return bool
     */
    public function deleteFile(string $path): bool
    {
        try {
            $storagePath = $this->extractPathFromUrl($path);
            $object = $this->bucket->object($storagePath);
            
            if ($object->exists()) {
                $object->delete();
                Log::info('Firebase Storage file deleted', ['path' => $storagePath]);
                return true;
            }
            
            Log::warning('Firebase Storage file not found for deletion', ['path' => $storagePath]);
            return false;
        } catch (\Exception $e) {
            Log::error('Firebase Storage delete failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if a file exists in Firebase Storage.
     *
     * @param string $path Path in Firebase Storage or full URL
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        try {
            $storagePath = $this->extractPathFromUrl($path);
            $object = $this->bucket->object($storagePath);
            return $object->exists();
        } catch (\Exception $e) {
            Log::debug('Firebase Storage file existence check failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get a signed URL for a file (valid for a specified duration).
     *
     * @param string $path Path in Firebase Storage or full URL
     * @param int $expirationMinutes Expiration time in minutes (default: 60)
     * @return string Signed URL
     */
    public function getSignedUrl(string $path, int $expirationMinutes = 60): string
    {
        try {
            $storagePath = $this->extractPathFromUrl($path);
            $expiration = new \DateTime("+{$expirationMinutes} minutes");
            return $this->bucket->object($storagePath)->signedUrl($expiration);
        } catch (\Exception $e) {
            Log::error('Firebase Storage signed URL generation failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to generate signed URL: {$e->getMessage()}");
        }
    }

    /**
     * Extract storage path from a full Firebase Storage URL.
     *
     * @param string $url Full Firebase Storage URL
     * @return string Storage path
     */
    protected function extractPathFromUrl(string $url): string
    {
        // If it's already a path (doesn't contain http/https), return as is
        if (!str_contains($url, 'http://') && !str_contains($url, 'https://')) {
            return $url;
        }

        // Extract path from Firebase Storage URL
        // Format: https://storage.googleapis.com/.../o/{path}?...
        $pattern = '/\/o\/([^?]+)/';
        if (preg_match($pattern, $url, $matches)) {
            return urldecode($matches[1]);
        }

        // Fallback: try to extract using bucket name
        if (str_contains($url, $this->bucketName)) {
            $parts = parse_url($url);
            if (isset($parts['path'])) {
                $pathParts = explode('/', trim($parts['path'], '/'));
                $keyIndex = array_search('o', $pathParts);
                if ($keyIndex !== false && isset($pathParts[$keyIndex + 1])) {
                    return urldecode($pathParts[$keyIndex + 1]);
                }
            }
        }

        // Final fallback: return original
        return $url;
    }
}
