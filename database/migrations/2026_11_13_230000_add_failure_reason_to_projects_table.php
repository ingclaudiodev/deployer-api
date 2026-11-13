<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why the last analysis or deployment of a project failed, for its owner to
     * read. Empty while nothing failed, and again once a new attempt starts.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('failure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('failure_reason');
        });
    }
};
