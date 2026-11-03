import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ORGANIZATION_LABELS as L, ORGANIZATION_MESSAGES as M } from '../features/organizations/messages'
import { api } from '../lib/api'
import OrganizationCreatePage from './OrganizationCreatePage'
import OrganizationDetailPage from './OrganizationDetailPage'

vi.mock('../lib/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }))

const catalogs = {
  organization_types: [{ code: 'private_company', name: 'Private company' }],
  industries: [{ code: 'manufacturing', name: 'Manufacturing' }],
  states: [{ code: '10', name: 'Durango' }],
  business_activities: [
    { code: '31-33', name: 'Manufacturing' },
    { code: '43', name: 'Wholesale trade' },
  ],
}

const created = {
  id: 7,
  legal_name: 'Aceros del Guadiana, S.A. de C.V.',
  organization_type: { code: 'private_company', name: 'Private company' },
  industry: { code: 'manufacturing', name: 'Manufacturing' },
  state: { code: '10', name: 'Durango' },
  municipality: 'Durango',
  employees_count: 120,
  business_activities: [{ code: '31-33', name: 'Manufacturing' }],
  is_active: true,
  created_at: null,
}

function mockApi(role: string) {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/api/user') return { data: { id: 1, name: 'User', email: 'u@example.test', role } }
    if (url === '/api/catalogs/organization') return { data: catalogs }
    if (url === '/api/organizations/7') return { data: { data: created } }
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
      <MemoryRouter initialEntries={['/organizations/new']}>
        <Routes>
          <Route path="/organizations/new" element={<OrganizationCreatePage />} />
          <Route path="/organizations/:id" element={<OrganizationDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function fillValidForm() {
  await screen.findByRole('option', { name: 'Durango' })
  fireEvent.change(screen.getByLabelText(L.legal_name), { target: { value: created.legal_name } })
  fireEvent.change(screen.getByLabelText(L.organization_type), { target: { value: 'private_company' } })
  fireEvent.change(screen.getByLabelText(L.industry), { target: { value: 'manufacturing' } })
  fireEvent.change(screen.getByLabelText(L.state), { target: { value: '10' } })
  fireEvent.change(screen.getByLabelText(L.municipality), { target: { value: 'Durango' } })
  fireEvent.change(screen.getByLabelText(L.employees_count), { target: { value: '120' } })
  fireEvent.click(screen.getByLabelText('31-33 - Manufacturing'))
}

describe('OrganizationCreatePage', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it.each(['compliance_staff', 'general_employee'])('shows the permission message to %s and no form', async (role) => {
    mockApi(role)
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(M.forbidden)
    expect(screen.queryByLabelText(L.legal_name)).not.toBeInTheDocument()
  })

  it('shows the 6 characteristics with their labels', async () => {
    mockApi('administrator')
    renderPage()

    for (const label of [L.legal_name, L.organization_type, L.industry, L.state, L.municipality, L.employees_count]) {
      expect(await screen.findByLabelText(label)).toBeInTheDocument()
    }
    expect(screen.getByRole('group', { name: L.business_activities })).toBeInTheDocument()
  })

  it('shows every message under its field, focuses the first invalid field and sends nothing', async () => {
    mockApi('administrator')
    renderPage()
    await screen.findByRole('option', { name: 'Durango' })

    fireEvent.click(screen.getByRole('button', { name: 'Register organization' }))

    for (const message of [
      M.legalNameRequired,
      M.organizationTypeRequired,
      M.industryRequired,
      M.stateRequired,
      M.municipalityRequired,
      M.employeesRequired,
      M.activitiesRequired,
    ]) {
      expect(await screen.findByText(message)).toHaveClass('text-red-600')
    }
    expect(screen.getByLabelText(L.legal_name)).toHaveFocus()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows server errors under the field and focuses it', async () => {
    mockApi('administrator')
    vi.mocked(api.post).mockRejectedValue(axios422({ legal_name: [M.legalNameTaken] }))
    renderPage()
    await fillValidForm()

    fireEvent.click(screen.getByRole('button', { name: 'Register organization' }))

    expect(await screen.findByText(M.legalNameTaken)).toBeInTheDocument()
    expect(screen.getByLabelText(L.legal_name)).toHaveFocus()
  })

  it('registers the organization, shows the toast and the 6 values', async () => {
    mockApi('administrator')
    vi.mocked(api.post).mockResolvedValue({ data: { data: created, message: M.created } })
    renderPage()
    await fillValidForm()

    fireEvent.click(screen.getByRole('button', { name: 'Register organization' }))

    expect(await screen.findByRole('status')).toHaveTextContent(M.created)
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/api/organizations', {
        legal_name: created.legal_name,
        organization_type: 'private_company',
        industry: 'manufacturing',
        state: '10',
        municipality: 'Durango',
        employees_count: 120,
        business_activities: ['31-33'],
      }),
    )
    const list = screen.getByRole('heading', { name: created.legal_name }).nextElementSibling as HTMLElement
    for (const value of ['Private company', 'Manufacturing', 'Durango', '120', '31-33 - Manufacturing']) {
      expect(within(list).getAllByText(value).length).toBeGreaterThan(0)
    }
  })
})
