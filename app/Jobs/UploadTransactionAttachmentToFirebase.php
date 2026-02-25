<?php

namespace App\Jobs;

use App\Models\TransactionAttachment;
use App\Services\FirebaseStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadTransactionAttachmentToFirebase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 120;

    public function __construct(
        public int $attachmentId,
        public string $tempPath,
        public string $firebasePath,
        public string $mimeType
    ) {}

    public function handle(): void
    {
        $attachment = TransactionAttachment::find($this->attachmentId);
        if (!$attachment || $attachment->upload_status === 'completed') {
            return;
        }

        $fullTempPath = Storage::disk('local')->path($this->tempPath);
        if (!file_exists($fullTempPath)) {
            Log::error('UploadTransactionAttachmentToFirebase: temp file not found', [
                'attachment_id' => $this->attachmentId,
                'temp_path' => $fullTempPath,
            ]);
            $attachment->update(['upload_status' => 'failed']);
            return;
        }

        try {
            $firebaseStorage = new FirebaseStorageService();
            $content = file_get_contents($fullTempPath);
            $firebaseUrl = $firebaseStorage->uploadContent($content, $this->firebasePath, $this->mimeType);

            $attachment->update([
                'file_path' => $firebaseUrl,
                'upload_status' => 'completed',
                'temp_path' => null,
            ]);

            Storage::disk('local')->delete($this->tempPath);

            Log::info('UploadTransactionAttachmentToFirebase: upload completed', [
                'attachment_id' => $this->attachmentId,
            ]);
        } catch (\Exception $e) {
            Log::error('UploadTransactionAttachmentToFirebase: upload failed', [
                'attachment_id' => $this->attachmentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $attachment = TransactionAttachment::find($this->attachmentId);
        if ($attachment) {
            $attachment->update(['upload_status' => 'failed']);
        }
        Log::error('UploadTransactionAttachmentToFirebase: job failed permanently', [
            'attachment_id' => $this->attachmentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
