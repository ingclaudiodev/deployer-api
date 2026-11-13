<?php

use App\Enums\RepositoryProvider;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

const CLIENT_FORM = 'http://localhost:5173/app/proyectos/nuevo?conexion=github&resultado=';

beforeEach(function (): void {
    Http::preventStrayRequests();

    config([
        'app.frontend_url' => 'http://localhost:5173',
        'services.github.client_id' => 'github-client',
        'services.github.client_secret' => 'github-secret',
    ]);
});

/**
 * Starts a connection as the given user and returns the state GitHub would send back.
 */
function startGitHubConnection(User $user): string
{
    Passport::actingAs($user);

    $url = test()->postJson(route('repositories.connect', ['provider' => 'github']))->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'];
}

test('repository endpoints require a signed-in user', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    'connections' => ['GET', '/api/repositories/connections'],
    'connect' => ['POST', '/api/repositories/github/connect'],
    'repos' => ['GET', '/api/repositories/github/repos'],
    'branches' => ['GET', '/api/repositories/github/branches'],
    'disconnect' => ['DELETE', '/api/repositories/github'],
]);

test('a provider that does not exist is not found', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/repositories/bitbucket/connect')->assertNotFound();
});

test('connecting GitLab is not available yet', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('repositories.connect', ['provider' => 'gitlab']))->assertNotFound();
});

test('connecting sends the browser to GitHub with the callback of this API and the repo scope', function (): void {
    Passport::actingAs(User::factory()->create());

    $url = $this->postJson(route('repositories.connect', ['provider' => 'github']))->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://github.com/login/oauth/authorize?')
        ->and($query['client_id'])->toBe('github-client')
        ->and($query['scope'])->toBe('repo')
        ->and($query['redirect_uri'])->toBe(route('repositories.callback', ['provider' => 'github']))
        ->and($query['state'])->not->toBeEmpty();
});

test('the callback stores the tokens of the account and returns the browser to the client', function (): void {
    $this->freezeSecond();
    $user = User::factory()->create();
    $state = startGitHubConnection($user);
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'gho-access', 'refresh_token' => 'ghr-refresh', 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600,
        ]),
        'https://api.github.com/user' => Http::response(['id' => 583231, 'login' => 'octocat']),
    ]);

    $this->get(route('repositories.callback', ['provider' => 'github', 'code' => 'the-code', 'state' => $state]))
        ->assertRedirect(CLIENT_FORM.'ok');

    $connection = $user->repositoryConnections()->sole();
    expect($connection->provider)->toBe(RepositoryProvider::GitHub)
        ->and($connection->account_id)->toBe('583231')
        ->and($connection->account_login)->toBe('octocat')
        ->and($connection->access_token)->toBe('gho-access')
        ->and($connection->refresh_token)->toBe('ghr-refresh')
        ->and($connection->access_token_expires_at->equalTo(now()->addSeconds(28800)))->toBeTrue()
        ->and($connection->getRawOriginal('access_token'))->not->toContain('gho-access');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://github.com/login/oauth/access_token'
        && $request['code'] === 'the-code'
        && $request['client_secret'] === 'github-secret');
});

test('connecting again replaces the tokens instead of adding a second connection', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create(['access_token' => 'old']);
    $state = startGitHubConnection($user);
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'new']),
        'https://api.github.com/user' => Http::response(['id' => 1, 'login' => 'octocat']),
    ]);

    $this->get(route('repositories.callback', ['provider' => 'github', 'code' => 'c', 'state' => $state]))->assertRedirect(CLIENT_FORM.'ok');

    expect($user->repositoryConnections()->sole()->access_token)->toBe('new');
});

test('a callback with a made-up state connects nothing', function (): void {
    Http::fake();
    $user = User::factory()->create();
    startGitHubConnection($user);

    $this->get(route('repositories.callback', ['provider' => 'github', 'code' => 'c', 'state' => 'made-up']))
        ->assertRedirect(CLIENT_FORM.'error');

    expect($user->repositoryConnections()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a callback without a code, as when the user refuses at GitHub, connects nothing', function (): void {
    Http::fake();
    $user = User::factory()->create();
    $state = startGitHubConnection($user);

    $this->get(route('repositories.callback', ['provider' => 'github', 'error' => 'access_denied', 'state' => $state]))
        ->assertRedirect(CLIENT_FORM.'error');

    expect($user->repositoryConnections()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a state cannot be used twice', function (): void {
    $user = User::factory()->create();
    $state = startGitHubConnection($user);
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'first']),
        'https://api.github.com/user' => Http::response(['id' => 1, 'login' => 'octocat']),
    ]);
    $callback = route('repositories.callback', ['provider' => 'github', 'code' => 'c', 'state' => $state]);

    $this->get($callback)->assertRedirect(CLIENT_FORM.'ok');
    $this->get($callback)->assertRedirect(CLIENT_FORM.'error');
});

test('a code GitHub rejects connects nothing', function (): void {
    $user = User::factory()->create();
    $state = startGitHubConnection($user);
    Http::fake(['https://github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code'])]);

    $this->get(route('repositories.callback', ['provider' => 'github', 'code' => 'c', 'state' => $state]))
        ->assertRedirect(CLIENT_FORM.'error');

    expect($user->repositoryConnections()->count())->toBe(0);
});

test('the connections of the user are listed without their tokens', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create(['account_login' => 'octocat']);
    RepositoryConnection::factory()->create();
    Passport::actingAs($user);

    $this->getJson(route('repositories.connections'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.proveedor', 'github')
        ->assertJsonPath('0.cuenta', 'octocat')
        ->assertJsonMissingPath('0.access_token')
        ->assertJsonMissingPath('0.refresh_token');
});

test('it lists the repositories of the connected account', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create(['access_token' => 'gho-access']);
    Passport::actingAs($user);
    Http::fake(['https://api.github.com/user/repos*' => Http::response([
        ['full_name' => 'octocat/orders-api', 'private' => true, 'default_branch' => 'develop'],
        ['full_name' => 'acme/site', 'private' => false, 'default_branch' => 'main'],
    ])]);

    $this->getJson(route('repositories.repos', ['provider' => 'github', 'pagina' => 2]))
        ->assertOk()
        ->assertExactJson([
            ['nombreCompleto' => 'octocat/orders-api', 'privado' => true, 'ramaPorDefecto' => 'develop'],
            ['nombreCompleto' => 'acme/site', 'privado' => false, 'ramaPorDefecto' => 'main'],
        ]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer gho-access')
        && (int) $request['page'] === 2);
});

test('it lists the branches of a repository', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create();
    Passport::actingAs($user);
    Http::fake(['https://api.github.com/repos/octocat/orders-api/branches*' => Http::response([['name' => 'main'], ['name' => 'develop']])]);

    $this->getJson(route('repositories.branches', ['provider' => 'github', 'repositorio' => 'octocat/orders-api']))
        ->assertOk()
        ->assertExactJson(['main', 'develop']);
});

test('a repository name that is not owner/name never reaches GitHub', function (string $repository): void {
    Http::fake();
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create();
    Passport::actingAs($user);

    $this->getJson(route('repositories.branches', ['provider' => 'github', 'repositorio' => $repository]))
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with(['no owner' => ['orders-api'], 'climbs' => ['octocat/../../user'], 'query' => ['octocat/orders-api?x=1']]);

test('reading repositories without a connected account asks to connect', function (): void {
    Http::fake();
    Passport::actingAs(User::factory()->create());

    $this->getJson(route('repositories.repos', ['provider' => 'github']))
        ->assertConflict()
        ->assertJsonPath('reconectar', true);
});

test('reading repositories asks for a reconnection when GitHub no longer accepts the token', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create();
    Passport::actingAs($user);
    Http::fake(['https://api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $this->getJson(route('repositories.repos', ['provider' => 'github']))
        ->assertConflict()
        ->assertJsonPath('reconectar', true);
});

test('reading repositories renews an expired token first', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->expired()->create();
    Passport::actingAs($user);
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'renewed', 'refresh_token' => 'next', 'expires_in' => 28800]),
        'https://api.github.com/user/repos*' => Http::response([]),
    ]);

    $this->getJson(route('repositories.repos', ['provider' => 'github']))->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer renewed'));
});

test('reading repositories asks for a reconnection when the refresh token was rejected', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->expired()->create();
    Passport::actingAs($user);
    Http::fake(['https://github.com/login/oauth/access_token' => Http::response(['error' => 'bad_refresh_token'])]);

    $this->getJson(route('repositories.repos', ['provider' => 'github']))
        ->assertConflict()
        ->assertJsonPath('reconectar', true);
});

test('a failure of GitHub is a bad gateway, not a reconnection', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create();
    Passport::actingAs($user);
    Http::fake(['https://api.github.com/*' => Http::response('', 503)]);

    $this->getJson(route('repositories.repos', ['provider' => 'github']))
        ->assertStatus(502)
        ->assertJsonPath('reconectar', false);
});

test('disconnecting forgets the tokens of that account only', function (): void {
    $user = User::factory()->create();
    RepositoryConnection::factory()->for($user)->create();
    $other = RepositoryConnection::factory()->create();
    Passport::actingAs($user);

    $this->deleteJson(route('repositories.disconnect', ['provider' => 'github']))->assertNoContent();

    expect($user->repositoryConnections()->count())->toBe(0)
        ->and($other->fresh())->not->toBeNull();
});
