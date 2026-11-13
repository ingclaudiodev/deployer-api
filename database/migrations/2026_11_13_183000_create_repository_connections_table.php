<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per account a user connected at a repository host: at most one
     * GitHub and one GitLab account each. Tokens are stored encrypted, so the
     * columns are text.
     */
    public function up(): void
    {
        Schema::create('repository_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('account_id');
            $table->string('account_login');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_connections');
    }
};
