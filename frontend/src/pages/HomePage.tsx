import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { api } from '../lib/api'

type Health = { status: string }

export default function HomePage() {
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
          <a
            href="/documents/new"
            className="block w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition-colors"
          >
            Subir Nuevo Documento
          </a>
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