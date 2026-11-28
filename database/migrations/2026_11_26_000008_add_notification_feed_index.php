<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 performance: the notification feed runs on every poll / real-time event:
 *
 *   SELECT * FROM notifications
 *   WHERE notifiable_type = 'App\Models\User' AND notifiable_id = ?
 *   ORDER BY created_at DESC LIMIT 5
 *
 * EXPLAIN showed "Using filesort" with Laravel's default (notifiable_type, notifiable_id)
 * index. Adding created_at as the third column lets MySQL read the rows already sorted.
 * The old 2-column index becomes redundant (same leading columns), so it is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_notifiable_created_at_index');
            $table->dropIndex(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->dropIndex('notifications_notifiable_created_at_index');
        });
    }
};
