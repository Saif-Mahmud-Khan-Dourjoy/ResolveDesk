<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2: software products the company supports (HRMS, ERP, Payroll, ...).
 * Projects are never deleted while tickets reference them; they are deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 30)->unique();          // Short unique code, e.g. "HRMS"
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);   // Inactive = not selectable for new tickets
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
