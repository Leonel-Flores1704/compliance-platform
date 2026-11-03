import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { REQUIREMENT_LABELS as L, REQUIREMENT_MESSAGES as M } from '../features/requirements/messages'
import { api } from '../lib/api'
import RequirementCreatePage from './RequirementCreatePage'
import RequirementDetailPage from './RequirementDetailPage'

vi.mock('../lib/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }))

const created = {
  id: 5,
  name: 'Psychosocial risk factors',
  description: 'Identify, analyze and prevent psychosocial risk factors at work.',
  regulation: 'NOM-035-STPS-2018',
  deadline: '2026-12-31',
  status: { code: 'at_risk', label: 'At Risk' },
  responsible_user: { id: 2, name: 'Compliance Staff' },
  required_documents: [{ id: 1, name: 'Psychosocial risk policy', category: { code: 'policy', name: 'Policy' } }],
  applies: true,
  created_at: null,
}

function mockApi(role: string) {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/api/user') return { data: { id: 2, name: 'Compliance Staff', email: 's@example.test', role } }
    if (url === '/api/users/options') return { data: { data: [{ id: 2, name: 'Compliance Staff' }] } }
    if (url === '/api/catalogs/document-categories') return { data: { document_categories: [{ code: 'policy', name: 'Policy' }] } }
    if (url === '/api/compliance-requirements/5') return { data: { data: created } }
    return { data: {} }
  })
}

function axios422(errors: Record<string, string[]>) {
  const response = { status: 422, data: { message: 'invalid', errors }, statusText: '', headers: {}, config: { headers: new AxiosHeaders() } }
  return new AxiosError('Unprocessable', 'ERR_BAD_REQUEST', undefined, undefined, response)
}

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/requirements/new']}>
        <Routes>
          <Route path="/requirements/new" element={<RequirementCreatePage />} />
          <Route path="/requirements/:id" element={<RequirementDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const submit = () => fireEvent.click(screen.getByRole('button', { name: 'Create requirement' }))

async function fillValidForm() {
  await screen.findByRole('option', { name: 'Compliance Staff' })
  await screen.findByRole('option', { name: 'Policy' })
  fireEvent.change(screen.getByLabelText(L.name), { target: { value: created.name } })
  fireEvent.change(screen.getByLabelText(L.description), { target: { value: created.description } })
  fireEvent.change(screen.getByLabelText(L.regulation), { target: { value: created.regulation } })
  fireEvent.change(screen.getByLabelText(L.deadline), { target: { value: '31/12/2026' } })
  fireEvent.change(screen.getByLabelText('Required document 1 name'), { target: { value: 'Psychosocial risk policy' } })
  fireEvent.change(screen.getByLabelText('Required document 1 category'), { target: { value: 'policy' } })
  fireEvent.change(screen.getByLabelText(L.responsible_user_id), { target: { value: '2' } })
  fireEvent.change(screen.getByLabelText(L.status), { target: { value: 'at_risk' } })
}

describe('RequirementCreatePage', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it('shows the permission message to General Employees and no form', async () => {
    mockApi('general_employee')
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(M.forbidden)
    expect(screen.queryByLabelText(L.name)).not.toBeInTheDocument()
  })

  it.each(['compliance_staff', 'administrator'])('shows the 7 fields to %s with Pending as default status', async (role) => {
    mockApi(role)
    renderPage()

    for (const label of [L.name, L.description, L.regulation, L.deadline, L.responsible_user_id, L.status]) {
      expect(await screen.findByLabelText(label)).toBeInTheDocument()
    }
    expect(screen.getByRole('group', { name: L.required_documents })).toBeInTheDocument()
    expect(screen.getByLabelText(L.deadline)).toHaveAttribute('placeholder', 'DD/MM/YYYY')
    expect(screen.getByLabelText(L.status)).toHaveValue('pending')
    expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(
      expect.arrayContaining(['Compliant', 'Pending', 'At Risk', 'Overdue']),
    )
  })

  it('adds and removes required document rows', async () => {
    mockApi('compliance_staff')
    renderPage()
    await screen.findByLabelText('Required document 1 name')

    fireEvent.click(screen.getByRole('button', { name: 'Add required document' }))
    expect(screen.getByLabelText('Required document 2 name')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Remove required document 2' }))
    expect(screen.queryByLabelText('Required document 2 name')).not.toBeInTheDocument()
  })

  it('shows every message under its field, focuses the first invalid field and sends nothing', async () => {
    mockApi('compliance_staff')
    renderPage()
    await screen.findByRole('option', { name: 'Policy' })

    submit()

    for (const message of [M.nameRequired, M.descriptionRequired, M.regulationRequired, M.deadlineRequired, M.documentIncomplete, M.responsibleRequired]) {
      expect(await screen.findByText(message)).toHaveClass('text-red-600')
    }
    expect(screen.getByLabelText(L.name)).toHaveFocus()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('asks for at least one required document when the list is empty', async () => {
    mockApi('compliance_staff')
    renderPage()
    await fillValidForm()
    fireEvent.click(screen.getByRole('button', { name: 'Remove required document 1' }))

    submit()

    expect(await screen.findByText(M.documentsRequired)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('rejects an invalid deadline date', async () => {
    mockApi('compliance_staff')
    renderPage()
    await fillValidForm()
    fireEvent.change(screen.getByLabelText(L.deadline), { target: { value: '31/02/2026' } })

    submit()

    expect(await screen.findByText(M.deadlineInvalid)).toBeInTheDocument()
    expect(screen.getByLabelText(L.deadline)).toHaveFocus()
  })

  it('shows server errors under the field and focuses it', async () => {
    mockApi('compliance_staff')
    vi.mocked(api.post).mockRejectedValue(
      axios422({ 'required_documents.0.category': [M.documentIncomplete], status: [M.statusInvalid] }),
    )
    renderPage()
    await fillValidForm()

    submit()

    expect(await screen.findByText(M.documentIncomplete)).toBeInTheDocument()
    expect(screen.getByText(M.statusInvalid)).toBeInTheDocument()
    expect(screen.getByLabelText('Required document 1 category')).toHaveFocus()
  })

  it('creates the requirement, shows the toast, all fields and the status badge', async () => {
    mockApi('compliance_staff')
    vi.mocked(api.post).mockResolvedValue({ data: { data: created, message: M.created } })
    renderPage()
    await fillValidForm()

    submit()

    expect(await screen.findByRole('status')).toHaveTextContent(M.created)
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/api/compliance-requirements', {
        name: created.name,
        description: created.description,
        regulation: 'NOM-035-STPS-2018',
        deadline: '2026-12-31',
        required_documents: [{ name: 'Psychosocial risk policy', category: 'policy' }],
        responsible_user_id: 2,
        status: 'at_risk',
      }),
    )
    expect(await screen.findByTestId('status-badge')).toHaveTextContent('At Risk')
    const details = screen.getByRole('heading', { name: created.name }).parentElement!.nextElementSibling as HTMLElement
    for (const value of [created.description, 'NOM-035-STPS-2018', '31/12/2026', 'Compliance Staff', 'Psychosocial risk policy']) {
      expect(within(details).getAllByText(value, { exact: false }).length).toBeGreaterThan(0)
    }
  })
})
