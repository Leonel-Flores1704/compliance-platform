<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.ai.url' => 'http://ai-service:8001']);
});

it('returns ok when database and ai service are healthy', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

    $this->getJson('/api/health')
        ->assertStatus(200)
        ->assertExactJson([
            'status' => 'ok',
            'database' => 'ok',
            'ai_service' => 'ok',
        ]);
});

it('returns 503 when the database is unreachable', function () {
    DB::shouldReceive('connection')->andThrow(new PDOException('connection refused'));

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'error',
            'database' => 'unreachable',
        ]);
});

it('returns 503 when the ai service is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('ai-service down'));

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'degraded',
            'database' => 'ok',
            'ai_service' => 'unreachable',
        ]);
});
