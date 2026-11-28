<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * V2: secure attachment storage.
 *
 * IMPORTANT: a database transaction cannot undo a file written to disk.
 * So TicketService uses this order:
 *   1. storeFiles()    - write files to the private disk with generated names
 *   2. DB::transaction - create ticket/comment + createRecords()
 *   3. if anything fails -> deleteFiles() removes the files again (no orphans)
 */
class AttachmentService
{
    /**
     * Writes uploaded files to the private disk.
     *
     * @param  array<UploadedFile>  $files  already validated (size, extension, MIME)
     * @return array<array{original_name: string, storage_path: string, mime_type: string, size: int}>
     */
    public function storeFiles(array $files): array
    {
        $disk = config('resolvedesk.attachments.disk');
        $storedFiles = [];

        try {
            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());

                // Generated name: the user's file name is NEVER used as a path
                // (prevents path traversal like "../../.env" and name collisions).
                $storagePath = $file->storeAs(
                    'ticket-attachments/' . now()->format('Y/m'),
                    Str::uuid() . '.' . $extension,
                    $disk
                );

                if ($storagePath === false) {
                    throw new \RuntimeException('The file could not be saved.');
                }

                $storedFiles[] = [
                    'original_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
                    'storage_path' => $storagePath,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream', // detected from content
                    'size' => (int) $file->getSize(),
                ];
            }
        } catch (Throwable $exception) {
            $this->deleteFiles($storedFiles); // remove the files written before the failure
            throw $exception;
        }

        return $storedFiles;
    }

    /** Inserts one ticket_attachments row per stored file. Call inside the ticket/comment transaction. */
    public function createRecords(array $storedFiles, Ticket $ticket, ?TicketComment $comment, User $uploader): void
    {
        foreach ($storedFiles as $storedFile) {
            $attachment = new TicketAttachment;
            $attachment->ticket_id = $ticket->id;
            $attachment->ticket_comment_id = $comment?->id;
            $attachment->uploaded_by = $uploader->id;
            $attachment->original_name = $storedFile['original_name'];
            $attachment->storage_path = $storedFile['storage_path'];
            $attachment->mime_type = $storedFile['mime_type'];
            $attachment->size = $storedFile['size'];
            $attachment->save();
        }
    }

    /** Best-effort cleanup after a failed database operation. */
    public function deleteFiles(array $storedFiles): void
    {
        $disk = config('resolvedesk.attachments.disk');

        foreach ($storedFiles as $storedFile) {
            try {
                Storage::disk($disk)->delete($storedFile['storage_path']);
            } catch (Throwable $exception) {
                Log::warning('Could not delete orphaned attachment file', [
                    'path' => $storedFile['storage_path'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /** Validation rules shared by ticket creation and comments. */
    public static function validationRules(): array
    {
        $extensions = implode(',', config('resolvedesk.attachments.allowed_extensions'));

        return [
            'attachments' => ['nullable', 'array', 'max:' . config('resolvedesk.attachments.max_files')],
            'attachments.*' => [
                'file',
                'max:' . config('resolvedesk.attachments.max_file_kb'),
                'extensions:' . $extensions, // the file name's extension
                'mimes:' . $extensions,      // the MIME type detected from the file content
            ],
        ];
    }
}
