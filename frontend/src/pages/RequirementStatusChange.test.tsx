import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { REQUIREMENT_MESSAGES as M } from '../features/requirements/messages'
import { api } from '../lib/api'
import RequirementDetailPage from './RequirementDetailPage'

vi.mock('../lib/api', () => ({ api: { get: vi.fn(), patch: vi.fn() } }))

const LABELS: Record<string, string> = { compliant: 'Compliant', pending: 'Pending', at_risk: 'At Risk', overdue: 'Overdue' }
let currentStatus = 'pending'

const requirement = () => ({
  id: 5,
  name: 'Psychosocial risk factors',
  description: 'Identify, analyze and prevent psychosocial risk factors at work.',
  regulation: 'NOM-035-STPS-2018',
  deadline: '2026-12-31',
  status: { code: currentStatus, label: LABELS[currentStatus] },
  responsible_user: { id: 2, name: 'Compliance Staff' },
  required_documents: [],
  applies: true,
  created_at: null,
})

function mockApi(role: string) {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/api/user') return { data: { id: 2, name: 'User', email: 'u@example.test', role } }
    if (url === '/api/compliance-requirements/5') return { data: { data: requirement() } }
    if (url === '/api/dashboard') return { data: { status: LABELS[currentStatus] } }
    return { data: {} }
  })
}

function httpError(status: number, data: unknown) {
  const response = { status, data, statusText: '', headers: {}, config: { headers: new AxiosHeaders() } }
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, response)
}

// Stand-in for the dashboard of CMP-19: any query under ['dashboard'] must refresh after a status change.
function DashboardProbe() {
  const { data } = useQuery({
    queryKey: ['dashboard', 'summary'],
    queryFn: async () => (await api.get<{ status: string }>('/api/dashboard')).data,
  })
  return <p data-testid="dashboard-status">{data?.status}</p>
}

function renderPage(client = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/requirements/5']}>
        <DashboardProbe />
        <Routes>
          <Route path="/requirements/:id" element={<RequirementDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return client
}

describe('Requirement status change', () => {
  beforeEach(() => {
    currentStatus = 'pending'
    vi.mocked(api.get).mockReset()
    vi.mocked(api.patch).mockReset()
  })

  it('offers only the 4 statuses', async () => {
    mockApi('compliance_staff')
    renderPage()

    const select = await screen.findByLabelText('Change status')
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['Compliant', 'Pending', 'At Risk', 'Overdue'])
    expect(select).toHaveValue('pending')
  })

  it.each(['administrator', 'general_employee'])('does not show the status control to %s', async (role) => {
    mockApi(role)
    renderPage()

    expect(await screen.findByTestId('status-badge')).toHaveTextContent('Pending')
    await vi.waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/user'))
    expect(screen.queryByLabelText('Change status')).not.toBeInTheDocument()
  })

  it('asks for confirmation and does nothing on Cancel', async () => {
    mockApi('compliance_staff')
    renderPage()

    fireEvent.change(await screen.findByLabelText('Change status'), { target: { value: 'compliant' } })

    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveTextContent('Change status from Pending to Compliant?')
    expect(within(dialog).getByRole('button', { name: 'Change status' })).toHaveFocus()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.patch).not.toHaveBeenCalled()
    expect(screen.getByLabelText('Change status')).toHaveValue('pending')
  })

  it('changes the status, shows the toast and refreshes the detail badge and the dashboard without reloading', async () => {
    mockApi('compliance_staff')
    vi.mocked(api.patch).mockImplementation(async () => {
      currentStatus = 'compliant'
      return { data: { data: requirement(), message: 'Status updated to Compliant.' } }
    })
    const client = renderPage()
    const invalidate = vi.spyOn(client, 'invalidateQueries')
    await vi.waitFor(() => expect(screen.getByTestId('dashboard-status')).toHaveTextContent('Pending'))

    fireEvent.change(await screen.findByLabelText('Change status'), { target: { value: 'compliant' } })
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Change status' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Status updated to Compliant.')
    expect(api.patch).toHaveBeenCalledWith('/api/compliance-requirements/5/status', { status: 'compliant' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['requirements'] })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['dashboard'] })
    await vi.waitFor(() => expect(screen.getByTestId('status-badge')).toHaveTextContent('Compliant'))
    await vi.waitFor(() => expect(screen.getByTestId('dashboard-status')).toHaveTextContent('Compliant'))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it.each([
    [422, { message: M.statusUnchanged, errors: { status: [M.statusUnchanged] } }, M.statusUnchanged],
    [403, { message: M.statusForbidden }, M.statusForbidden],
    [404, { message: M.notFound }, M.notFound],
  ])('shows the exact message of an HTTP %s error', async (status, data, message) => {
    mockApi('compliance_staff')
    vi.mocked(api.patch).mockRejectedValue(httpError(status, data))
    renderPage()

    fireEvent.change(await screen.findByLabelText('Change status'), { target: { value: 'overdue' } })
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Change status' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(screen.getByTestId('status-badge')).toHaveTextContent('Pending')
  })
})
