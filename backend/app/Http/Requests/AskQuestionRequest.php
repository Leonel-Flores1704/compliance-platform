<?php

namespace App\Http\Requests;

use App\Support\AssistantMessages as M;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/assistant/questions (FR-010, AIR-001, CMP-33).
 * Every authenticated role can use the assistant (ADR-005, function 8).
 */
class AskQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // TrimStrings + ConvertEmptyStringsToNull turn a blank question into null.
            'question' => ['bail', 'required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => M::QUESTION_REQUIRED,
            'question.string' => M::QUESTION_REQUIRED,
            'question.min' => M::QUESTION_TOO_SHORT,
            'question.max' => M::QUESTION_TOO_LONG,
        ];
    }
}
