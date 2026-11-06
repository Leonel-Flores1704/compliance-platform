import axios from 'axios'
import { api } from '../../lib/api'
import { ASSISTANT_MESSAGES as M, MAX_QUESTION_LENGTH, MIN_QUESTION_LENGTH } from './messages'

export type Citation = {
  document_id: number
  document_version_id: number
  document_name: string
  page?: number | null
  section?: string | null
  chunk_id?: string
  score?: number
}

export type AiQuestion = {
  id: number
  question: string
  answer: string
  status: string
  citations: Citation[]
  disclaimer: string | null
  latency_ms: number
  created_at: string | null
}

export async function askQuestion(question: string): Promise<AiQuestion> {
  // Sanctum SPA authentication needs the CSRF cookie before a state-changing request.
  await api.get('/sanctum/csrf-cookie')
  const res = await api.post<{ data: AiQuestion }>('/api/assistant/questions', { question })
  return res.data.data
}

export async function fetchQuestionHistory(): Promise<AiQuestion[]> {
  const res = await api.get<{ data: AiQuestion[] }>('/api/assistant/questions')
  return res.data.data
}

// Same rules as backend/app/Http/Requests/AskQuestionRequest.php.
export function validateQuestion(question: string): string | null {
  const text = question.trim()
  if (text === '') return M.questionRequired
  if (text.length < MIN_QUESTION_LENGTH) return M.questionTooShort
  if (text.length > MAX_QUESTION_LENGTH) return M.questionTooLong
  return null
}

// Exact message for each error case; the server text is used when it is present.
export function messageForError(error: unknown): string {
  if (axios.isAxiosError(error) && error.response) {
    const data = error.response.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const validation = data?.errors?.question?.[0]
    if (validation) return validation
    switch (error.response.status) {
      case 429:
        return M.tooManyQuestions
      case 504:
        return M.timeout
      case 503:
        return M.unavailable
      default:
        return data?.message ?? M.unavailable
    }
  }
  // Network error: the backend could not be reached.
  return M.unavailable
}
