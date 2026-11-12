<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two things a project may add to its instances: a GPU (with or without zonal
     * redundancy) and an ephemeral disk mounted at a path. Both are optional, so
     * the columns are nullable and existing projects keep running without them.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('gpu', 24)->nullable();
            $table->boolean('gpu_zonal_redundancy')->default(false);
            $table->unsignedSmallInteger('ephemeral_disk_gb')->nullable();
            $table->string('ephemeral_disk_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['gpu', 'gpu_zonal_redundancy', 'ephemeral_disk_gb', 'ephemeral_disk_path']);
        });
    }
};
