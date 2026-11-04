<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Services\DocumentSecurityScanner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public const MAX_BYTES = 26214400; // Exactly 25 MiB (25 * 1024 * 1024)

    protected const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'txt'];

    protected const MIME_MAP = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
    ];

    protected const CATEGORIES_REQUIRING_EXPIRATION = ['permit_license', 'certificate'];

    protected const VALID_CATEGORIES = [
        'permit_license',
        'policy',
        'contract',
        'certificate',
        'report',
        'regulation',
        'other',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('create', Document::class);
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'You do not have permission to upload documents.',
        ], 403));
    }

    /**
     * Prepare the data for validation.
     * Check for oversized requests that PHP INI or Content-Length would reject.
     */
    protected function prepareForValidation(): void
    {
        $contentLength = (int) $this->server('CONTENT_LENGTH', 0);
        if ($contentLength > self::MAX_BYTES) {
            throw new HttpResponseException(response()->json([
                'message' => 'The file exceeds the 25 MB limit (26,214,400 bytes).',
                'errors' => [
                    'file' => ['The file exceeds the 25 MB limit (26,214,400 bytes).'],
                ],
            ], 422));
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::in(self::VALID_CATEGORIES)],
            'access_level' => ['required', 'string', Rule::in(['all_staff', 'compliance_only'])],
            'expiration_date' => [
                Rule::requiredIf(fn () => in_array($this->input('category'), self::CATEGORIES_REQUIRING_EXPIRATION, true)),
                'nullable',
                'date_format:Y-m-d',
            ],
            'file' => ['required'],
        ];
    }

    /**
     * Custom validation logic for file security, extension, MIME, and size.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('file')) {
                // If standard required rule failed, customize message
                $validator->errors()->forget('file');
                $validator->errors()->add('file', 'Select a file to upload.');

                return;
            }

            $file = $this->file('file');
            if (! $file || ! $file->isValid()) {
                if ($file && $file->getError() === UPLOAD_ERR_INI_SIZE) {
                    $validator->errors()->add('file', 'The file exceeds the 25 MB limit (26,214,400 bytes).');

                    return;
                }
                $validator->errors()->add('file', 'Select a file to upload.');

                return;
            }

            $size = $file->getSize();
            if ($size === 0) {
                $validator->errors()->add('file', 'The file is empty.');

                return;
            }

            if ($size > self::MAX_BYTES) {
                $validator->errors()->add('file', 'The file exceeds the 25 MB limit (26,214,400 bytes).');

                return;
            }

            $extension = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $validator->errors()->add('file', 'Only PDF, DOCX and TXT files are allowed.');

                return;
            }

            // Detect MIME from actual content using finfo
            $realPath = $file->getRealPath();
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo ? finfo_file($finfo, $realPath) : null;
            if ($finfo) {
                finfo_close($finfo);
            }

            $scanner = app(DocumentSecurityScanner::class);

            // For TXT files, check malicious executable / script signatures first
            if ($extension === 'txt') {
                $maliciousError = $scanner->scan($file, $extension);
                if ($maliciousError !== null) {
                    $validator->errors()->add('file', $maliciousError);

                    return;
                }

                if ($detectedMime !== self::MIME_MAP['txt']) {
                    $validator->errors()->add('file', 'The file content does not match its extension (.txt).');

                    return;
                }
            } else {
                // For PDF and DOCX, check extension and MIME match first
                $expectedMime = self::MIME_MAP[$extension] ?? null;
                if ($detectedMime !== $expectedMime) {
                    $validator->errors()->add('file', "The file content does not match its extension (.{$extension}).");

                    return;
                }

                // Format-specific malicious content checks (PDF JS/Launch, DOCX macros, executable signatures)
                $maliciousError = $scanner->scan($file, $extension);
                if ($maliciousError !== null) {
                    $validator->errors()->add('file', $maliciousError);

                    return;
                }
            }
        });
    }

    /**
     * Set custom validation messages.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Select a file to upload.',
            'expiration_date.required' => 'Expiration date is required for this category.',
        ];
    }

    /**
     * Handle a failed validation attempt and format response with exact strings.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->messages();
        $firstMessage = $validator->errors()->first();

        throw new HttpResponseException(response()->json([
            'message' => $firstMessage,
            'errors' => $errors,
        ], 422));
    }
}
