<?php

namespace App\Services;

use App\Models\DocumentVersion;
use App\Services\Exceptions\DocumentDecryptionException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DocumentEncryptionService
{
    /**
     * Get the binary encryption key from configuration.
     */
    public function getEncryptionKey(): string
    {
        $rawKey = config('documents.encryption_key');

        if (empty($rawKey)) {
            throw new RuntimeException('DOCUMENT_ENCRYPTION_KEY is not configured.');
        }

        if (str_starts_with($rawKey, 'base64:')) {
            $rawKey = substr($rawKey, 7);
        }

        $key = base64_decode($rawKey, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('DOCUMENT_ENCRYPTION_KEY must be a valid base64-encoded 32-byte key.');
        }

        return $key;
    }

    /**
     * Encrypt a local source file and stream the ciphertext to the documents disk.
     */
    public function encryptFile(string $sourceFilePath, string $storagePath): void
    {
        $key = $this->getEncryptionKey();
        $chunkSize = (int) config('documents.chunk_size', 65536);

        $source = fopen($sourceFilePath, 'rb');
        if ($source === false) {
            throw new RuntimeException("Cannot open source file for encryption: {$sourceFilePath}");
        }

        $tempEncrypted = fopen('php://temp', 'w+b');
        if ($tempEncrypted === false) {
            fclose($source);
            throw new RuntimeException('Failed to create temporary stream for encryption.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($tempEncrypted, $header);

            $chunk = fread($source, $chunkSize);

            if ($chunk === '' || $chunk === false) {
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    '',
                    '',
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                );
                fwrite($tempEncrypted, $cipher);
            } else {
                while ($chunk !== '' && $chunk !== false) {
                    $next = fread($source, $chunkSize);
                    $isFinal = ($next === '' || $next === false);
                    $tag = $isFinal
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                    fwrite($tempEncrypted, $cipher);

                    $chunk = $next;
                }
            }

            rewind($tempEncrypted);
            $this->putEncryptedStream($storagePath, $tempEncrypted);
        } finally {
            fclose($source);
            if (is_resource($tempEncrypted)) {
                fclose($tempEncrypted);
            }
        }
    }

    /**
     * Decrypt an encrypted document version as a stream and verify its SHA-256.
     *
     * @return resource Decrypted temporary stream positioned at offset 0
     *
     * @throws DocumentDecryptionException
     */
    public function decryptAndVerify(DocumentVersion $version)
    {
        $key = $this->getEncryptionKey();
        $chunkSize = (int) config('documents.chunk_size', 65536);
        $cipherChunkSize = $chunkSize + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        $cipherStream = $this->getEncryptedStream($version->storage_path);
        if ($cipherStream === null) {
            throw new DocumentDecryptionException("Stored encrypted file not found for path: {$version->storage_path}");
        }

        $header = fread($cipherStream, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($header === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            fclose($cipherStream);
            throw new DocumentDecryptionException('Invalid or truncated secretstream header.');
        }

        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            if ($state === false) {
                throw new DocumentDecryptionException('Failed to initialize secretstream decryption.');
            }
        } catch (Throwable $e) {
            fclose($cipherStream);
            throw new DocumentDecryptionException('Failed to initialize secretstream decryption: '.$e->getMessage(), 0, $e);
        }

        $tempDecrypted = fopen('php://temp', 'w+b');
        if ($tempDecrypted === false) {
            fclose($cipherStream);
            throw new DocumentDecryptionException('Failed to create temporary stream for decryption.');
        }

        $hashCtx = hash_init('sha256');
        $finalReached = false;

        try {
            while (! feof($cipherStream)) {
                $cipherChunk = fread($cipherStream, $cipherChunkSize);
                if ($cipherChunk === '' || $cipherChunk === false) {
                    break;
                }

                $pullResult = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipherChunk);
                if ($pullResult === false) {
                    throw new DocumentDecryptionException('Chunk authentication failed or ciphertext corrupted.');
                }

                [$plainChunk, $tag] = $pullResult;
                hash_update($hashCtx, $plainChunk);
                fwrite($tempDecrypted, $plainChunk);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finalReached = true;
                    break;
                }
            }

            if (! $finalReached) {
                throw new DocumentDecryptionException('Stream ended before final chunk tag was encountered.');
            }

            $computedSha256 = hash_final($hashCtx);
            if (! hash_equals($version->sha256, $computedSha256)) {
                throw new DocumentDecryptionException("SHA-256 verification mismatch: expected {$version->sha256}, got {$computedSha256}");
            }

            rewind($tempDecrypted);

            return $tempDecrypted;
        } catch (Throwable $e) {
            fclose($tempDecrypted);
            if ($e instanceof DocumentDecryptionException) {
                throw $e;
            }
            throw new DocumentDecryptionException('Decryption error: '.$e->getMessage(), 0, $e);
        } finally {
            fclose($cipherStream);
        }
    }

    /**
     * Store encrypted stream to disk.
     *
     * @param  resource  $stream
     */
    public function putEncryptedStream(string $storagePath, $stream): void
    {
        Storage::disk('documents')->put($storagePath, $stream);
    }

    /**
     * Retrieve read stream for an encrypted file from the documents disk.
     *
     * @return resource|null
     */
    public function getEncryptedStream(string $storagePath)
    {
        $disk = Storage::disk('documents');

        if ($disk->exists($storagePath)) {
            return $disk->readStream($storagePath);
        }

        $stripped = preg_replace('#^documents/#', '', $storagePath);
        if ($stripped !== $storagePath && $disk->exists($stripped)) {
            return $disk->readStream($stripped);
        }

        $prepended = 'documents/'.ltrim($storagePath, '/');
        if ($disk->exists($prepended)) {
            return $disk->readStream($prepended);
        }

        return null;
    }

    /**
     * Delete stored encrypted file(s) on rollback.
     */
    public function delete(string $storagePath): void
    {
        $disk = Storage::disk('documents');
        if ($disk->exists($storagePath)) {
            $disk->delete($storagePath);
        }

        $stripped = preg_replace('#^documents/#', '', $storagePath);
        if ($stripped !== $storagePath && $disk->exists($stripped)) {
            $disk->delete($stripped);
        }
    }
}
