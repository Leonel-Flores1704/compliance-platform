import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import HomePage from './HomePage'

vi.mock('../lib/api', () => ({ api: { get: vi.fn() } }))

describe('HomePage', () => {
  it('shows the title and the API status', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { status: 'ok' } })

    render(
      <QueryClientProvider client={new QueryClient()}>
        <HomePage />
      </QueryClientProvider>,
    )

    expect(screen.getByText('Compliance Platform')).toBeInTheDocument()
    expect(await screen.findByText('API status: ok')).toBeInTheDocument()
  })
})