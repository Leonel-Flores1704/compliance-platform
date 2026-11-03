import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import HomePage from './HomePage'

vi.mock('../lib/api', () => ({ api: { get: vi.fn() } }))

function mockApi(role: string | null) {
  vi.mocked(api.get).mockImplementation(async (url: string) => {
    if (url === '/api/user') {
      if (!role) throw new Error('Unauthenticated')
      return { data: { id: 1, name: 'User', email: 'u@example.test', role } }
    }
    return { data: { status: 'ok' } }
  })
}

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <HomePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('HomePage', () => {
  beforeEach(() => vi.mocked(api.get).mockReset())

  it('shows the title and the API status', async () => {
    mockApi(null)
    renderPage()

    expect(screen.getByText('Compliance Platform')).toBeInTheDocument()
    expect(await screen.findByText('API status: ok')).toBeInTheDocument()
  })

  it('shows the "Register organization" button to Administrators', async () => {
    mockApi('administrator')
    renderPage()

    expect(await screen.findByRole('link', { name: 'Register organization' })).toHaveAttribute('href', '/organizations/new')
  })

  it.each(['compliance_staff', 'general_employee'])('hides the "Register organization" button from %s', async (role) => {
    mockApi(role)
    renderPage()

    await screen.findByText('API status: ok')
    await vi.waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/user'))
    expect(screen.queryByRole('link', { name: 'Register organization' })).not.toBeInTheDocument()
  })
})

describe('HomePage requirement link', () => {
  beforeEach(() => vi.mocked(api.get).mockReset())

  it.each(['administrator', 'compliance_staff'])('shows "New compliance requirement" to %s', async (role) => {
    mockApi(role)
    renderPage()

    expect(await screen.findByRole('link', { name: 'New compliance requirement' })).toHaveAttribute('href', '/requirements/new')
  })

  it('hides "New compliance requirement" from general_employee', async () => {
    mockApi('general_employee')
    renderPage()

    await screen.findByText('API status: ok')
    await vi.waitFor(() => expect(api.get).toHaveBeenCalledWith('/api/user'))
    expect(screen.queryByRole('link', { name: 'New compliance requirement' })).not.toBeInTheDocument()
  })
})
