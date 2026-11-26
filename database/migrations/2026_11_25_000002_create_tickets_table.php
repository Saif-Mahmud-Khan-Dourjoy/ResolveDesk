<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central business table: one row per support ticket.
 *
 * Two columns reference the users table:
 *   created_by  -> the user who reported the ticket (customer, agent or admin)
 *   assigned_to -> the support agent working on it (NULL while unassigned)
 *
 * restrictOnDelete(): the database refuses to delete a user or category that
 * is still referenced by a ticket. This protects the ticket history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // Human-friendly number like TK-000001. It is built from the
            // auto-increment id right after the INSERT, so it is NULL for a
            // brief moment inside the creation transaction. A UNIQUE index
            // allows multiple NULLs in MySQL, so this is safe.
            $table->string('ticket_number', 20)->nullable()->unique();

            $table->string('subject');
            $table->text('description');

            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('priority', 20);  // low | medium | high | critical (see Ticket::PRIORITIES)
            $table->string('status', 20);    // open | assigned | in_progress | resolved | closed

            // DATETIME (not TIMESTAMP) on purpose: on MariaDB / older MySQL, the first
            // NOT NULL TIMESTAMP column silently gets "ON UPDATE CURRENT_TIMESTAMP",
            // which would overwrite the SLA deadline on every UPDATE.
            $table->dateTime('due_at');                  // SLA deadline = created_at + priority hours
            $table->dateTime('resolved_at')->nullable(); // Set when resolved, cleared when reopened
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            // Indexes for the most common filters and dashboard queries.
            $table->index('status');
            $table->index('priority');
            $table->index('due_at');
            $table->index('created_at');
            // Agent workload query: "WHERE assigned_to = ? AND status IN (...)".
            // MySQL can also use this composite index for the assigned_to foreign key.
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
