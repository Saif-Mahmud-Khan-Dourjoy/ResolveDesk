<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the "users" table and Laravel's "sessions" table.
 *
 * ResolveDesk uses ONE users table for all three roles (admin, agent, customer).
 * The "role" column decides what a user can do. Allowed role values are kept
 * in App\Models\User::ROLES (a plain string column, not a database ENUM, so a
 * new role can be added without changing the database schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();          // Login identifier - must be unique.
            $table->string('password');                 // Stored as a bcrypt hash, never plain text.
            $table->string('role', 20)->index();        // admin | agent | customer
            $table->boolean('is_active')->default(true); // Inactive users cannot log in.
            $table->rememberToken();
            $table->timestamps();
        });

        // Laravel stores session data here because SESSION_DRIVER=database.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
