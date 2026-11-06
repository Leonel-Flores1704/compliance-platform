<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Document Encryption Key
    |--------------------------------------------------------------------------
    |
    | Base64-encoded 32-byte key used for libsodium streaming encryption
    | (sodium_crypto_secretstream_xchacha20poly1305).
    |
    */
    'encryption_key' => env('DOCUMENT_ENCRYPTION_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Chunk Size
    |--------------------------------------------------------------------------
    |
    | Size in bytes of each chunk during streaming encryption and decryption.
    | Default is 64 KiB (65536 bytes).
    |
    */
    'chunk_size' => (int) env('DOCUMENT_ENCRYPTION_CHUNK_SIZE', 65536),
];
