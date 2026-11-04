import React, { useState } from 'react'
import { useDropzone } from 'react-dropzone'
import { useNavigate } from 'react-router-dom'
import axios from 'axios'
import { api } from '../lib/api'

export const MAX_FILE_SIZE = 26214400 // Exactly 25 MiB (26,214,400 bytes)
const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'txt']
const CATEGORIES_WITH_EXPIRATION = ['permit_license', 'certificate']

export interface DocumentUploadPageProps {
  currentUserRole?: string
}

export default function DocumentUploadPage({ currentUserRole }: DocumentUploadPageProps) {
  const navigate = useNavigate()

  const [file, setFile] = useState<File | null>(null)
  const [name, setName] = useState('')
  const [category, setCategory] = useState('policy')
  const [version, setVersion] = useState('1.0.0')
  const [accessLevel, setAccessLevel] = useState<'all_staff' | 'compliance_only'>('all_staff')
  const [expirationDate, setExpirationDate] = useState('')

  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [successToast, setSuccessToast] = useState<string | null>(null)
  const [isUploading, setIsUploading] = useState(false)
  const [uploadProgress, setUploadProgress] = useState<number | null>(null)
  const [isForbidden, setIsForbidden] = useState(currentUserRole === 'general_employee')

  const validateFile = (selectedFile: File): string | null => {
    if (selectedFile.size === 0) {
      return 'The file is empty.'
    }
    if (selectedFile.size > MAX_FILE_SIZE) {
      return 'The file exceeds the 25 MB limit (26,214,400 bytes).'
    }
    const ext = selectedFile.name.split('.').pop()?.toLowerCase() ?? ''
    if (!ALLOWED_EXTENSIONS.includes(ext)) {
      return 'Only PDF, DOCX and TXT files are allowed.'
    }
    return null
  }

  const onDrop = (acceptedFiles: File[]) => {
    if (acceptedFiles.length === 0) return
    const chosenFile = acceptedFiles[0]
    const error = validateFile(chosenFile)
    if (error) {
      setErrorMessage(error)
      setFile(null)
      return
    }
    setErrorMessage(null)
    setFile(chosenFile)
    if (!name) {
      // Auto-populate document name without extension as a convenience
      const baseName = chosenFile.name.replace(/\.[^/.]+$/, '')
      setName(baseName)
    }
  }

  const { getRootProps, getInputProps, isDragActive, open } = useDropzone({
    onDrop,
    noClick: true, // We allow both click and explicit Browse button
    noKeyboard: true,
    multiple: false,
    accept: {
      'application/pdf': ['.pdf'],
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['.docx'],
      'text/plain': ['.txt'],
    },
  })

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (isUploading) return

    if (!file) {
      setErrorMessage('Select a file to upload.')
      return
    }

    const fileError = validateFile(file)
    if (fileError) {
      setErrorMessage(fileError)
      return
    }

    if (!name.trim()) {
      setErrorMessage('Document name is required.')
      return
    }

    if (name.trim().length < 3 || name.trim().length > 150) {
      setErrorMessage('Document name must be between 3 and 150 characters.')
      return
    }

    if (!category) {
      setErrorMessage('Select a category.')
      return
    }

    if (!version.trim()) {
      setErrorMessage('Version is required.')
      return
    }

    const versionRegex = /^\d+(\.\d+){0,2}$/
    if (!versionRegex.test(version.trim())) {
      setErrorMessage('Version must use the format 1, 1.0 or 1.0.0.')
      return
    }

    if (CATEGORIES_WITH_EXPIRATION.includes(category) && !expirationDate) {
      const catDisplayName = category === 'permit_license' ? 'Permit/License' : 'Certificate'
      setErrorMessage(`Expiration date is required for ${catDisplayName} documents.`)
      return
    }

    if (expirationDate) {
      const today = new Date()
      today.setHours(0, 0, 0, 0)
      const selectedDate = new Date(expirationDate + 'T00:00:00')
      if (selectedDate <= today) {
        setErrorMessage('Expiration date must be a future date.')
        return
      }
    }

    setErrorMessage(null)
    setIsUploading(true)
    setUploadProgress(0)

    const formData = new FormData()
    formData.append('file', file)
    formData.append('name', name.trim())
    formData.append('category', category)
    formData.append('version', version.trim())
    formData.append('access_level', accessLevel)
    if (expirationDate) {
      formData.append('expiration_date', expirationDate)
    }

    try {
      const response = await api.post('/api/documents', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
        onUploadProgress: (progressEvent) => {
          if (progressEvent.total) {
            const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total)
            setUploadProgress(percent)
          }
        },
      })

      const successMsg =
        response.data?.message || 'Document uploaded successfully. It is now being processed.'
      setSuccessToast(successMsg)

      const docId = response.data?.id || response.data?.document?.id
      if (docId) {
        setTimeout(() => {
          navigate(`/documents/${docId}`)
        }, 300)
      }
    } catch (err) {
      if (axios.isAxiosError(err)) {
        if (err.response?.status === 403) {
          setIsForbidden(true)
          setErrorMessage(
            err.response.data?.message || 'You do not have permission to upload documents.',
          )
        } else if (err.response?.status === 413) {
          setErrorMessage('The file exceeds the 25 MB limit (26,214,400 bytes).')
        } else if (err.response?.data?.message) {
          setErrorMessage(err.response.data.message)
        } else {
          setErrorMessage('An error occurred while uploading the document.')
        }
      } else {
        setErrorMessage('An unexpected error occurred.')
      }
    } finally {
      setIsUploading(false)
    }
  }

  return (
    <div className="max-w-2xl mx-auto py-8 px-4">
      <h1 className="text-2xl font-bold mb-6 text-gray-900">Upload New Document</h1>

      {isForbidden && (
        <div
          role="alert"
          className="mb-6 p-4 rounded-md bg-red-50 border border-red-200 text-red-700 font-medium"
        >
          You do not have permission to upload documents.
        </div>
      )}

      {errorMessage && !isForbidden && (
        <div
          role="alert"
          className="mb-6 p-4 rounded-md bg-red-50 border border-red-200 text-red-700 font-medium"
        >
          {errorMessage}
        </div>
      )}

      {successToast && (
        <div
          role="status"
          className="mb-6 p-4 rounded-md bg-green-50 border border-green-200 text-green-700 font-medium"
        >
          {successToast}
        </div>
      )}

      {!isForbidden && (
        <form noValidate onSubmit={handleSubmit} className="space-y-6">
          {/* Dropzone */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Document File (PDF, DOCX, TXT — max 25 MB)
            </label>
            <div
              {...getRootProps()}
              className={`border-2 border-dashed rounded-lg p-6 flex flex-col items-center justify-center text-center cursor-pointer transition-colors ${
                isDragActive
                  ? 'border-blue-500 bg-blue-50'
                  : 'border-gray-300 hover:border-gray-400 bg-gray-50'
              }`}
            >
              <input {...getInputProps()} data-testid="file-input" />
              <p className="text-gray-600 mb-2">
                {isDragActive
                  ? 'Drop the document here...'
                  : 'Drag & drop your document here, or browse files'}
              </p>
              <button
                type="button"
                onClick={open}
                className="mt-2 px-4 py-2 text-sm font-medium text-blue-600 bg-white border border-blue-600 rounded-md hover:bg-blue-50 focus:outline-none"
              >
                Browse files
              </button>
              {file && (
                <div className="mt-3 text-sm font-semibold text-gray-800">
                  Selected file: {file.name} ({(file.size / 1024).toFixed(1)} KB)
                </div>
              )}
            </div>
          </div>

          {/* Document Name */}
          <div>
            <label htmlFor="doc-name" className="block text-sm font-medium text-gray-700 mb-1">
              Document Name
            </label>
            <input
              id="doc-name"
              type="text"
              maxLength={150}
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Environmental Health & Safety Policy"
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              disabled={isUploading}
            />
          </div>

          {/* Category */}
          <div>
            <label htmlFor="doc-category" className="block text-sm font-medium text-gray-700 mb-1">
              Category
            </label>
            <select
              id="doc-category"
              data-testid="category-select"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              disabled={isUploading}
            >
              <option value="policy">Policy</option>
              <option value="permit_license">Permit/License (Requires Expiration)</option>
              <option value="contract">Contract</option>
              <option value="certificate">Certificate (Requires Expiration)</option>
              <option value="report">Report</option>
              <option value="regulation">Regulation</option>
              <option value="other">Other</option>
            </select>
          </div>

          {/* Version */}
          <div>
            <label htmlFor="doc-version" className="block text-sm font-medium text-gray-700 mb-1">
              Version
            </label>
            <input
              id="doc-version"
              data-testid="version-input"
              type="text"
              required
              value={version}
              onChange={(e) => setVersion(e.target.value)}
              placeholder="e.g. 1, 1.0 or 1.0.0"
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              disabled={isUploading}
            />
          </div>

          {/* Visibility */}
          <div>
            <label htmlFor="doc-access" className="block text-sm font-medium text-gray-700 mb-1">
              Visibility
            </label>
            <select
              id="doc-access"
              data-testid="visibility-select"
              value={accessLevel}
              onChange={(e) => setAccessLevel(e.target.value as 'all_staff' | 'compliance_only')}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              disabled={isUploading}
            >
              <option value="all_staff">All staff</option>
              <option value="compliance_only">Compliance only</option>
            </select>
          </div>

          {/* Expiration Date */}
          <div>
            <label htmlFor="doc-expiration" className="block text-sm font-medium text-gray-700 mb-1">
              Expiration Date {CATEGORIES_WITH_EXPIRATION.includes(category) && <span className="text-red-500">*</span>}
            </label>
            <input
              id="doc-expiration"
              type="date"
              value={expirationDate}
              onChange={(e) => setExpirationDate(e.target.value)}
              required={CATEGORIES_WITH_EXPIRATION.includes(category)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
              disabled={isUploading}
            />
          </div>

          {/* Progress Bar */}
          {isUploading && uploadProgress !== null && (
            <div className="space-y-1">
              <div className="flex justify-between text-xs font-semibold text-gray-600">
                <span>Uploading document...</span>
                <span>{uploadProgress}%</span>
              </div>
              <div className="w-full bg-gray-200 rounded-full h-2.5">
                <div
                  role="progressbar"
                  aria-valuenow={uploadProgress}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  className="bg-blue-600 h-2.5 rounded-full transition-all duration-300"
                  style={{ width: `${uploadProgress}%` }}
                />
              </div>
            </div>
          )}

          {/* Submit Button */}
          <button
            type="submit"
            disabled={isUploading}
            className={`w-full py-2.5 px-4 rounded-md font-medium text-white transition-colors ${
              isUploading
                ? 'bg-blue-400 cursor-not-allowed'
                : 'bg-blue-600 hover:bg-blue-700 focus:ring-2 focus:ring-offset-2 focus:ring-blue-500'
            }`}
          >
            {isUploading ? 'Uploading...' : 'Upload document'}
          </button>
        </form>
      )}
    </div>
  )
}
