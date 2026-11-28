<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * V2: the ONLY way to get an attachment. Files live on the private disk, so
 * there is no public URL; every download passes through this authorization.
 *
 * GET /tickets/{ticket}/attachments/{attachment}
 */
class TicketAttachmentController extends Controller
{
    public function download(Ticket $ticket, TicketAttachment $attachment)
    {
        // Checks: attachment belongs to this ticket, user may view the ticket,
        // and internal-note files are staff-only. Otherwise HTTP 403.
        Gate::authorize('downloadAttachment', [$ticket, $attachment]);

        $disk = Storage::disk(config('resolvedesk.attachments.disk'));

        if (! $disk->exists($attachment->storage_path)) {
            Log::error('Attachment file missing on disk', ['attachment_id' => $attachment->id, 'ticket_id' => $ticket->id]);
            abort(404, 'The file could not be found.');
        }

        // download() sends "Content-Disposition: attachment", so the browser saves the
        // file instead of rendering it (an uploaded .html/.svg can never run as a page).
        // nosniff stops the browser from guessing a different, executable content type.
        return $disk->download($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
