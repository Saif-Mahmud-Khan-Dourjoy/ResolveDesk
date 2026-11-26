<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket categories such as "Billing" or "Software Bug".
 * Categories are never removed while tickets use them; admins deactivate them instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();      // Category names must be unique.
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true); // Only active categories appear in new ticket forms.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
