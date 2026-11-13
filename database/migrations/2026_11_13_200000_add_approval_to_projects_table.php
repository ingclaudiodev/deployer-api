<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who signed the deployment of a project, when, which snapshot of its files
     * they signed and the build that is carrying it out. All empty until the
     * first approval.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_version', 32)->nullable();
            $table->string('deployment_build_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropColumn(['approved_at', 'approved_version', 'deployment_build_id']);
        });
    }
};
