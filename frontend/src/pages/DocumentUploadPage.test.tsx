import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { BrowserRouter } from 'react-router-dom'
import { api } from '../lib/api'
import DocumentUploadPage, { MAX_FILE_SIZE } from './DocumentUploadPage'

const mockNavigate = vi.fn()
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

vi.mock('../lib/api', () => ({
  api: {
    post: vi.fn(),
  },
}))

describe('DocumentUploadPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('rejects unsupported extensions before sending with exact message', async () => {
    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    const file = new File(['image content'], 'image.png', { type: 'image/png' })
    const input = screen.getByTestId('file-input')

    fireEvent.change(input, { target: { files: [file] } })

    expect(
      await screen.findByText('Only PDF, DOCX and TXT files are allowed.'),
    ).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('rejects empty files before sending with exact message', async () => {
    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    const file = new File([], 'empty.txt', { type: 'text/plain' })
    const input = screen.getByTestId('file-input')

    fireEvent.change(input, { target: { files: [file] } })

    expect(await screen.findByText('The file is empty.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('rejects oversized files before sending with exact message', async () => {
    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    // Create file exceeding 26,214,400 bytes
    const oversizedFile = new File(['x'], 'large.pdf', { type: 'application/pdf' })
    Object.defineProperty(oversizedFile, 'size', { value: MAX_FILE_SIZE + 1 })

    const input = screen.getByTestId('file-input')
    fireEvent.change(input, { target: { files: [oversizedFile] } })

    expect(
      await screen.findByText('The file exceeds the 25 MB limit (26,214,400 bytes).'),
    ).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('accepts valid file selection and populates document name', async () => {
    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    const file = new File(['valid content'], 'Compliance-Manual.pdf', {
      type: 'application/pdf',
    })
    const input = screen.getByTestId('file-input')

    fireEvent.change(input, { target: { files: [file] } })

    expect(
      await screen.findByText(/Selected file: Compliance-Manual.pdf/),
    ).toBeInTheDocument()
    const nameInput = screen.getByLabelText('Document Name') as HTMLInputElement
    expect(nameInput.value).toBe('Compliance-Manual')
  })

  it('displays upload progress during upload and prevents duplicate submissions', async () => {
    let capturedProgressCallback: ((e: { loaded: number; total: number }) => void) | null = null

    vi.mocked(api.post).mockImplementation((_url, _data, config) => {
      capturedProgressCallback = config?.onUploadProgress as any
      return new Promise((resolve) => {
        setTimeout(() => {
          resolve({
            data: {
              message: 'Document uploaded successfully. It is now being processed.',
              id: 10,
            },
          })
        }, 100)
      })
    })

    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    const file = new File(['valid content'], 'doc.pdf', { type: 'application/pdf' })
    const fileInput = screen.getByTestId('file-input')
    fireEvent.change(fileInput, { target: { files: [file] } })

    const submitBtn = screen.getByRole('button', { name: /Upload document/i })
    fireEvent.click(submitBtn)

    // Simulate upload progress
    if (capturedProgressCallback) {
      capturedProgressCallback({ loaded: 50, total: 100 })
    }

    await waitFor(() => {
      expect(screen.getByRole('progressbar')).toBeInTheDocument()
      expect(screen.getByText('Uploading...')).toBeDisabled()
    })
  })

  it('displays server-side validation error messages', async () => {
    vi.mocked(api.post).mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          message: 'The file was rejected because it contains executable content.',
        },
      },
    })

    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    const file = new File(['content'], 'test.docx', {
      type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    })
    const fileInput = screen.getByTestId('file-input')
    fireEvent.change(fileInput, { target: { files: [file] } })

    const submitBtn = screen.getByRole('button', { name: /Upload document/i })
    fireEvent.click(submitBtn)

    expect(
      await screen.findByText('The file was rejected because it contains executable content.'),
    ).toBeInTheDocument()
  })

  it('displays success toast and redirects to /documents/{id} after successful upload', async () => {
    vi.mocked(api.post).mockResolvedValue({
      data: {
        message: 'Document uploaded successfully. It is now being processed.',
        id: 77,
      },
    })

    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    const file = new File(['hello'], 'policy.txt', { type: 'text/plain' })
    const fileInput = screen.getByTestId('file-input')
    fireEvent.change(fileInput, { target: { files: [file] } })

    const submitBtn = screen.getByRole('button', { name: /Upload document/i })
    fireEvent.click(submitBtn)

    expect(
      await screen.findByText('Document uploaded successfully. It is now being processed.'),
    ).toBeInTheDocument()

    await waitFor(() => {
      expect(mockNavigate).toHaveBeenCalledWith('/documents/77')
    })
  })

  it('hides upload button and shows exact message for a general employee', () => {
    render(
      <BrowserRouter>
        <DocumentUploadPage currentUserRole="general_employee" />
      </BrowserRouter>,
    )

    expect(
      screen.getByText('You do not have permission to upload documents.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Upload document/i })).not.toBeInTheDocument()
  })

  it('handles 403 server response by hiding upload button and displaying exact message', async () => {
    vi.mocked(api.post).mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 403,
        data: {
          message: 'You do not have permission to upload documents.',
        },
      },
    })

    render(
      <BrowserRouter>
        <DocumentUploadPage />
      </BrowserRouter>,
    )

    const file = new File(['valid'], 'doc.pdf', { type: 'application/pdf' })
    const fileInput = screen.getByTestId('file-input')
    fireEvent.change(fileInput, { target: { files: [file] } })

    const submitBtn = screen.getByRole('button', { name: /Upload document/i })
    fireEvent.click(submitBtn)

    expect(
      await screen.findByText('You do not have permission to upload documents.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Upload document/i })).not.toBeInTheDocument()
  })
})
