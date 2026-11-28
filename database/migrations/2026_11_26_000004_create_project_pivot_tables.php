<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 many-to-many pivot tables.
 *
 *   client_project: which client organizations use which projects.
 *   project_user:   which support agents support which projects (assignment eligibility).
 *
 * The composite UNIQUE index stops duplicate rows and also serves lookups that
 * start with its first column (project_id). The extra single-column index serves
 * lookups from the other direction ("projects of this client / of this agent").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'client_id']);
            $table->index('client_id');
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('client_project');
    }
};
