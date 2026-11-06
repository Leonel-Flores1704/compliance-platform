import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../auth/AuthContext'
import ProtectedRoute from '../components/ProtectedRoute'
import { ASSISTANT_MESSAGES as M, ASSISTANT_TEXTS as T } from '../features/assistant/messages'
import { api } from '../lib/api'
import AssistantPage from './AssistantPage'

vi.mock('../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), interceptors: { response: { use: vi.fn() } } },
  setUnauthorizedHandler: vi.fn(),
}))

const answer = {
  id: 10,
  question: 'How long must we keep payroll records?',
  answer: 'Payroll records must be kept for **5 years**.',
  status: 'answered',
  citations: [
    { document_id: 1, document_version_id: 1, document_name: 'Records Policy.pdf', page: 4, section: '4.2 Retention', chunk_id: 'c1', score: 0.91 },
    { document_id: 2, document_version_id: 3, document_name: 'Payroll Manual.docx', page: null, section: 'Archiving', chunk_id: 'c2', score: 0.82 },
  ],
  disclaimer: 'This answer is not legal advice.',
  latency_ms: 812,
  created_at: '2026-11-06T14:00:00Z',
}

function httpError(status: number, data: unknown) {
  const response = { status, data, statusText: '', headers: {}, config: { headers: new AxiosHeaders() } }
  return new AxiosError('Request failed', 'ERR_BAD_RESPONSE', undefined, undefined, response)
}

function mockGet({ session = true, history = [] as unknown[] } = {}) {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/api/auth/me') {
      if (!session) throw httpError(401, { message: 'Unauthenticated.' })
      return { data: { id: 2, name: 'Compliance Staff', role: 'compliance_staff', organization_id: 1 } }
    }
    if (url === '/api/assistant/questions') return { data: { data: history } }
    return { data: {} }
  })
}

function renderAssistant() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/assistant']}>
        <AuthProvider>
          <Routes>
            <Route path="/login" element={<p>Login page</p>} />
            <Route element={<ProtectedRoute />}>
              <Route path="/assistant" element={<AssistantPage />} />
            </Route>
          </Routes>
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const textarea = () => screen.getByPlaceholderText(T.placeholder)
const type = (value: string) => fireEvent.change(textarea(), { target: { value } })
const pressEnter = (shiftKey = false) => fireEvent.keyDown(textarea(), { key: 'Enter', shiftKey })

describe('AssistantPage', () => {
  afterEach(cleanup)
  beforeEach(() => vi.clearAllMocks())

  it('redirects to /login when there is no session', async () => {
    mockGet({ session: false })
    renderAssistant()

    expect(await screen.findByText('Login page')).toBeInTheDocument()
    expect(screen.queryByPlaceholderText(T.placeholder)).not.toBeInTheDocument()
  })

  it('shows the textarea with placeholder, the 0/1000 counter and the Send button', async () => {
    mockGet()
    renderAssistant()

    expect(await screen.findByPlaceholderText("Ask a question about your organization's documents...")).toBeInTheDocument()
    expect(screen.getByTestId('char-counter')).toHaveTextContent('0/1000')
    expect(screen.getByRole('button', { name: 'Send' })).toBeEnabled()

    type('Hello there')
    expect(screen.getByTestId('char-counter')).toHaveTextContent('11/1000')
  })

  it('Shift+Enter adds a new line and does not send', async () => {
    mockGet()
    renderAssistant()
    await screen.findByPlaceholderText(T.placeholder)
    type('First line')

    pressEnter(true)

    expect(api.post).not.toHaveBeenCalled()
    expect(screen.queryByTestId('user-bubble')).not.toBeInTheDocument()
  })

  it('Enter sends, shows the loading state with controls disabled, then the answer with citations and disclaimer', async () => {
    mockGet()
    let resolve: (value: unknown) => void = () => {}
    vi.mocked(api.post).mockReturnValue(new Promise((r) => (resolve = r)) as never)
    renderAssistant()
    await screen.findByPlaceholderText(T.placeholder)
    type('  How long must we keep payroll records?  ')

    pressEnter()

    expect(await screen.findByText('Searching your documents...')).toBeInTheDocument()
    expect(screen.getByTestId('user-bubble')).toHaveTextContent('How long must we keep payroll records?')
    await waitFor(() => expect(textarea()).toBeDisabled())
    expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled()
    expect(api.post).toHaveBeenCalledWith('/api/assistant/questions', { question: 'How long must we keep payroll records?' })

    resolve({ data: { data: answer } })

    const bubble = await screen.findByTestId('answer-bubble')
    expect(within(bubble).getByText('5 years').tagName).toBe('STRONG')
    const sources = within(bubble).getByRole('list', { name: 'Sources' })
    expect(within(sources).getAllByRole('listitem').map((li) => li.textContent)).toEqual([
      '[1] Records Policy.pdf, p. 4, 4.2 Retention',
      '[2] Payroll Manual.docx, Archiving',
    ])
    expect(within(bubble).getByText('This answer is not legal advice.')).toHaveClass('text-gray-500')
    expect(screen.queryByText('Searching your documents...')).not.toBeInTheDocument()
    await waitFor(() => expect(textarea()).toBeEnabled())
    expect(textarea()).toHaveValue('')
  })

  it.each([
    ['empty question', '', M.questionRequired],
    ['only spaces', '    ', M.questionRequired],
    ['shorter than 3 characters', 'Hi', M.questionTooShort],
    ['longer than 1,000 characters', 'a'.repeat(1001), M.questionTooLong],
  ])('shows "%s" as a red assistant bubble without calling the API', async (_case, value, message) => {
    mockGet()
    renderAssistant()
    await screen.findByPlaceholderText(T.placeholder)
    type(value)

    pressEnter()

    const bubble = await screen.findByTestId('error-bubble')
    expect(bubble).toHaveTextContent(message)
    expect(bubble).toHaveClass('bg-red-50', 'text-red-700')
    expect(within(bubble).getByRole('button', { name: 'Try again' })).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it.each([
    ['422 from the server', httpError(422, { message: M.questionTooShort, errors: { question: [M.questionTooShort] } }), M.questionTooShort],
    ['504 AI service timeout', httpError(504, { message: M.timeout }), 'The assistant took too long to respond. Please try again.'],
    ['503 AI service down', httpError(503, { message: M.unavailable }), 'The AI assistant is not available right now. Please try again later.'],
    ['429 too many questions', httpError(429, { message: M.tooManyQuestions }), 'You are sending questions too quickly. Wait a moment and try again.'],
    ['network error (backend unreachable)', new AxiosError('Network Error', 'ERR_NETWORK'), M.unavailable],
  ])('shows the exact message of a %s inside the chat', async (_case, error, message) => {
    mockGet()
    vi.mocked(api.post).mockRejectedValue(error)
    renderAssistant()
    await screen.findByPlaceholderText(T.placeholder)
    type('What is the retention period?')

    pressEnter()

    const bubble = await screen.findByTestId('error-bubble')
    expect(bubble).toHaveTextContent(message)
    expect(bubble).toHaveClass('bg-red-50', 'text-red-700')
    expect(within(bubble).getByRole('button', { name: 'Try again' })).toBeInTheDocument()
    expect(screen.queryByText('Searching your documents...')).not.toBeInTheDocument()
  })

  it('"Try again" sends the same question again', async () => {
    mockGet()
    vi.mocked(api.post)
      .mockRejectedValueOnce(httpError(504, { message: M.timeout }))
      .mockResolvedValueOnce({ data: { data: answer } })
    renderAssistant()
    await screen.findByPlaceholderText(T.placeholder)
    type('How long must we keep payroll records?')
    pressEnter()

    fireEvent.click(within(await screen.findByTestId('error-bubble')).getByRole('button', { name: 'Try again' }))

    expect(await screen.findByTestId('answer-bubble')).toBeInTheDocument()
    expect(screen.queryByTestId('error-bubble')).not.toBeInTheDocument()
    expect(screen.getAllByTestId('user-bubble')).toHaveLength(1)
    expect(vi.mocked(api.post).mock.calls.filter(([url]) => url === '/api/assistant/questions')).toHaveLength(2)
  })

  it('lists the previous questions of the user', async () => {
    mockGet({ history: [answer] })
    renderAssistant()

    const list = await screen.findByRole('list', { name: 'Previous questions' })
    expect(await within(list).findByText('How long must we keep payroll records?')).toBeInTheDocument()
  })
})
