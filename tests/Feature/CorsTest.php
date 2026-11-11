<?php

/**
 * On Cloud Run nothing sits in front of the container, so the framework answers CORS itself
 * (config/cors.php) instead of HAProxy.
 */
beforeEach(function (): void {
    config(['cors.allowed_origins' => ['https://deployer.cl']]);
});

it('allows the preflight from the frontend origin', function (): void {
    $response = $this->call('OPTIONS', '/api/auth/login', server: [
        'HTTP_ORIGIN' => 'https://deployer.cl',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type',
    ]);

    $response->assertNoContent();
    $response->assertHeader('Access-Control-Allow-Origin', 'https://deployer.cl');
    $response->assertHeader('Access-Control-Max-Age', (string) config('cors.max_age'));
});

it('does not allow other origins', function (): void {
    $response = $this->call('OPTIONS', '/api/auth/login', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});

it('adds the allow-origin header to api responses', function (): void {
    $response = $this->getJson('/api/auth/me', ['Origin' => 'https://deployer.cl']);

    $response->assertUnauthorized();
    $response->assertHeader('Access-Control-Allow-Origin', 'https://deployer.cl');
});
