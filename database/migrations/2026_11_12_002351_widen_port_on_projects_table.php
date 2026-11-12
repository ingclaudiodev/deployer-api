<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A port goes up to 65,535, but PostgreSQL has no unsigned types: the
     * `unsignedSmallInteger` the column was created with is a `smallint` there and
     * stops at 32,767. An integer holds every valid port.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedInteger('port')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedSmallInteger('port')->nullable()->change();
        });
    }
};
