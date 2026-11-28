<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * V2: metadata of an uploaded file. The file is stored on the private "local"
 * disk at storage_path; it is never publicly reachable by URL.
 * Download: TicketAttachmentController@download (checks TicketPolicy first).
 */
class TicketAttachment extends Model
{
    // All columns are set explicitly by TicketService; nothing comes from mass assignment.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'ticket_comment_id' => 'integer',
            'uploaded_by' => 'integer',
            'size' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** NULL when the file was attached to the ticket itself (at creation). */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'ticket_comment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** 1536000 => "1.5 MB" */
    public function readableSize(): string
    {
        if ($this->size >= 1048576) {
            return round($this->size / 1048576, 1) . ' MB';
        }

        return max(1, round($this->size / 1024)) . ' KB';
    }
}
