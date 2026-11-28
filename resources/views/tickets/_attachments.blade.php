{{--
    List of downloadable attachments. Links go to TicketAttachmentController, which
    checks TicketPolicy::downloadAttachment() - files have no public URL.
    Usage: @include('tickets._attachments', ['attachments' => $comment->attachments])
--}}
@if ($attachments->isNotEmpty())
    <div class="d-flex flex-wrap gap-2 mt-2">
        @foreach ($attachments as $attachment)
            <a href="{{ route('tickets.attachments.download', [$attachment->ticket_id, $attachment->id]) }}"
               class="btn btn-sm btn-light border text-truncate" style="max-width: 260px;" title="{{ $attachment->original_name }}">
                <i class="bi bi-paperclip"></i> {{ $attachment->original_name }}
                <span class="text-muted small">({{ $attachment->readableSize() }})</span>
            </a>
        @endforeach
    </div>
@endif
