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
        const res = await api.get<Health>('/api/health')
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
    <main className="flex min-h-screen flex-col items-center justify-center gap-4">
      <h1 className="text-3xl font-bold">Compliance Platform</h1>
      <p className="text-lg">
        {isLoading ? 'Checking API...' : `API status: ${data?.status}`}
      </p>
    </main>
  )
}