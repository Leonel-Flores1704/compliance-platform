// User-facing texts of the AI assistant chat (CMP-33).
// The error texts must stay identical to backend/app/Support/AssistantMessages.php.
export const ASSISTANT_MESSAGES = {
  questionRequired: 'Type a question before sending.',
  questionTooShort: 'Your question is too short.',
  questionTooLong: 'Questions can be up to 1,000 characters long.',
  timeout: 'The assistant took too long to respond. Please try again.',
  unavailable: 'The AI assistant is not available right now. Please try again later.',
  tooManyQuestions: 'You are sending questions too quickly. Wait a moment and try again.',
} as const

export const ASSISTANT_TEXTS = {
  placeholder: "Ask a question about your organization's documents...",
  send: 'Send',
  loading: 'Searching your documents...',
  tryAgain: 'Try again',
} as const

export const MAX_QUESTION_LENGTH = 1000
export const MIN_QUESTION_LENGTH = 3
