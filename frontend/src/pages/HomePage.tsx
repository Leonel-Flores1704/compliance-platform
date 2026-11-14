import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { api } from '../lib/api'
import { useCan } from '../hooks/useCan'

type Health = { status: string }

export default function HomePage() {
  const canViewDashboard = useCan('view-dashboard')
  const canUploadDocuments = useCan('upload-documents')
  const canUseAssistant = useCan('use-ai-assistant')
  const canManageUsers = useCan('manage-users')
  const { data, isLoading } = useQuery<Health>({
    queryKey: ['health'],
    retry: false,
    queryFn: async () => {
      try {
        // const res = await api.get<Health>('/api/health')
        const res = await api.get<Health>('http://localhost:8000/api/health')
        return res.data
      } catch (err) {
        if (axios.isAxiosError(err) && err.response?.data?.status) {
          return err.response.data as Health
        }
        return { status: 'unreachable' }
      }
    },
  })

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-6 p-6 bg-slate-50">
      <div className="bg-white p-8 rounded-xl shadow-md max-w-md w-full text-center space-y-4 border border-slate-200">
        <h1 className="text-3xl font-bold text-slate-800">Compliance Platform</h1>
        <div className="inline-block px-3 py-1 rounded-full text-sm font-medium bg-slate-100 text-slate-700">
          {isLoading ? 'Checking API...' : `API status: ${data?.status}`}
        </div>

        <div className="pt-4 border-t border-slate-100 space-y-3">
          <p className="text-sm text-slate-500">Módulos disponibles:</p>
          {canViewDashboard && <a href="/dashboard" className="block w-full rounded-lg bg-slate-700 px-4 py-2.5 font-medium text-white transition-colors hover:bg-slate-800">Dashboard</a>}
          {canUploadDocuments && <a href="/documents/new" className="block w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white transition-colors hover:bg-indigo-700">Subir Nuevo Documento</a>}
          {canUseAssistant && <a href="/assistant" className="block w-full rounded-lg bg-slate-100 px-4 py-2.5 font-medium text-slate-700 transition-colors hover:bg-slate-200">AI Assistant</a>}
          {canManageUsers && <a href="/admin/users" className="block w-full rounded-lg bg-slate-100 px-4 py-2.5 font-medium text-slate-700 transition-colors hover:bg-slate-200">Users</a>}
          <a
            href="/login"
            className="block w-full py-2 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-lg transition-colors text-sm"
          >
            Cambiar de Usuario (/login)
          </a>
        </div>
      </div>
    </main>
  )
}
