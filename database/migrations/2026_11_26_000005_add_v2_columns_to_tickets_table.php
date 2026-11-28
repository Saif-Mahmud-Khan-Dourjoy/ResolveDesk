<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 ticket columns. Every new column is nullable or has a default, so the
 * existing V1 tickets stay valid and become GLOBAL tickets (project_id NULL,
 * client_id NULL). No historical project/client mapping is invented.
 *
 *   project_id      NULL = global ticket (not tied to a project). Global does NOT mean public.
 *   client_id       NULL = internal ticket (no client organization).
 *   version         optimistic locking counter, +1 on every ticket change.
 *   sla_notified_at when the SLA-breach reminder was sent (prevents repeats).
 *   request_token   idempotency key from the create form; UNIQUE per creator,
 *                   so a double-submitted form cannot create two tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('category_id')
                ->constrained('projects')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->after('project_id')
                ->constrained('clients')->restrictOnDelete();
            $table->dateTime('sla_notified_at')->nullable()->after('resolved_at');
            $table->unsignedInteger('version')->default(1)->after('resolution_note');
            $table->string('request_token', 64)->nullable()->after('version');

            // MySQL allows many NULLs in a unique index, so old tickets (NULL token) are fine.
            $table->unique(['created_by', 'request_token']);
            $table->index(['project_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['status', 'due_at']);      // SLA scheduler + overdue filter
        });
    }

    public function down(): void
    {
        // Foreign keys first: MySQL refuses to drop an index that a foreign key still uses.
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['project_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_at']);
            $table->dropIndex(['client_id', 'status']);
            $table->dropIndex(['project_id', 'status']);
            $table->dropUnique(['created_by', 'request_token']);
            $table->dropColumn(['project_id', 'client_id', 'version', 'sla_notified_at', 'request_token']);
        });
    }
};
