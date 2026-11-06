<?php

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->tempEnvPath = tempnam(sys_get_temp_dir(), 'test_env_');
    File::put($this->tempEnvPath, "APP_NAME=Laravel\nAPP_KEY=base64:R2zqUCchkf6aF2WZbcBeI0NpwrWzKsZ9WUJxRzp6T+4=\nDOCUMENT_ENCRYPTION_KEY=\n");
    app()->useEnvironmentPath(dirname($this->tempEnvPath));
    app()->loadEnvironmentFrom(basename($this->tempEnvPath));
});

afterEach(function (): void {
    if (file_exists($this->tempEnvPath)) {
        unlink($this->tempEnvPath);
    }
});

it('displays the key without modifying the file when using --show', function (): void {
    $initialContent = File::get($this->tempEnvPath);

    $this->artisan('documents:generate-key', ['--show' => true])
        ->expectsOutputToContain('=')
        ->assertExitCode(0);

    expect(File::get($this->tempEnvPath))->toBe($initialContent);
});

it('writes a valid 32-byte base64 encryption key different from APP_KEY to .env', function (): void {
    config(['documents.encryption_key' => '']);

    $this->artisan('documents:generate-key', ['--force' => true])
        ->assertExitCode(0);

    $updatedContent = File::get($this->tempEnvPath);
    expect($updatedContent)->toMatch('/^DOCUMENT_ENCRYPTION_KEY=[A-Za-z0-9+\/]{43}=$/m');

    preg_match('/^DOCUMENT_ENCRYPTION_KEY=(.*)$/m', $updatedContent, $matches);
    $generatedKey = $matches[1];

    $decoded = base64_decode($generatedKey, true);
    expect($decoded)->not->toBeFalse();
    expect(strlen($decoded))->toBe(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    expect(strlen($decoded))->toBe(32);

    // Verify it is different from APP_KEY
    expect($generatedKey)->not->toBe(config('app.key'));
    expect($generatedKey)->not->toBe('R2zqUCchkf6aF2WZbcBeI0NpwrWzKsZ9WUJxRzp6T+4=');
});
