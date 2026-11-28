<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2:
 *  - ticket_comments.visibility: 'public' (reply) or 'internal' (staff-only note).
 *    Existing comments become public replies (they were all visible in V1).
 *  - ticket_activities.user_id becomes nullable: NULL = system action (the
 *    SLA scheduler), so system activities are distinguishable from user actions.
 *  - ticket_activities.is_internal: staff-only entries are hidden from customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->string('visibility', 20)->default('public')->after('message');
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->boolean('is_internal')->default(false)->after('new_value');
        });
    }

    public function down(): void
    {
        // Note: this rollback fails while system activities (user_id NULL) exist.
        Schema::table('ticket_activities', function (Blueprint $table) {
            $table->dropColumn('is_internal');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'created_at']);
            $table->dropColumn('visibility');
        });
    }
};
