import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import axios from 'axios'
import { api } from '../lib/api'

export interface DocumentData {
  id: number | string
  name: string
  category: string
  category_name?: string
  access_level: string
  visibility?: string
  is_archived?: boolean
  current_version?: string
  expiration_date?: string | null
  expiration_date_formatted?: string
  upload_date?: string | null
  uploaded_at?: string | null
  uploaded_by?: string
  processing_status?: string
  status?: string
}

export default function DocumentDetailPage() {
  const { id } = useParams<{ id: string }>()

  const [document, setDocument] = useState<DocumentData | null>(null)
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  useEffect(() => {
    let isMounted = true

    const fetchDocument = async () => {
      try {
        setLoading(true)
        setErrorMessage(null)
        const response = await api.get(`/api/documents/${id}`)
        if (isMounted) {
          const doc = response.data?.document || response.data
          setDocument(doc)
        }
      } catch (err) {
        if (isMounted) {
          if (axios.isAxiosError(err)) {
            if (err.response?.status === 403) {
              setErrorMessage('You do not have permission to view this document.')
            } else if (err.response?.status === 404) {
              setErrorMessage('Document not found.')
            } else {
              setErrorMessage(err.response?.data?.message || 'Failed to load document details.')
            }
          } else {
            setErrorMessage('An unexpected error occurred.')
          }
        }
      } finally {
        if (isMounted) {
          setLoading(false)
        }
      }
    }

    if (id) {
      fetchDocument()
    }

    return () => {
      isMounted = false
    }
  }, [id])

  if (loading) {
    return (
      <div className="max-w-3xl mx-auto py-8 px-4" role="status">
        <p className="text-gray-600">Loading document details...</p>
      </div>
    )
  }

  if (errorMessage) {
    return (
      <div className="max-w-3xl mx-auto py-8 px-4">
        <div
          role="alert"
          className="p-4 rounded-md bg-red-50 border border-red-200 text-red-700 font-medium mb-6"
        >
          {errorMessage}
        </div>
        <Link
          to="/documents/new"
          className="text-blue-600 hover:text-blue-800 underline font-medium"
        >
          Upload a document
        </Link>
      </div>
    )
  }

  if (!document) {
    return null
  }

  const categoryDisplay = document.category_name || document.category
  const visibilityDisplay =
    document.visibility || (document.access_level === 'compliance_only' ? 'Compliance only' : 'All staff')
  const versionDisplay = document.current_version || '1.0.0'
  const expirationDisplay = document.expiration_date_formatted || (document.expiration_date ? document.expiration_date : 'No expiration')
  const uploadedByDisplay = document.uploaded_by || 'Unknown'
  const uploadDateDisplay = document.upload_date || document.uploaded_at || 'Unknown'
  const statusDisplay = document.processing_status || document.status || 'Queued'

  return (
    <div className="max-w-3xl mx-auto py-8 px-4">
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900" data-testid="doc-name">
          {document.name}
        </h1>
        <Link
          to="/documents/new"
          className="text-sm font-medium text-blue-600 hover:text-blue-800 underline"
        >
          Upload another document
        </Link>
      </div>

      <div className="bg-white shadow rounded-lg border border-gray-200 divide-y divide-gray-200">
        <div className="px-6 py-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
              Category
            </span>
            <span className="text-sm font-medium text-gray-900" data-testid="doc-category">
              {categoryDisplay}
            </span>
          </div>

          <div>
            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
              Current version
            </span>
            <span className="text-sm font-medium text-gray-900" data-testid="doc-version">
              {versionDisplay}
            </span>
          </div>

          <div>
            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
              Visibility
            </span>
            <span className="text-sm font-medium text-gray-900" data-testid="doc-visibility">
              {visibilityDisplay}
            </span>
          </div>

          <div>
            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
              Expiration date
            </span>
            <span className="text-sm font-medium text-gray-900" data-testid="doc-expiration">
              {expirationDisplay}
            </span>
          </div>

          <div>
            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
              Uploaded by
            </span>
            <span className="text-sm font-medium text-gray-900" data-testid="doc-uploaded-by">
              {uploadedByDisplay}
            </span>
          </div>

          <div>
            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
              Upload date
            </span>
            <span className="text-sm font-medium text-gray-900" data-testid="doc-upload-date">
              {uploadDateDisplay}
            </span>
          </div>

          <div className="sm:col-span-2">
            <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider block">
              Processing status
            </span>
            <span
              className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize mt-1 bg-blue-100 text-blue-800"
              data-testid="doc-status"
            >
              {statusDisplay}
            </span>
          </div>
        </div>
      </div>
    </div>
  )
}
