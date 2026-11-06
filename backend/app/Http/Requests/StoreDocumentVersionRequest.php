<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentSecurityScanner;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Throwable;

class StoreDocumentVersionRequest extends FormRequest
{
    public const MAX_BYTES = 26214400; // Exactly 25 MiB (25 * 1024 * 1024)

    protected const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'txt'];

    protected const MIME_MAP = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $document = $this->route('document');

        if (! $user || ! $document instanceof Document) {
            return false;
        }

        if ($user->organization_id !== $document->organization_id) {
            return false;
        }

        if (! in_array($user->role, ['administrator', 'compliance_staff'], true)) {
            return false;
        }

        if ($document->is_archived) {
            return false;
        }

        return true;
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
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'regex:/^\d+(\.\d+){0,2}$/'],
            'expiration_date' => ['nullable', 'date_format:Y-m-d'],
            'file' => ['required'],
        ];
    }

    /**
     * Custom validation logic.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $document = $this->route('document');

            if ($validator->errors()->has('file')) {
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

            $realPath = $file->getRealPath();
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo ? finfo_file($finfo, $realPath) : null;
            if ($finfo) {
                finfo_close($finfo);
            }

            $scanner = app(DocumentSecurityScanner::class);

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
                $expectedMime = self::MIME_MAP[$extension] ?? null;
                if ($detectedMime !== $expectedMime) {
                    $validator->errors()->add('file', "The file content does not match its extension (.{$extension}).");

                    return;
                }

                $maliciousError = $scanner->scan($file, $extension);
                if ($maliciousError !== null) {
                    $validator->errors()->add('file', $maliciousError);

                    return;
                }
            }

            // Check if version label already exists for this document
            if ($document instanceof Document && $this->filled('version') && ! $validator->errors()->has('version')) {
                $versionLabel = (string) $this->input('version');
                $exists = DocumentVersion::where('document_id', $document->id)
                    ->where('version_label', $versionLabel)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('version', "Version {$versionLabel} already exists for this document.");
                }
            }

            // Check expiration date requirement based on document category
            if ($document instanceof Document && in_array($document->category, StoreDocumentRequest::CATEGORIES_REQUIRING_EXPIRATION, true)) {
                if (! $this->filled('expiration_date')) {
                    $catName = StoreDocumentRequest::CATEGORY_NAMES[$document->category] ?? 'Permit/License';
                    $validator->errors()->add('expiration_date', "Expiration date is required for {$catName} documents.");
                }
            }

            // Future date check
            if ($this->filled('expiration_date') && ! $validator->errors()->has('expiration_date')) {
                try {
                    $expDate = Carbon::createFromFormat('Y-m-d', (string) $this->input('expiration_date'))->startOfDay();
                    $today = now()->startOfDay();
                    if ($expDate->lessThanOrEqualTo($today)) {
                        $validator->errors()->add('expiration_date', 'Expiration date must be a future date.');
                    }
                } catch (Throwable) {
                    $validator->errors()->add('expiration_date', 'Expiration date must be a future date.');
                }
            }
        });
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Version is required.',
            'version.regex' => 'Version must use the format 1, 1.0 or 1.0.0.',
            'file.required' => 'Select a file to upload.',
            'expiration_date.date_format' => 'Expiration date must be a future date.',
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
