<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project stops being always a service: it can also be a job (runs and ends),
     * a worker pool (always on, takes no requests) or a single instance. What only
     * makes sense for some kinds becomes nullable, and the timeout grows to hold the
     * 168 hours a job task may run. Projects that already exist stay services billed
     * per request, which is what they were.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('kind', 16)->default('servicio')->after('name');
            $table->string('billing', 16)->nullable()->default('solicitudes')->after('kind');
            $table->unsignedSmallInteger('tasks')->nullable()->after('timeout_seconds');
            $table->unsignedTinyInteger('max_retries')->nullable()->after('tasks');
            $table->boolean('delayed')->default(false)->after('max_retries');

            $table->unsignedSmallInteger('min_instances')->nullable()->change();
            $table->unsignedSmallInteger('max_instances')->nullable()->change();
            $table->unsignedSmallInteger('concurrency')->nullable()->change();
            $table->unsignedInteger('timeout_seconds')->nullable()->change();
            $table->unsignedSmallInteger('port')->nullable()->change();
            $table->boolean('public_access')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'billing', 'tasks', 'max_retries', 'delayed']);
        });
    }
};
