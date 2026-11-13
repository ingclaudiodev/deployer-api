<?php

use App\Exceptions\Repositories\TokenRefreshException;
use App\Models\RepositoryConnection;
use App\Services\Repositories\RefreshTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    config([
        'services.github.client_id' => 'github-client',
        'services.github.client_secret' => 'github-secret',
        'services.gitlab.client_id' => 'gitlab-client',
        'services.gitlab.client_secret' => 'gitlab-secret',
        'services.gitlab.url' => 'https://gitlab.com',
    ]);
});

test('it returns the stored access token while it is still valid', function (): void {
    Http::fake();
    $connection = RepositoryConnection::factory()->create(['access_token' => 'current']);

    expect(app(RefreshTokenService::class)->accessToken($connection))->toBe('current');

    Http::assertNothingSent();
});

test('it never renews a token that does not expire', function (): void {
    Http::fake();
    $connection = RepositoryConnection::factory()->withoutExpiry()->create(['access_token' => 'forever']);

    expect(app(RefreshTokenService::class)->accessToken($connection))->toBe('forever');

    Http::assertNothingSent();
});

test('it renews an expired token at the host of the connection and stores the new pair', function (string $state, string $url, string $clientId): void {
    $this->freezeSecond();
    Http::fake([$url => Http::response([
        'access_token' => 'new-access',
        'refresh_token' => 'new-refresh',
        'expires_in' => 7200,
    ])]);
    $connection = RepositoryConnection::factory()->{$state}()->expired()->create(['refresh_token' => 'old-refresh']);

    $token = app(RefreshTokenService::class)->accessToken($connection);

    expect($token)->toBe('new-access');

    $stored = $connection->fresh();
    expect($stored->access_token)->toBe('new-access')
        ->and($stored->refresh_token)->toBe('new-refresh')
        ->and($stored->access_token_expires_at->equalTo(now()->addSeconds(7200)))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === $url
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh'
        && $request['client_id'] === $clientId);
})->with([
    'github' => ['withGitHub', 'https://github.com/login/oauth/access_token', 'github-client'],
    'gitlab' => ['gitlab', 'https://gitlab.com/oauth/token', 'gitlab-client'],
]);

test('it renews a token that is about to expire', function (): void {
    Http::fake(['*' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 28800])]);
    $connection = RepositoryConnection::factory()->create(['access_token_expires_at' => now()->addMinute()]);

    expect(app(RefreshTokenService::class)->accessToken($connection))->toBe('new-access');
});

test('it uses the token another request already renewed instead of spending the refresh token again', function (): void {
    Http::fake();
    $connection = RepositoryConnection::factory()->expired()->create();
    $stale = RepositoryConnection::query()->findOrFail($connection->id);
    $connection->forceFill(['access_token' => 'renewed-elsewhere', 'access_token_expires_at' => now()->addHours(8)])->save();

    expect(app(RefreshTokenService::class)->accessToken($stale))->toBe('renewed-elsewhere');

    Http::assertNothingSent();
});

test('it asks for a reconnection when the host rejects the refresh token', function (string $state, int $status): void {
    Http::fake(['*' => Http::response(['error' => 'bad_refresh_token'], $status)]);
    $connection = RepositoryConnection::factory()->{$state}()->expired()->create(['access_token' => 'old-access']);

    try {
        app(RefreshTokenService::class)->accessToken($connection);
        $this->fail('The refresh should have been rejected.');
    } catch (TokenRefreshException $exception) {
        expect($exception->requiresReconnection)->toBeTrue()
            ->and($connection->fresh()->access_token)->toBe('old-access');
    }
})->with([
    'github answers 200 with an error' => ['withGitHub', 200],
    'gitlab answers 400' => ['gitlab', 400],
]);

test('it asks for a reconnection when an expired token has no refresh token', function (): void {
    Http::fake();
    $connection = RepositoryConnection::factory()->expired()->create(['refresh_token' => null]);

    try {
        app(RefreshTokenService::class)->accessToken($connection);
        $this->fail('The refresh should have failed.');
    } catch (TokenRefreshException $exception) {
        expect($exception->requiresReconnection)->toBeTrue();
    }

    Http::assertNothingSent();
});

test('it fails without asking for a reconnection when the host is down', function (): void {
    Http::fake(['*' => Http::response('Bad Gateway', 502)]);
    $connection = RepositoryConnection::factory()->expired()->create();

    try {
        app(RefreshTokenService::class)->accessToken($connection);
        $this->fail('The refresh should have failed.');
    } catch (TokenRefreshException $exception) {
        expect($exception->requiresReconnection)->toBeFalse();
    }
});

test('it refuses to call a host whose OAuth application is not configured', function (): void {
    Http::fake();
    config(['services.github.client_secret' => null]);
    $connection = RepositoryConnection::factory()->expired()->create();

    app(RefreshTokenService::class)->accessToken($connection);
})->throws(TokenRefreshException::class, 'not configured');
