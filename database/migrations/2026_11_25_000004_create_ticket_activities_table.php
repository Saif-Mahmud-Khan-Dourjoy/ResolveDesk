<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail: every important change to a ticket creates one row here.
 * Example: action = "priority_changed", old_value = "Medium", new_value = "Critical".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // Who did it
            $table->string('action', 50);           // created, assigned, resolved, ... (see TicketActivity::ACTION_LABELS)
            $table->text('old_value')->nullable();  // Simple readable text, e.g. "Open"
            $table->text('new_value')->nullable();  // Simple readable text, e.g. "Assigned"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_activities');
    }
};
