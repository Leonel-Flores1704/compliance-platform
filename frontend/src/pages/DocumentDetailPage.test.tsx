import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../lib/api'
import DocumentDetailPage from './DocumentDetailPage'

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
  },
}))

describe('DocumentDetailPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  afterEach(() => {
    cleanup()
  })

  it('renders all document metadata correctly for authorized users', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 15,
        name: 'Fire Safety Operating License',
        category: 'permit_license',
        category_name: 'Permit/License',
        access_level: 'all_staff',
        visibility: 'All staff',
        current_version: '1.2.0',
        expiration_date: '2027-05-15',
        expiration_date_formatted: '15/05/2027',
        uploaded_by: 'Jane Doe',
        upload_date: '04/11/2026 10:30',
        processing_status: 'processed',
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/15']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    // Name
    expect(await screen.findByTestId('doc-name')).toHaveTextContent(
      'Fire Safety Operating License',
    )
    // Category
    expect(screen.getByTestId('doc-category')).toHaveTextContent('Permit/License')
    // Current version
    expect(screen.getByTestId('doc-version')).toHaveTextContent('1.2.0')
    // Visibility
    expect(screen.getByTestId('doc-visibility')).toHaveTextContent('All staff')
    // Expiration date
    expect(screen.getByTestId('doc-expiration')).toHaveTextContent('15/05/2027')
    // Uploaded by
    expect(screen.getByTestId('doc-uploaded-by')).toHaveTextContent('Jane Doe')
    // Upload date
    expect(screen.getByTestId('doc-upload-date')).toHaveTextContent('04/11/2026 10:30')
    // Processing status
    expect(screen.getByTestId('doc-status')).toHaveTextContent('processed')
  })

  it('renders "No expiration" when expiration date is not set', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        id: 20,
        name: 'Internal Information Security Policy',
        category: 'policy',
        category_name: 'Policy',
        access_level: 'compliance_only',
        visibility: 'Compliance only',
        current_version: '1.0',
        expiration_date: null,
        expiration_date_formatted: 'No expiration',
        uploaded_by: 'Admin User',
        upload_date: '01/11/2026 09:00',
        processing_status: 'queued',
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/20']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByTestId('doc-name')).toHaveTextContent(
      'Internal Information Security Policy',
    )
    expect(screen.getByTestId('doc-expiration')).toHaveTextContent('No expiration')
    expect(screen.getByTestId('doc-visibility')).toHaveTextContent('Compliance only')
  })

  it('displays forbidden error message when user lacks permission to view document', async () => {
    vi.mocked(api.get).mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 403,
        data: {
          message: 'You do not have permission to view this document.',
        },
      },
    })

    render(
      <MemoryRouter initialEntries={['/documents/99']}>
        <Routes>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(
      await screen.findByText('You do not have permission to view this document.'),
    ).toBeInTheDocument()
  })
})
