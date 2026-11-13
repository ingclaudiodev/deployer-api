<?php

namespace App\Models;

use App\Enums\RepositoryProvider;
use Database\Factories\RepositoryConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The GitHub or GitLab account a user connected, with the OAuth tokens that
 * let Deployer read and write their repositories. Tokens are encrypted at rest.
 *
 * @property int $id
 * @property int $user_id
 * @property RepositoryProvider $provider
 * @property string $account_id Id of the account at the host.
 * @property string $account_login
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $access_token_expires_at `null` when the token does not expire.
 * @property Carbon|null $refresh_token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['provider', 'account_id', 'account_login'])]
#[Hidden(['access_token', 'refresh_token'])]
class RepositoryConnection extends Model
{
    /** @use HasFactory<RepositoryConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
