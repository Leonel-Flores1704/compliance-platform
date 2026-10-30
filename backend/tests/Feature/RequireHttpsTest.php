<?php

beforeEach(function () {
    config(['app.require_https' => false]);
});

it('rejects plain HTTP POST requests in production with 426 and exact message', function () {
    config(['app.require_https' => true]);

    $response = $this->postJson('http://localhost/api/auth/login', [
        'email' => 'user@compliance-platform.online',
        'password' => 'secret',
    ]);

    $response->assertStatus(426)
        ->assertExactJson([
            'message' => 'HTTPS is required.',
        ]);
});

it('rejects other non-GET plain HTTP methods with 426 in production', function (string $method) {
    config(['app.require_https' => true]);

    $response = match ($method) {
        'POST' => $this->post('http://localhost/api/health'),
        'PUT' => $this->put('http://localhost/api/health'),
        'PATCH' => $this->patch('http://localhost/api/health'),
        'DELETE' => $this->delete('http://localhost/api/health'),
    };

    $response->assertStatus(426)
        ->assertExactJson([
            'message' => 'HTTPS is required.',
        ]);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('redirects plain HTTP GET requests in production with 301 to https', function () {
    config(['app.require_https' => true]);

    $response = $this->get('http://compliance-platform.online/login');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toBe('https://compliance-platform.online/login');
});

it('allows non-GET requests when HTTPS is used in production', function () {
    config(['app.require_https' => true]);

    $response = $this->postJson('https://localhost/api/auth/login', [
        'email' => 'user@compliance-platform.online',
        'password' => 'secret',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Authenticated successfully.',
        ]);
});

it('recognizes X-Forwarded-Proto https from trusted reverse proxy as secure', function () {
    config(['app.require_https' => true]);

    $response = $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
    ])->postJson('http://localhost/api/auth/login');

    $response->assertStatus(200);
});

it('rejects X-Forwarded-Proto http from reverse proxy with 426 on non-GET', function () {
    config(['app.require_https' => true]);

    $response = $this->withHeaders([
        'X-Forwarded-Proto' => 'http',
        'X-Forwarded-Port' => '80',
    ])->postJson('http://localhost/api/auth/login');

    $response->assertStatus(426)
        ->assertExactJson([
            'message' => 'HTTPS is required.',
        ]);
});

it('allows plain HTTP requests in non-production local environments', function () {
    config(['app.require_https' => false]);

    $response = $this->postJson('http://localhost/api/auth/login');

    $response->assertStatus(200);
});
