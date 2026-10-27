<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class SanitizeSensitiveDataProcessor implements ProcessorInterface
{
    /**
     * Context and extra keys that must be redacted.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'phone',
        'email',
        'secret',
        'token',
        'legal_representative_name',
        'tax_id',
        'rfc',
        'fiscal_address',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->sanitizeString($record->message),
            context: $this->sanitizeData($record->context),
            extra: $this->sanitizeData($record->extra)
        );
    }

    private function sanitizeData(mixed $data): mixed
    {
        if (! is_array($data)) {
            return is_string($data) ? $this->sanitizeString($data) : $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            $isSensitiveKey = is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true);

            if ($isSensitiveKey) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            $sanitized[$key] = $this->sanitizeData($value);
        }

        return $sanitized;
    }

    private function sanitizeString(string $value): string
    {
        // Redact email addresses
        $value = (string) preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED]', $value);

        // Redact telephone numbers (10 digits or international formats)
        $value = (string) preg_replace('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{4}/', '[REDACTED]', $value);

        // Redact password assignments
        $value = (string) preg_replace('/(password(?:_confirmation)?\s*[:=]\s*)[^\s,]+/i', '$1[REDACTED]', $value);

        return $value;
    }
}
