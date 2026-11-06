<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class GenerateDocumentEncryptionKey extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:generate-key
                    {--show : Display the key instead of modifying files}
                    {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the document encryption key';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $key = $this->generateRandomKey();

        if ($this->option('show')) {
            $this->line('<comment>'.$key.'</comment>');

            return self::SUCCESS;
        }

        if (! $this->setKeyInEnvironmentFile($key)) {
            return self::FAILURE;
        }

        $this->components->info('Document encryption key set successfully.');

        return self::SUCCESS;
    }

    /**
     * Generate a 32-byte cryptographically secure random key, base64-encoded.
     */
    protected function generateRandomKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Set the document encryption key in the environment file.
     */
    protected function setKeyInEnvironmentFile(string $key): bool
    {
        $currentKey = config('documents.encryption_key');

        if (strlen($currentKey) !== 0 && (! $this->confirmToProceed())) {
            return false;
        }

        if (! $this->writeNewEnvironmentFileWith($key)) {
            return false;
        }

        return true;
    }

    /**
     * Write a new environment file with the given key.
     */
    protected function writeNewEnvironmentFileWith(string $key): bool
    {
        $envFilePath = $this->laravel->environmentFilePath();

        if (! file_exists($envFilePath)) {
            $this->error("Environment file {$envFilePath} does not exist.");

            return false;
        }

        $contents = file_get_contents($envFilePath);

        if (preg_match('/^DOCUMENT_ENCRYPTION_KEY=.*$/m', $contents)) {
            $contents = preg_replace(
                '/^DOCUMENT_ENCRYPTION_KEY=.*$/m',
                'DOCUMENT_ENCRYPTION_KEY='.$key,
                $contents
            );
        } else {
            $contents = rtrim($contents, "\r\n").PHP_EOL.'DOCUMENT_ENCRYPTION_KEY='.$key.PHP_EOL;
        }

        file_put_contents($envFilePath, $contents);

        return true;
    }
}
