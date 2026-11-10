<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fixed set of roles a user can hold. The rows are part of the schema, not
     * seed data: the `users.role_id` migration and every test rely on them existing.
     *
     * @var array<int, array{slug: string, name: string}>
     */
    private array $roles = [
        ['slug' => 'super_admin', 'name' => 'Super administrador'],
        ['slug' => 'admin', 'name' => 'Administrador'],
        ['slug' => 'usuario', 'name' => 'Usuario'],
        ['slug' => 'soporte', 'name' => 'Soporte'],
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
            $table->timestamps();
        });

        $now = now();

        DB::table('roles')->insert(array_map(
            fn (array $role): array => $role + ['created_at' => $now, 'updated_at' => $now],
            $this->roles,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
