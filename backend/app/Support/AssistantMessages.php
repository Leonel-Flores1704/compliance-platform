<?php

namespace App\Support;

/**
 * User-facing texts of the AI assistant chat (CMP-33).
 * The frontend uses the same texts in frontend/src/features/assistant/messages.ts.
 */
final class AssistantMessages
{
    public const QUESTION_REQUIRED = 'Type a question before sending.';

    public const QUESTION_TOO_SHORT = 'Your question is too short.';

    public const QUESTION_TOO_LONG = 'Questions can be up to 1,000 characters long.';

    public const TIMEOUT = 'The assistant took too long to respond. Please try again.';

    public const UNAVAILABLE = 'The AI assistant is not available right now. Please try again later.';

    public const TOO_MANY_QUESTIONS = 'You are sending questions too quickly. Wait a moment and try again.';
}
