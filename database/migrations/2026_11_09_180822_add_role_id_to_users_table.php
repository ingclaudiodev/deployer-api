<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every user holds exactly one role. The column is added in stages because
     * `users` is already populated in deployed environments: nullable first, then
     * existing accounts are backfilled as administrators, then the NOT NULL lands.
     * Deleting a role is refused while users still hold it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')
                ->nullable()
                ->after('id')
                ->constrained('roles')
                ->restrictOnDelete();
        });

        $adminId = DB::table('roles')->where('slug', 'admin')->value('id');

        DB::table('users')->whereNull('role_id')->update(['role_id' => $adminId]);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
