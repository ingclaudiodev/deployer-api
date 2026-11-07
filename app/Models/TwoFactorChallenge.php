<?php

namespace App\Models;

use Database\Factories\TwoFactorChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending second-factor step of a sign-in: the password was right, the emailed
 * code has not been confirmed yet. Its id is the opaque handle the client holds
 * between the two requests, so it is a UUID (v7: time-ordered prefix plus 74 random
 * bits) and never a sequential number; guessing one only lets an attacker burn or
 * spam that challenge, never pass it without the emailed code.
 *
 * @property string $id
 * @property int $user_id
 * @property string $code_hash
 * @property int $attempts
 * @property string|null $ip_address
 * @property Carbon $last_sent_at
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'code_hash', 'attempts', 'ip_address', 'last_sent_at', 'expires_at', 'consumed_at'])]
#[Hidden(['code_hash'])]
class TwoFactorChallenge extends Model
{
    /** @use HasFactory<TwoFactorChallengeFactory> */
    use HasFactory, HasUuids, Prunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
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
     * Still open: not used, not expired. Attempts are checked by the service, which
     * also needs to tell "wrong code" apart from "challenge burned".
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Finished challenges are useless after a day; `model:prune` removes them.
     *
     * @return Builder<$this>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }
}
