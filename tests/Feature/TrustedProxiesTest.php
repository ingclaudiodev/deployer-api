<?php

use Illuminate\Support\Facades\URL;

/**
 * Production chain: GCLB appends "<client ip>, <gateway ip>" to whatever X-Forwarded-For the
 * client sent, HAProxy appends the GFE source ip, and the pod sees HAProxy as REMOTE_ADDR.
 */
beforeEach(function (): void {
    config(['trustedproxy.proxies' => '10.4.0.0/17,130.211.0.0/22,35.191.0.0/16,136.82.30.226']);
});

it('generates https urls when a trusted proxy forwards the https scheme', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.4.1.20'])
        ->get('/up', ['X-Forwarded-Proto' => 'https'])
        ->assertOk();

    expect(request()->isSecure())->toBeTrue()
        ->and(URL::to('/'))->toStartWith('https://')
        ->and(asset('build/app.css'))->toStartWith('https://');
});

it('keeps plain http when no forwarded scheme is present', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.4.1.20'])
        ->get('/up')
        ->assertOk();

    expect(request()->isSecure())->toBeFalse()
        ->and(URL::to('/'))->toStartWith('http://');
});

it('resolves the real client ip through the gateway, haproxy chain and ignores a spoofed prefix', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.4.1.20'])
        ->get('/up', ['X-Forwarded-For' => '198.51.100.7, 203.0.113.9, 136.82.30.226, 35.191.0.5'])
        ->assertOk();

    expect(request()->ip())->toBe('203.0.113.9');
});

it('ignores forwarded headers from an untrusted remote address', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/up', ['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])
        ->assertOk();

    expect(request()->ip())->toBe('203.0.113.50')
        ->and(request()->isSecure())->toBeFalse();
});

it('ignores a forwarded host so clients cannot poison generated urls', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.4.1.20'])
        ->get('/up', ['X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Proto' => 'https'])
        ->assertOk();

    expect(request()->getHost())->not->toBe('evil.test')
        ->and(URL::to('/'))->not->toContain('evil.test');
});

it('trusts no proxy when the setting is empty', function (): void {
    config(['trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.4.1.20'])
        ->get('/up', ['X-Forwarded-Proto' => 'https'])
        ->assertOk();

    expect(request()->isSecure())->toBeFalse();
});
