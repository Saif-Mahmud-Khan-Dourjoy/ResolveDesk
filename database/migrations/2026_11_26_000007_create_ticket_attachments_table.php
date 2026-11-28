<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2: files uploaded with a ticket or with a comment.
 * The file itself lives on the PRIVATE "local" disk (storage/app/private);
 * this table stores where it is and who uploaded it. Downloads always go
 * through TicketAttachmentController, which checks ticket authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('ticket_comment_id')->nullable()->constrained('ticket_comments')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');        // Shown to users; never used as a storage path
            $table->string('storage_path');         // Generated name, e.g. ticket-attachments/2026/11/9f1c...e2.pdf
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');     // Bytes
            $table->timestamps();

            $table->index(['ticket_id', 'ticket_comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
