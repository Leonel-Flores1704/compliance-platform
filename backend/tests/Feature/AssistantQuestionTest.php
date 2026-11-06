<?php

use App\Models\AiQuestion;
use App\Models\Organization;
use App\Models\User;
use App\Support\AssistantMessages as M;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

const AI_ASK_URL = 'http://ai-service.test/v1/ask';

beforeEach(function (): void {
    config(['services.ai.url' => 'http://ai-service.test', 'services.ai.token' => 'test-token']);
    RateLimiter::clear('assistant:1');
    $this->seed(CatalogSeeder::class);

    $makeOrganization = fn (string $name) => Organization::query()->create([
        'legal_name' => $name, 'organization_type' => 'private_company', 'industry' => 'manufacturing',
        'state' => '10', 'municipality' => 'Durango', 'employees_count' => 120, 'business_activities' => ['31-33'],
    ]);
    $this->organization = $makeOrganization('Aceros del Guadiana, S.A. de C.V.');
    $other = $makeOrganization('Otra Empresa, S.A. de C.V.');

    $makeUser = fn (string $role, string $email, Organization $org) => User::query()->create([
        'organization_id' => $org->id, 'name' => ucwords(str_replace('_', ' ', $role)),
        'email' => $email, 'password' => 'password', 'role' => $role,
    ]);
    $this->staff = $makeUser('compliance_staff', 'staff@example.test', $this->organization);
    $this->employee = $makeUser('general_employee', 'employee@example.test', $this->organization);
    $this->outsider = $makeUser('compliance_staff', 'outsider@example.test', $other);

    $makeDocument = function (string $name, string $access, string $status, bool $archived = false, ?Organization $org = null): int {
        $id = DB::table('documents')->insertGetId([
            'organization_id' => ($org ?? $this->organization)->id, 'name' => $name, 'category' => 'policy',
            'access_level' => $access, 'is_archived' => $archived, 'created_by' => $this->staff->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('document_versions')->insert([
            'document_id' => $id, 'version_label' => '1.0', 'storage_path' => "documents/{$id}.bin",
            'original_filename' => "{$name}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64), 'status' => $status, 'is_current' => true,
            'uploaded_by' => $this->staff->id, 'uploaded_at' => now(),
        ]);

        return $id;
    };
    $this->docs = [
        'all_staff' => $makeDocument('Code of conduct', 'all_staff', 'processed'),
        'compliance_only' => $makeDocument('Internal audit', 'compliance_only', 'processed'),
        'archived' => $makeDocument('Old policy', 'all_staff', 'processed', archived: true),
        'failed' => $makeDocument('Broken scan', 'all_staff', 'processing_failed'),
        'other_org' => $makeDocument('Other company policy', 'all_staff', 'processed', org: $other),
    ];
});

function fakeAnswer(): array
{
    return [
        'answer' => 'Records must be kept for **5 years**.',
        'status' => 'answered',
        'citations' => [[
            'document_id' => 1, 'document_version_id' => 1, 'document_name' => 'Code of conduct',
            'page' => 4, 'section' => '4.2 Retention', 'chunk_id' => 'chunk_101', 'score' => 0.91,
        ]],
        'disclaimer' => 'This answer is not legal advice.',
        'latency_ms' => 450,
    ];
}

describe('validation messages', function (): void {
    it('rejects the question with the exact message and does not call the AI service', function (mixed $question, string $message): void {
        Http::fake();

        $this->actingAs($this->staff)
            ->postJson('/api/assistant/questions', ['question' => $question])
            ->assertStatus(422)
            ->assertJsonPath('errors.question.0', $message);

        Http::assertNothingSent();
        expect(AiQuestion::query()->count())->toBe(0);
    })->with([
        'empty' => ['', M::QUESTION_REQUIRED],
        'only spaces' => ['     ', M::QUESTION_REQUIRED],
        'missing' => [null, M::QUESTION_REQUIRED],
        'shorter than 3 characters' => ['Hi', M::QUESTION_TOO_SHORT],
        'longer than 1,000 characters' => [str_repeat('a', 1001), M::QUESTION_TOO_LONG],
    ]);

    it('accepts 3 and 1,000 characters', function (string $question): void {
        Http::fake([AI_ASK_URL => Http::response(fakeAnswer())]);

        $this->actingAs($this->staff)->postJson('/api/assistant/questions', ['question' => $question])->assertCreated();
    })->with(['3 characters' => 'Why', '1,000 characters' => str_repeat('a', 1000)]);
});

describe('AI service errors', function (): void {
    it('returns 504 with the exact message when the AI service times out', function (callable $fake): void {
        Http::fake([AI_ASK_URL => $fake]);

        $this->actingAs($this->staff)
            ->postJson('/api/assistant/questions', ['question' => 'What is the retention period?'])
            ->assertStatus(504)
            ->assertJsonPath('message', 'The assistant took too long to respond. Please try again.');

        expect(AiQuestion::query()->sole()->status)->toBe('timeout');
    })->with([
        'no answer in 15 s (connection timeout)' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds')],
        'FastAPI LLM_TIMEOUT (504)' => [fn () => fn () => Http::response(['error_code' => 'LLM_TIMEOUT', 'message' => 'The language model did not respond in time.'], 504)],
    ]);

    it('returns 503 with the exact message when the AI service is down or the provider is not authorized', function (callable $fake): void {
        Http::fake([AI_ASK_URL => $fake]);

        $this->actingAs($this->staff)
            ->postJson('/api/assistant/questions', ['question' => 'What is the retention period?'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The AI assistant is not available right now. Please try again later.');

        expect(AiQuestion::query()->sole()->status)->toBe('unavailable');
    })->with([
        'service down (connection refused)' => [fn () => fn () => throw new ConnectionException('cURL error 7: Failed to connect to ai-service port 8001: Connection refused')],
        'provider not authorized (503)' => [fn () => fn () => Http::response(['error_code' => 'PROVIDER_NOT_AUTHORIZED', 'message' => 'The AI provider is not authorized.'], 503)],
        'embedding failed (502)' => [fn () => fn () => Http::response(['error_code' => 'EMBEDDING_FAILED', 'message' => 'Failed.'], 502)],
        'internal token rejected (401)' => [fn () => fn () => Http::response(['error_code' => 'UNAUTHORIZED', 'message' => 'Invalid token.'], 401)],
    ]);

    it('allows 20 questions per minute and answers the 21st with 429 and the exact message', function (): void {
        Http::fake([AI_ASK_URL => Http::response(fakeAnswer())]);

        foreach (range(1, 20) as $i) {
            $this->actingAs($this->staff)->postJson('/api/assistant/questions', ['question' => "Question number {$i}"])->assertCreated();
        }

        $this->actingAs($this->staff)
            ->postJson('/api/assistant/questions', ['question' => 'Question number 21'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'You are sending questions too quickly. Wait a moment and try again.');

        expect(AiQuestion::query()->count())->toBe(20);

        // The limit is per user.
        $this->actingAs($this->employee)->postJson('/api/assistant/questions', ['question' => 'Another user'])->assertCreated();
    });
});

describe('successful question', function (): void {
    it('calls FastAPI with a 15 s timeout and stores the question in ai_questions', function (): void {
        Http::fake([AI_ASK_URL => Http::response(fakeAnswer())]);

        $response = $this->actingAs($this->staff)
            ->postJson('/api/assistant/questions', ['question' => '  How long must records be kept?  '])
            ->assertCreated()
            ->assertJsonPath('data.question', 'How long must records be kept?')
            ->assertJsonPath('data.answer', 'Records must be kept for **5 years**.')
            ->assertJsonPath('data.status', 'answered')
            ->assertJsonPath('data.citations.0.document_name', 'Code of conduct')
            ->assertJsonPath('data.disclaimer', 'This answer is not legal advice.');

        $record = AiQuestion::query()->sole();
        expect($record->user_id)->toBe($this->staff->id)
            ->and($record->question)->toBe('How long must records be kept?')
            ->and($record->answer)->toBe('Records must be kept for **5 years**.')
            ->and($record->status)->toBe('answered')
            ->and($record->citations[0]['page'])->toBe(4)
            ->and($record->latency_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($response->json('data.id'))->toBe($record->id);

        Http::assertSent(fn (Request $request) => $request->url() === AI_ASK_URL
            && $request->hasHeader('X-Internal-Token', 'test-token')
            && $request['question'] === 'How long must records be kept?'
            && $request['user_id'] === $this->staff->id
            && $request['organization_id'] === $this->organization->id);
    });

    it('sends only the documents the user may read', function (string $role, array $expected): void {
        Http::fake([AI_ASK_URL => Http::response(fakeAnswer())]);
        $user = $role === 'general_employee' ? $this->employee : $this->staff;

        $this->actingAs($user)->postJson('/api/assistant/questions', ['question' => 'Which policies apply?'])->assertCreated();

        $ids = array_map(fn ($key) => $this->docs[$key], $expected);
        Http::assertSent(fn (Request $request) => $request['allowed_document_ids'] === $ids);
    })->with([
        'Compliance Staff: both access levels' => ['compliance_staff', ['all_staff', 'compliance_only']],
        'General Employee: all_staff only' => ['general_employee', ['all_staff']],
    ]);

    it('stores an insufficient-information answer with its status', function (): void {
        Http::fake([AI_ASK_URL => Http::response(['answer' => 'Not enough information.', 'status' => 'insufficient_information', 'citations' => [], 'disclaimer' => 'Not legal advice.', 'latency_ms' => 100])]);

        $this->actingAs($this->staff)->postJson('/api/assistant/questions', ['question' => 'What is the moon made of?'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'insufficient_information');

        expect(AiQuestion::query()->sole()->status)->toBe('insufficient_information');
    });
});

describe('history and authentication', function (): void {
    it('lists the last 50 questions of the user only, newest first', function (): void {
        foreach (range(1, 55) as $i) {
            AiQuestion::query()->create([
                'user_id' => $this->staff->id, 'question' => "Question {$i}", 'answer' => "Answer {$i}",
                'status' => 'answered', 'citations' => [], 'latency_ms' => 10, 'created_at' => now()->subMinutes(60 - $i),
            ]);
        }
        AiQuestion::query()->create([
            'user_id' => $this->outsider->id, 'question' => 'Not mine', 'answer' => 'x',
            'status' => 'answered', 'citations' => [], 'latency_ms' => 10,
        ]);

        $this->actingAs($this->staff)
            ->getJson('/api/assistant/questions')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.question', 'Question 55')
            ->assertJsonPath('data.49.question', 'Question 6')
            ->assertJsonMissing(['question' => 'Not mine']);
    });

    it('requires authentication', function (): void {
        $this->postJson('/api/assistant/questions', ['question' => 'Anything?'])->assertUnauthorized();
        $this->getJson('/api/assistant/questions')->assertUnauthorized();
    });
});

it('uses the same messages in the React chat and in Laravel', function (): void {
    $frontend = file_get_contents(base_path('../frontend/src/features/assistant/messages.ts'));

    foreach ((new ReflectionClass(M::class))->getConstants() as $name => $text) {
        expect(str_contains($frontend, "'{$text}'"))->toBeTrue("{$name} is missing or different in messages.ts");
    }
})->skip(fn () => ! file_exists(base_path('../frontend/src/features/assistant/messages.ts')), 'The frontend folder is not available (for example inside the backend container).');
