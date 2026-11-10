<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One of the fixed roles a user can hold. The rows are created by the
 * `create_roles_table` migration; code refers to them by slug, never by id.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 */
#[Fillable(['slug', 'name'])]
class Role extends Model
{
    public const string SUPER_ADMIN = 'super_admin';

    public const string ADMIN = 'admin';

    public const string USUARIO = 'usuario';

    public const string SOPORTE = 'soporte';

    /**
     * Roles that a role also counts as. Only super_admin implies another one: it
     * passes every admin check. The rest are peers, not steps of a ladder.
     *
     * @var array<string, list<string>>
     */
    private const array IMPLIES = [
        self::SUPER_ADMIN => [self::ADMIN],
    ];

    /**
     * This role's slug plus every slug it implies.
     *
     * @return list<string>
     */
    public function slugsCovered(): array
    {
        return [$this->slug, ...self::IMPLIES[$this->slug] ?? []];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The role behind a slug. Throws when the slug is unknown: a missing role is
     * a schema problem, not something to recover from at runtime.
     */
    public static function findBySlug(string $slug): self
    {
        return static::query()->where('slug', $slug)->firstOrFail();
    }
}
