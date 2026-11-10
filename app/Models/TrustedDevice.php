<?php

namespace App\Models;

use Database\Factories\TrustedDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A device the user asked to remember after confirming a one-time code. Only the
 * SHA-256 of the token the client keeps is stored, so a leaked table is useless.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property Carbon $last_used_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'token_hash', 'user_agent', 'ip_address', 'last_used_at', 'expires_at'])]
#[Hidden(['token_hash'])]
class TrustedDevice extends Model
{
    /** @use HasFactory<TrustedDeviceFactory> */
    use HasFactory, Prunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The client's plain token is never stored; this is how it is matched.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Expired rows are dead weight; `model:prune` removes them.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
