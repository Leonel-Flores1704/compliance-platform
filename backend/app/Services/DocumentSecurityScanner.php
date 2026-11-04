<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use ZipArchive;

class DocumentSecurityScanner
{
    public const MALICIOUS_CONTENT_MESSAGE = 'The file was rejected because it contains executable content.';

    /**
     * Inspect the uploaded file for malicious content.
     * Returns null if clean, or an error message if rejected.
     */
    public function scan(UploadedFile $file, string $extension): ?string
    {
        $path = $file->getRealPath();
        if (! $path || ! file_exists($path)) {
            return null;
        }

        // 1. Executable and script headers check (all file types)
        if ($this->hasExecutableSignature($path)) {
            return self::MALICIOUS_CONTENT_MESSAGE;
        }

        // 2. Format-specific inspections
        $ext = strtolower($extension);

        if ($ext === 'docx') {
            if ($this->hasDocxMacros($path)) {
                return self::MALICIOUS_CONTENT_MESSAGE;
            }
        } elseif ($ext === 'pdf') {
            if ($this->hasPdfMaliciousActions($path)) {
                return self::MALICIOUS_CONTENT_MESSAGE;
            }
        }

        return null;
    }

    /**
     * Reject files that start with MZ (Windows PE), 7F 45 4C 46 (ELF), or #! (shebang script).
     * Handles UTF-8 BOM (\xEF\xBB\xBF) and leading whitespace for scripts.
     */
    public function hasExecutableSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return false;
        }

        $header = fread($handle, 64);
        fclose($handle);

        if ($header === false || strlen($header) < 2) {
            return false;
        }

        // Windows PE (MZ - 4D 5A or legacy ZM - 5A 4D)
        if (str_starts_with($header, 'MZ') || str_starts_with($header, 'ZM')) {
            return true;
        }

        // Linux ELF (7F 45 4C 46)
        if (strlen($header) >= 4 && str_starts_with($header, "\x7FELF")) {
            return true;
        }

        // Script shebang (#!) with BOM or leading whitespace tolerance
        $cleaned = $header;
        if (str_starts_with($cleaned, "\xEF\xBB\xBF")) {
            $cleaned = substr($cleaned, 3);
        }
        $trimmed = ltrim($cleaned);
        if (str_starts_with($trimmed, '#!')) {
            return true;
        }

        return false;
    }

    /**
     * For DOCX files: inspect the ZIP archive for macros, VBA projects, and custom macro relationships.
     */
    public function hasDocxMacros(string $path): bool
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($path);

        if ($openResult !== true) {
            // Corrupt or non-standard ZIP archive
            return true;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if ($entryName === false) {
                    continue;
                }

                $lower = strtolower(str_replace('\\', '/', $entryName));

                // Direct file name matching
                if ($lower === 'word/vbaproject.bin' || str_ends_with($lower, '/vbaproject.bin') || $lower === 'vbaproject.bin') {
                    return true;
                }

                // Check [Content_Types].xml for macro types
                if ($lower === '[content_types].xml') {
                    $content = (string) $zip->getFromIndex($i);
                    if (stripos($content, 'vbaProject') !== false || stripos($content, 'macroEnabled') !== false) {
                        return true;
                    }
                }

                // Check relationship definitions for vbaProject targets
                if (str_ends_with($lower, '.rels')) {
                    $content = (string) $zip->getFromIndex($i);
                    if (stripos($content, 'relationships/vbaProject') !== false || stripos($content, 'relationships/macro') !== false) {
                        return true;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return false;
    }

    /**
     * For PDF files: reject documents containing /JavaScript or /Launch actions,
     * including actions compressed inside Object Streams (/ObjStm) or XFA scripts.
     */
    public function hasPdfMaliciousActions(string $path): bool
    {
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return false;
        }

        // Normalize PDF name tokens by decoding hex escapes (e.g., #4a#61#76#61#53#63#72#69#70#74 -> JavaScript)
        $normalized = $this->normalizePdfNames($content);

        // Strip literal streams for initial structural scan
        $structureOnly = preg_replace('/stream[\r\n]+.*?[\r\n]+endstream/s', '', $normalized);

        // Check for /JavaScript or /Launch in structural dictionaries/objects
        if (preg_match('/\/(?:JavaScript|Launch)\b/i', $structureOnly)) {
            return true;
        }

        // Check if action dictionaries appear in uncompressed content
        if (preg_match('/\/S\s*\/(?:JavaScript|Launch)\b/i', $normalized)) {
            return true;
        }

        // Check raw /JavaScript or /Launch names in normalized content
        if (preg_match('/\/(?:JavaScript|Launch)\b/i', $normalized)) {
            return true;
        }

        // Inspect and decompress Object Streams (/ObjStm) and FlateDecode streams
        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $streamMatches)) {
            foreach ($streamMatches[1] as $streamBody) {
                $decompressed = @gzuncompress($streamBody);
                if ($decompressed === false) {
                    $decompressed = @zlib_decode($streamBody);
                }

                if ($decompressed !== false && $decompressed !== '') {
                    $decompNormalized = $this->normalizePdfNames($decompressed);
                    if (preg_match('/\/(?:JavaScript|Launch)\b/i', $decompNormalized)) {
                        return true;
                    }
                    if (preg_match('/<script\b/i', $decompressed)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Decodes PDF name token hex characters (#XX) to their ASCII equivalents.
     */
    protected function normalizePdfNames(string $content): string
    {
        return (string) preg_replace_callback('/\/[^\s\<\>\(\)\[\]\{\}\/\%]+/', function ($matches) {
            return preg_replace_callback('/#([0-9a-fA-F]{2})/', function ($hexMatch) {
                return chr(hexdec($hexMatch[1]));
            }, $matches[0]);
        }, $content);
    }
}
