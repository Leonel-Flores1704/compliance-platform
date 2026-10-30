<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class SanitizedLineFormatter extends LineFormatter
{
    /**
     * Format a log record while ensuring no sensitive personal or authentication values leak.
     */
    public function format(LogRecord $record): string
    {
        $sanitizedRecord = (new SanitizeSensitiveDataProcessor)($record);

        $line = parent::format($sanitizedRecord);

        // Final safety layer: redact any leftover sensitive patterns from the formatted string
        $line = (string) preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED]', $line);
        $line = (string) preg_replace('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{4}/', '[REDACTED]', $line);
        $line = (string) preg_replace('/(password(?:_confirmation)?\s*[:=]\s*)[^\s,]+/i', '$1[REDACTED]', $line);

        return $line;
    }
}
