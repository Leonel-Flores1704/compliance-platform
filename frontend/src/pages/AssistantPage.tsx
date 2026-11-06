import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { type FormEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react'
import ReactMarkdown from 'react-markdown'
import {
  askQuestion,
  fetchQuestionHistory,
  messageForError,
  validateQuestion,
  type AiQuestion,
  type Citation,
} from '../features/assistant/api'
import { ASSISTANT_TEXTS as T, MAX_QUESTION_LENGTH } from '../features/assistant/messages'

type ChatMessage =
  | { id: number; role: 'user'; text: string }
  | { id: number; role: 'assistant'; kind: 'loading' }
  | { id: number; role: 'assistant'; kind: 'answer'; record: AiQuestion }
  | { id: number; role: 'assistant'; kind: 'error'; message: string; retryQuestion: string | null }

let nextId = 1
const newId = () => nextId++

function LoadingBubble() {
  return (
    <div role="status" aria-live="polite" className="flex items-center gap-3">
      <span className="flex gap-1" aria-hidden="true">
        {[0, 150, 300].map((delay) => (
          <span key={delay} className="h-2 w-2 animate-bounce rounded-full bg-gray-500" style={{ animationDelay: `${delay}ms` }} />
        ))}
      </span>
      <span className="text-sm text-gray-700">{T.loading}</span>
    </div>
  )
}

function citationLabel(citation: Citation): string {
  const parts = [citation.document_name]
  if (citation.page) parts.push(`p. ${citation.page}`)
  if (citation.section) parts.push(citation.section)
  return parts.join(', ')
}

function AnswerBubble({ record }: { record: AiQuestion }) {
  return (
    <div data-testid="answer-bubble">
      <div className="prose prose-sm max-w-none text-sm leading-relaxed [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-2 [&_ul]:list-disc [&_ul]:pl-5">
        <ReactMarkdown>{record.answer}</ReactMarkdown>
      </div>
      {record.citations.length > 0 && (
        <ol aria-label="Sources" className="mt-3 space-y-1 border-t border-gray-200 pt-2 text-xs text-gray-700">
          {record.citations.map((citation, index) => (
            <li key={`${citation.chunk_id ?? index}-${index}`}>
              <span className="font-semibold">[{index + 1}]</span> {citationLabel(citation)}
            </li>
          ))}
        </ol>
      )}
      {record.disclaimer && <p className="mt-2 text-xs text-gray-500">{record.disclaimer}</p>}
      {/* Feedback buttons (useful / not useful) are added by CMP-35 here. */}
    </div>
  )
}

export default function AssistantPage() {
  const queryClient = useQueryClient()
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [draft, setDraft] = useState('')
  const textarea = useRef<HTMLTextAreaElement | null>(null)
  const listEnd = useRef<HTMLDivElement | null>(null)

  const history = useQuery({ queryKey: ['assistant', 'questions'], queryFn: fetchQuestionHistory })

  const mutation = useMutation({
    mutationFn: ({ question }: { question: string; loadingId: number }) => askQuestion(question),
    onSuccess: (record, { loadingId }) => {
      setMessages((current) =>
        current.map((m) => (m.id === loadingId ? { id: loadingId, role: 'assistant', kind: 'answer', record } : m)),
      )
      void queryClient.invalidateQueries({ queryKey: ['assistant', 'questions'] })
    },
    onError: (error, { question, loadingId }) => {
      setMessages((current) =>
        current.map((m) =>
          m.id === loadingId
            ? { id: loadingId, role: 'assistant', kind: 'error', message: messageForError(error), retryQuestion: question }
            : m,
        ),
      )
    },
  })

  const waiting = mutation.isPending

  useEffect(() => {
    listEnd.current?.scrollIntoView?.({ behavior: 'smooth' })
  }, [messages])

  useEffect(() => {
    if (!waiting) textarea.current?.focus()
  }, [waiting])

  const send = (question: string) => {
    const loadingId = newId()
    setMessages((current) => [
      ...current,
      { id: newId(), role: 'user', text: question },
      { id: loadingId, role: 'assistant', kind: 'loading' },
    ])
    mutation.mutate({ question, loadingId })
  }

  const submit = () => {
    if (waiting) return
    const error = validateQuestion(draft)
    if (error) {
      // Validation errors are shown in the chat; "Try again" returns to the textarea.
      setMessages((current) => [...current, { id: newId(), role: 'assistant', kind: 'error', message: error, retryQuestion: null }])
      return
    }
    const question = draft.trim()
    setDraft('')
    send(question)
  }

  const retry = (message: Extract<ChatMessage, { kind: 'error' }>) => {
    setMessages((current) => current.filter((m) => m.id !== message.id))
    if (message.retryQuestion) {
      // Remove the user bubble of the failed attempt; send() adds it again.
      setMessages((current) => {
        const users = current.map((m, i) => (m.role === 'user' && m.text === message.retryQuestion ? i : -1)).filter((i) => i !== -1)
        const index = users.length > 0 ? users[users.length - 1] : -1
        return index === -1 ? current : current.filter((_, i) => i !== index)
      })
      send(message.retryQuestion)
    } else {
      textarea.current?.focus()
    }
  }

  const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    // Enter sends; Shift+Enter keeps the default behavior (new line).
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      submit()
    }
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    submit()
  }

  const tooLong = draft.length > MAX_QUESTION_LENGTH

  return (
    <main className="mx-auto flex h-screen max-w-6xl gap-6 p-4 sm:p-6">
      <section className="flex min-w-0 flex-1 flex-col rounded-lg border border-gray-200 bg-white">
        <header className="border-b border-gray-200 px-4 py-3">
          <h1 className="text-lg font-bold">AI assistant</h1>
        </header>

        <div role="log" aria-label="Conversation" className="flex-1 space-y-4 overflow-y-auto bg-gray-50 p-4">
          {messages.map((message) =>
            message.role === 'user' ? (
              <div key={message.id} className="flex justify-end">
                <div data-testid="user-bubble" className="max-w-[80%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-blue-700 px-4 py-2 text-sm text-white">
                  {message.text}
                </div>
              </div>
            ) : message.kind === 'error' ? (
              <div key={message.id} className="flex justify-start">
                <div
                  data-testid="error-bubble"
                  role="alert"
                  className="max-w-[80%] rounded-2xl rounded-bl-sm border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700"
                >
                  <p>{message.message}</p>
                  <button
                    type="button"
                    onClick={() => retry(message)}
                    className="mt-2 rounded-md border border-red-400 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100"
                  >
                    {T.tryAgain}
                  </button>
                </div>
              </div>
            ) : (
              <div key={message.id} className="flex justify-start">
                <div className="max-w-[80%] rounded-2xl rounded-bl-sm border border-gray-200 bg-white px-4 py-3 shadow-sm">
                  {message.kind === 'loading' ? <LoadingBubble /> : <AnswerBubble record={message.record} />}
                </div>
              </div>
            ),
          )}
          <div ref={listEnd} />
        </div>

        <form onSubmit={onSubmit} className="border-t border-gray-200 p-3">
          <label htmlFor="assistant-question" className="sr-only">
            Question
          </label>
          <textarea
            id="assistant-question"
            ref={textarea}
            rows={3}
            value={draft}
            disabled={waiting}
            placeholder={T.placeholder}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={onKeyDown}
            className="block w-full resize-none rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-600 disabled:bg-gray-100"
          />
          <div className="mt-2 flex items-center justify-between">
            <span data-testid="char-counter" className={`text-xs ${tooLong ? 'font-semibold text-red-600' : 'text-gray-500'}`}>
              {draft.length}/{MAX_QUESTION_LENGTH}
            </span>
            <button
              type="submit"
              disabled={waiting}
              className="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
            >
              {T.send}
            </button>
          </div>
        </form>
      </section>

      <aside className="hidden w-72 shrink-0 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 lg:block">
        <h2 className="text-sm font-semibold">Previous questions</h2>
        <ul className="mt-3 space-y-2" aria-label="Previous questions">
          {(history.data ?? []).map((item) => (
            <li key={item.id}>
              <details className="rounded-md border border-gray-200 p-2 text-xs">
                <summary className="cursor-pointer font-medium">{item.question}</summary>
                <p className="mt-2 whitespace-pre-wrap text-gray-700">{item.answer || '—'}</p>
              </details>
            </li>
          ))}
        </ul>
      </aside>
    </main>
  )
}
