<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One project = one repository deployed as one service. The row holds what
     * the user asked for (source and compute limits) and where the deploy flow
     * stands; deployments, metrics and logs get their own tables when they exist.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 49);
            $table->string('repo_provider', 16);
            $table->string('repo_full_name');
            $table->string('repo_branch');
            $table->string('region', 32);
            $table->string('cpu', 8);
            $table->unsignedInteger('memory_mb');
            $table->unsignedSmallInteger('min_instances');
            $table->unsignedSmallInteger('max_instances');
            $table->unsignedSmallInteger('concurrency');
            $table->unsignedSmallInteger('timeout_seconds');
            $table->unsignedSmallInteger('port');
            $table->boolean('public_access');
            $table->string('status', 32);
            $table->string('url')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
