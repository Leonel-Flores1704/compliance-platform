<?php

namespace App\Http\Controllers;

use App\Http\Requests\AskQuestionRequest;
use App\Http\Resources\AiQuestionResource;
use App\Models\AiQuestion;
use App\Services\AiServiceClient;
use App\Services\AllowedDocumentResolver;
use App\Services\Exceptions\AiServiceException;
use App\Services\Exceptions\AiServiceLlmTimeoutException;
use App\Support\AssistantMessages;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AssistantController extends Controller
{
    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly AiServiceClient $ai,
        private readonly AllowedDocumentResolver $allowedDocuments,
    ) {}

    /**
     * POST /api/assistant/questions (FR-010, AIR-001).
     * Calls FastAPI /v1/ask (15 s timeout, CMP-07) and stores every attempt in ai_questions.
     */
    public function ask(AskQuestionRequest $request): JsonResponse
    {
        $user = $request->user();
        $question = $request->validated('question');
        $started = hrtime(true);

        try {
            $result = $this->ai->ask(
                question: $question,
                userId: $user->id,
                organizationId: $user->organization_id,
                allowedDocumentIds: $this->allowedDocuments->forUser($user),
                requestId: (string) Str::uuid(),
            );
        } catch (ConnectionException $exception) {
            $timedOut = str_contains($exception->getMessage(), 'cURL error 28') || str_contains(strtolower($exception->getMessage()), 'timed out');

            return $this->failure($user->id, $question, $started, $timedOut);
        } catch (AiServiceLlmTimeoutException) {
            return $this->failure($user->id, $question, $started, timedOut: true);
        } catch (AiServiceException) {
            // Service down, provider not authorized (CMP-28) or any other AI service error.
            return $this->failure($user->id, $question, $started, timedOut: false);
        }

        $record = AiQuestion::query()->create([
            'user_id' => $user->id,
            'question' => $question,
            'answer' => (string) ($result['answer'] ?? ''),
            'status' => (string) ($result['status'] ?? 'answered'),
            'citations' => array_values($result['citations'] ?? []),
            'latency_ms' => $this->elapsedMs($started),
        ]);

        return (new AiQuestionResource($record))
            ->withDisclaimer($result['disclaimer'] ?? null)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/assistant/questions: last 50 questions of the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $questions = AiQuestion::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return AiQuestionResource::collection($questions);
    }

    private function failure(int $userId, string $question, int $started, bool $timedOut): JsonResponse
    {
        AiQuestion::query()->create([
            'user_id' => $userId,
            'question' => $question,
            'answer' => '',
            'status' => $timedOut ? self::STATUS_TIMEOUT : self::STATUS_UNAVAILABLE,
            'citations' => [],
            'latency_ms' => $this->elapsedMs($started),
        ]);

        return $timedOut
            ? response()->json(['message' => AssistantMessages::TIMEOUT], 504)
            : response()->json(['message' => AssistantMessages::UNAVAILABLE], 503);
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
