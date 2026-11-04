import { useParams, Link } from 'react-router-dom'

export default function DocumentDetailPage() {
  const { id } = useParams<{ id: string }>()

  return (
    <div className="max-w-2xl mx-auto py-8 px-4">
      <h1 className="text-2xl font-bold mb-4 text-gray-900">Document #{id}</h1>
      <p className="text-gray-600 mb-6">Status: Queued for processing</p>
      <Link
        to="/documents/new"
        className="text-blue-600 hover:text-blue-800 underline font-medium"
      >
        Upload another document
      </Link>
    </div>
  )
}
