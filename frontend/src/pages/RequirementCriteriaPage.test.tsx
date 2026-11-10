import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CRITERIA_MESSAGES as M } from '../features/criteria/messages'
import { api } from '../lib/api'
import RequirementCriteriaPage from './RequirementCriteriaPage'
import RequirementListPage from './RequirementListPage'

vi.mock('../lib/api', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))

const catalogs = {
  organization_types: [{ code: 'private_company', name: 'Private company' }],
  industries: [
    { code: 'manufacturing', name: 'Manufacturing' },
    { code: 'retail', name: 'Retail' },
  ],
  states: [{ code: '10', name: 'Durango' }],
  business_activities: [
    { code: '31-33', name: 'Manufacturing' },
    { code: '62', name: 'Health care and social assistance' },
  ],
}

let storedCriteria: unknown = { all: [] }

function mockApi(role = 'compliance_staff') {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/api/user') return { data: { id: 2, name: 'User', email: 'u@example.test', role } }
    if (url === '/api/catalogs/organization') return { data: catalogs }
    if (url === '/api/compliance-requirements/7')
      return { data: { data: { id: 7, name: 'Large manufacturing safety program', applicability_criteria: storedCriteria } } }
    if (url === '/api/compliance-requirements')
      return { data: { data: [{ id: 7, name: 'Large manufacturing safety program', regulation: 'NOM-001', deadline: '2026-12-31', status: { code: 'pending', label: 'Pending' }, applies: true }] } }
    return { data: {} }
  })
  vi.mocked(api.post).mockImplementation(async (_url: string, body: unknown) => {
    const criteria = (body as { criteria: { all?: { op: string; value: number }[] } }).criteria
    const gte = criteria.all?.find((c) => c.op === 'gte')
    return { data: { applies: !gte || gte.value <= 120 } }
  })
}

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/requirements/7/criteria']}>
        <Routes>
          <Route path="/requirements/:id/criteria" element={<RequirementCriteriaPage />} />
          <Route path="/requirements" element={<RequirementListPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const optionTexts = (select: HTMLElement) => within(select).getAllByRole('option').map((o) => o.textContent)
const choose = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('RequirementCriteriaPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    storedCriteria = { all: [] }
  })

  it('shows the permission message to General Employees', async () => {
    mockApi('general_employee')
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent(M.forbidden)
  })

  it('loads the stored criteria and shows the live preview', async () => {
    storedCriteria = { all: [{ field: 'industry', op: 'in', value: ['manufacturing'] }, { field: 'employees_count', op: 'gte', value: 51 }] }
    mockApi()
    renderPage()

    expect(await screen.findByLabelText('Condition 1 field')).toHaveValue('industry')
    expect(screen.getByLabelText('Condition 1 operator')).toHaveValue('in')
    expect(screen.getByLabelText('Condition 2 value')).toHaveValue(51)
    expect(screen.getByRole('radio', { name: 'Match all conditions' })).toHaveAttribute('aria-checked', 'true')
    await waitFor(() => expect(screen.getByTestId('applicability-preview')).toHaveTextContent('Applies to the current organization: Yes'))

    fireEvent.change(screen.getByLabelText('Condition 2 value'), { target: { value: '251' } })
    await waitFor(() => expect(screen.getByTestId('applicability-preview')).toHaveTextContent('Applies to the current organization: No'))
  })

  it('offers operators and value inputs that depend on the field', async () => {
    mockApi()
    renderPage()
    fireEvent.click(await screen.findByLabelText('Use criteria'))

    choose('Condition 1 field', 'industry')
    expect(optionTexts(screen.getByLabelText('Condition 1 operator'))).toEqual(['Select', 'equals', 'is any of', 'is none of'])
    choose('Condition 1 operator', 'eq')
    expect(screen.getByLabelText('Condition 1 value').tagName).toBe('SELECT')
    choose('Condition 1 operator', 'in')
    expect(within(screen.getByRole('group', { name: 'Condition 1 value' })).getAllByRole('checkbox')).toHaveLength(2)

    choose('Condition 1 field', 'employees_count')
    expect(optionTexts(screen.getByLabelText('Condition 1 operator'))).toEqual(['Select', 'greater than or equal to', 'less than or equal to', 'between'])
    choose('Condition 1 operator', 'gte')
    expect(screen.getByLabelText('Condition 1 value')).toHaveAttribute('type', 'number')
    choose('Condition 1 operator', 'between')
    expect(screen.getByLabelText('Condition 1 value from')).toBeInTheDocument()
    expect(screen.getByLabelText('Condition 1 value to')).toBeInTheDocument()

    choose('Condition 1 field', 'business_activities')
    expect(optionTexts(screen.getByLabelText('Condition 1 operator'))).toEqual(['Select', 'contains any of'])
  })

  it('adds and removes conditions', async () => {
    mockApi()
    renderPage()
    fireEvent.click(await screen.findByLabelText('Use criteria'))

    fireEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    expect(screen.getAllByTestId('condition-row')).toHaveLength(2)
    fireEvent.click(screen.getByRole('button', { name: 'Remove condition 2' }))
    expect(screen.getAllByTestId('condition-row')).toHaveLength(1)
  })

  it.each([
    ['no conditions', 'remove-all', M.noConditions],
    ['condition without field', 'empty', M.selectField],
    ['condition without operator', 'field-only', M.selectOperator],
    ['condition without value', 'field-and-op', M.enterValue],
  ])('shows "%s" and does not save', async (_case, setup, message) => {
    mockApi()
    renderPage()
    fireEvent.click(await screen.findByLabelText('Use criteria'))
    if (setup === 'remove-all') fireEvent.click(screen.getByRole('button', { name: 'Remove condition 1' }))
    if (setup === 'field-only' || setup === 'field-and-op') choose('Condition 1 field', 'industry')
    if (setup === 'field-and-op') choose('Condition 1 operator', 'in')

    fireEvent.click(screen.getByRole('button', { name: 'Save criteria' }))

    expect(await screen.findByText(message)).toBeInTheDocument()
    expect(api.put).not.toHaveBeenCalled()
  })

  it('shows the server message for an operator not valid for the field', async () => {
    storedCriteria = { all: [{ field: 'industry', op: 'eq', value: 'retail' }] }
    mockApi()
    const response = {
      status: 422,
      data: { errors: { 'criteria.all.0.op': ['The operator gte cannot be used with industry.'] } },
      statusText: '',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }
    vi.mocked(api.put).mockRejectedValue(new AxiosError('Unprocessable', 'ERR_BAD_REQUEST', undefined, undefined, response))
    renderPage()
    await screen.findByLabelText('Condition 1 field')

    fireEvent.click(screen.getByRole('button', { name: 'Save criteria' }))

    expect(await screen.findByText('The operator gte cannot be used with industry.')).toBeInTheDocument()
  })

  it('saves the ADR-021 JSON and shows the toast on the updated requirement list', async () => {
    mockApi()
    vi.mocked(api.put).mockResolvedValue({ data: { data: {}, message: M.saved } })
    renderPage()
    fireEvent.click(await screen.findByLabelText('Use criteria'))
    choose('Condition 1 field', 'industry')
    choose('Condition 1 operator', 'in')
    fireEvent.click(within(screen.getByRole('group', { name: 'Condition 1 value' })).getByLabelText('Manufacturing'))
    fireEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    choose('Condition 2 field', 'employees_count')
    choose('Condition 2 operator', 'gte')
    fireEvent.change(screen.getByLabelText('Condition 2 value'), { target: { value: '51' } })

    fireEvent.click(screen.getByRole('button', { name: 'Save criteria' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Applicability criteria saved. The requirement list has been updated.')
    expect(api.put).toHaveBeenCalledWith('/api/compliance-requirements/7/criteria', {
      use_criteria: true,
      criteria: {
        all: [
          { field: 'industry', op: 'in', value: ['manufacturing'] },
          { field: 'employees_count', op: 'gte', value: 51 },
        ],
      },
    })
    expect(await screen.findByRole('heading', { name: 'Compliance requirements' })).toBeInTheDocument()
    expect(await screen.findAllByTestId('requirement-row')).toHaveLength(1)
  })

  it('sends "any" when "Match any condition" is selected and {"all": []} for every organization', async () => {
    storedCriteria = { all: [{ field: 'state', op: 'eq', value: '10' }] }
    mockApi()
    vi.mocked(api.put).mockResolvedValue({ data: { data: {}, message: M.saved } })
    renderPage()
    await screen.findByLabelText('Condition 1 field')

    fireEvent.click(screen.getByRole('radio', { name: 'Match any condition' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save criteria' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenLastCalledWith('/api/compliance-requirements/7/criteria', {
        use_criteria: true,
        criteria: { any: [{ field: 'state', op: 'eq', value: '10' }] },
      }),
    )
  })
})
