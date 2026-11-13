import { Link } from 'react-router-dom'

export default function ForbiddenPage() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <section className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 text-center shadow-md">
        <h1 className="text-3xl font-bold text-slate-800">Access denied</h1>
        <p className="mt-3 text-slate-600">You do not have permission to view this page.</p>
        <Link
          to="/"
          className="mt-6 inline-block rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white transition-colors hover:bg-indigo-700"
        >
          Go to home
        </Link>
      </section>
    </main>
  )
}
