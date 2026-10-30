<?php

use App\Services\AiServiceClient;
use App\Services\Exceptions\AiServiceEmbeddingFailedException;
use App\Services\Exceptions\AiServiceException;
use App\Services\Exceptions\AiServiceExtractionFailedException;
use App\Services\Exceptions\AiServiceLlmTimeoutException;
use App\Services\Exceptions\AiServiceOcrFailedException;
use App\Services\Exceptions\AiServiceProviderNotAuthorizedException;
use App\Services\Exceptions\AiServiceUnauthorizedException;
use App\Services\Exceptions\AiServiceUnsupportedFileTypeException;
use App\Services\Exceptions\AiServiceValidationException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'services.ai.url' => 'http://ai-service:8001',
        'services.ai.token' => 'test-secret-token',
    ]);
});

it('fetches health status successfully with authentication header', function () {
    Http::fake([
        'http://ai-service:8001/health' => Http::response([
            'status' => 'ok',
            'vector_store' => 'ok',
            'llm_provider' => 'ok',
        ], 200),
    ]);

    $client = new AiServiceClient;
    $result = $client->health();

    expect($result)->toBe([
        'status' => 'ok',
        'vector_store' => 'ok',
        'llm_provider' => 'ok',
    ]);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://ai-service:8001/health'
            && $request->method() === 'GET'
            && $request->header('X-Internal-Token') === ['test-secret-token'];
    });
});

it('ingests a document successfully with multipart payload', function () {
    Http::fake([
        'http://ai-service:8001/v1/documents/ingest' => Http::response([
            'status' => 'processed',
            'chunks' => 42,
            'pages' => 12,
            'ocr_used' => false,
        ], 200),
    ]);

    $client = new AiServiceClient;
    $fakeFile = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');

    $result = $client->ingest(
        file: $fakeFile,
        organizationId: 1,
        documentId: 10,
        documentVersionId: 1,
        mimeType: 'application/pdf',
        accessLevel: 'confidential'
    );

    expect($result)->toBe([
        'status' => 'processed',
        'chunks' => 42,
        'pages' => 12,
        'ocr_used' => false,
    ]);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://ai-service:8001/v1/documents/ingest'
            && $request->method() === 'POST'
            && $request->isMultipart()
            && $request->header('X-Internal-Token') === ['test-secret-token'];
    });
});

it('sets current version successfully', function () {
    Http::fake([
        'http://ai-service:8001/v1/documents/10/set-current' => Http::response([
            'status' => 'ok',
        ], 200),
    ]);

    $client = new AiServiceClient;
    $result = $client->setCurrent(documentId: 10, documentVersionId: 7);

    expect($result)->toBe(['status' => 'ok']);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://ai-service:8001/v1/documents/10/set-current'
            && $request->method() === 'POST'
            && $request['document_version_id'] === 7
            && $request->header('X-Internal-Token') === ['test-secret-token'];
    });
});

it('asks a question and returns citations and answer', function () {
    $expectedResponse = [
        'answer' => 'Compliance records must be stored for 7 years.',
        'status' => 'answered',
        'citations' => [
            [
                'document_id' => 10,
                'document_version_id' => 1,
                'document_name' => 'policy.pdf',
                'page' => 3,
                'section' => 'Data Retention',
                'chunk_id' => 'chunk_1',
                'score' => 0.95,
            ],
        ],
        'disclaimer' => 'AI generated answer.',
        'latency_ms' => 350,
    ];

    Http::fake([
        'http://ai-service:8001/v1/ask' => Http::response($expectedResponse, 200),
    ]);

    $client = new AiServiceClient;
    $requestId = (string) Str::uuid();

    $result = $client->ask(
        question: 'What is the retention period?',
        userId: 1,
        organizationId: 2,
        allowedDocumentIds: [10, 11],
        requestId: $requestId
    );

    expect($result)->toBe($expectedResponse);

    Http::assertSent(function (Request $request) use ($requestId) {
        return $request->url() === 'http://ai-service:8001/v1/ask'
            && $request->method() === 'POST'
            && $request['question'] === 'What is the retention period?'
            && $request['user_id'] === 1
            && $request['organization_id'] === 2
            && $request['allowed_document_ids'] === [10, 11]
            && $request['request_id'] === $requestId
            && $request->header('X-Internal-Token') === ['test-secret-token'];
    });
});

// Error mapping tests for all CMP-07 exact error codes
it('maps 401 UNAUTHORIZED to AiServiceUnauthorizedException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'UNAUTHORIZED',
            'message' => 'Invalid internal token.',
        ], 401),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->health())
        ->toThrow(function (AiServiceUnauthorizedException $e) {
            expect($e->getMessage())->toBe('Invalid internal token.')
                ->and($e->getErrorCode())->toBe('UNAUTHORIZED')
                ->and($e->getStatusCode())->toBe(401);
        });
});

it('maps 422 VALIDATION_ERROR to AiServiceValidationException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'VALIDATION_ERROR',
            'message' => 'question: String should have at least 3 characters',
        ], 422),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->ask('hi', 1, 1, [1], (string) Str::uuid()))
        ->toThrow(function (AiServiceValidationException $e) {
            expect($e->getMessage())->toBe('question: String should have at least 3 characters')
                ->and($e->getErrorCode())->toBe('VALIDATION_ERROR')
                ->and($e->getStatusCode())->toBe(422);
        });
});

it('maps 415 UNSUPPORTED_FILE_TYPE to AiServiceUnsupportedFileTypeException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'UNSUPPORTED_FILE_TYPE',
            'message' => 'Only PDF, DOCX and TXT files can be processed.',
        ], 415),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->ingest('test.zip', 1, 1, 1, 'application/zip', 'confidential'))
        ->toThrow(function (AiServiceUnsupportedFileTypeException $e) {
            expect($e->getMessage())->toBe('Only PDF, DOCX and TXT files can be processed.')
                ->and($e->getErrorCode())->toBe('UNSUPPORTED_FILE_TYPE')
                ->and($e->getStatusCode())->toBe(415);
        });
});

it('maps 422 EXTRACTION_FAILED to AiServiceExtractionFailedException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'EXTRACTION_FAILED',
            'message' => 'The file could not be read. It may be damaged or password-protected.',
        ], 422),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->ingest('corrupt.pdf', 1, 1, 1, 'application/pdf', 'confidential'))
        ->toThrow(function (AiServiceExtractionFailedException $e) {
            expect($e->getMessage())->toBe('The file could not be read. It may be damaged or password-protected.')
                ->and($e->getErrorCode())->toBe('EXTRACTION_FAILED')
                ->and($e->getStatusCode())->toBe(422);
        });
});

it('maps 422 OCR_FAILED to AiServiceOcrFailedException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'OCR_FAILED',
            'message' => 'The scanned document could not be read.',
        ], 422),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->ingest('scan.pdf', 1, 1, 1, 'application/pdf', 'confidential'))
        ->toThrow(function (AiServiceOcrFailedException $e) {
            expect($e->getMessage())->toBe('The scanned document could not be read.')
                ->and($e->getErrorCode())->toBe('OCR_FAILED')
                ->and($e->getStatusCode())->toBe(422);
        });
});

it('maps 502 EMBEDDING_FAILED to AiServiceEmbeddingFailedException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'EMBEDDING_FAILED',
            'message' => 'The document could not be indexed.',
        ], 502),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->ingest('test.pdf', 1, 1, 1, 'application/pdf', 'confidential'))
        ->toThrow(function (AiServiceEmbeddingFailedException $e) {
            expect($e->getMessage())->toBe('The document could not be indexed.')
                ->and($e->getErrorCode())->toBe('EMBEDDING_FAILED')
                ->and($e->getStatusCode())->toBe(502);
        });
});

it('maps 503 PROVIDER_NOT_AUTHORIZED to AiServiceProviderNotAuthorizedException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'PROVIDER_NOT_AUTHORIZED',
            'message' => 'The AI provider is not authorized.',
        ], 503),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->ask('Test query', 1, 1, [1], (string) Str::uuid()))
        ->toThrow(function (AiServiceProviderNotAuthorizedException $e) {
            expect($e->getMessage())->toBe('The AI provider is not authorized.')
                ->and($e->getErrorCode())->toBe('PROVIDER_NOT_AUTHORIZED')
                ->and($e->getStatusCode())->toBe(503);
        });
});

it('maps 504 LLM_TIMEOUT to AiServiceLlmTimeoutException', function () {
    Http::fake([
        '*' => Http::response([
            'error_code' => 'LLM_TIMEOUT',
            'message' => 'The language model did not respond in time.',
        ], 504),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->ask('Test query', 1, 1, [1], (string) Str::uuid()))
        ->toThrow(function (AiServiceLlmTimeoutException $e) {
            expect($e->getMessage())->toBe('The language model did not respond in time.')
                ->and($e->getErrorCode())->toBe('LLM_TIMEOUT')
                ->and($e->getStatusCode())->toBe(504);
        });
});

it('maps unexpected non-json server error to generic AiServiceException', function () {
    Http::fake([
        '*' => Http::response('Gateway Timeout HTML body', 504),
    ]);

    $client = new AiServiceClient;

    expect(fn () => $client->health())
        ->toThrow(function (AiServiceException $e) {
            expect($e->getStatusCode())->toBe(504);
        });
});
