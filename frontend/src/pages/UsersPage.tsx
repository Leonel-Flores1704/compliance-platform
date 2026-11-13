import { FormEvent, useEffect, useState } from 'react'
import { api } from '../lib/api'

type Role = 'administrator' | 'compliance_staff' | 'general_employee'
type User = { id: number; name: string; email: string; role: Role; is_active: boolean; last_login_at: string | null; must_change_password: boolean }
type Page = { data: User[]; current_page: number; last_page: number }

const emptyForm = { name: '', email: '', role: '' as Role | '', password: '', must_change_password: true }
const roleLabels: Record<Role, string> = { administrator: 'Administrator', compliance_staff: 'Compliance Staff', general_employee: 'General Employee' }

function formatLastSignIn(value: string | null) {
  return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : 'Never'
}

function temporaryPassword() {
  return `${crypto.randomUUID().replace(/-/g, '').slice(0, 8)}Aa1!`
}

export default function UsersPage() {
  const [page, setPage] = useState<Page>({ data: [], current_page: 1, last_page: 1 })
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<User | null | undefined>(undefined)
  const [form, setForm] = useState(emptyForm)
  const [error, setError] = useState<string | null>(null)
  const [toast, setToast] = useState<string | null>(null)

  const load = async (requestedPage = page.current_page, term = search) => {
    const response = await api.get<Page>('/api/users', { params: { page: requestedPage, search: term } })
    setPage(response.data)
  }

  useEffect(() => { void load(1, search) }, [search]) // Search always starts at the first page.

  const openCreate = () => { setEditing(null); setForm({ ...emptyForm, password: temporaryPassword() }); setError(null) }
  const openEdit = (user: User) => { setEditing(user); setForm({ name: user.name, email: user.email, role: user.role, password: '', must_change_password: user.must_change_password }); setError(null) }
  const closeModal = () => setEditing(undefined)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError(null)
    try {
      const values = { ...form, ...(form.password ? {} : { password: undefined }) }
      const response = editing
        ? await api.patch(`/api/users/${editing.id}`, values)
        : await api.post('/api/users', form)
      setToast(response.data.message)
      closeModal()
      await load()
    } catch (reason: unknown) {
      const data = (reason as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data
      setError(data?.errors ? Object.values(data.errors)[0]?.[0] ?? data.message ?? 'Unable to save user.' : data?.message ?? 'Unable to save user.')
    }
  }

  const changeStatus = async (user: User) => {
    if (user.is_active && !window.confirm(`Deactivate ${user.name}? They will no longer be able to sign in.`)) return
    try {
      const response = await api.post(`/api/users/${user.id}/${user.is_active ? 'deactivate' : 'activate'}`)
      setToast(response.data.message)
      await load()
    } catch (reason: unknown) {
      setError((reason as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data?.errors?.user?.[0]
        ?? (reason as { response?: { data?: { message?: string } } }).response?.data?.message
        ?? 'Unable to update user.')
    }
  }

  return (
    <main className="min-h-screen bg-slate-50 p-6">
      <section className="mx-auto max-w-6xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
          <div><h1 className="text-2xl font-bold text-slate-800">Users</h1><p className="text-sm text-slate-600">Manage organization accounts and roles.</p></div>
          <button className="rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white" onClick={openCreate}>Create user</button>
        </div>
        {toast && <p role="status" className="mb-3 rounded bg-green-50 p-3 text-green-800">{toast}</p>}
        {error && <p role="alert" className="mb-3 rounded bg-red-50 p-3 text-red-800">{error}</p>}
        <input aria-label="Search users" className="mb-4 w-full rounded border p-2" placeholder="Search by name or email" value={search} onChange={(event) => setSearch(event.target.value)} />
        <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b text-slate-600"><tr><th className="p-3">Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th /></tr></thead>
          <tbody>{page.data.map((user) => <tr key={user.id} className="border-b"><td className="p-3 font-medium">{user.name}</td><td>{user.email}</td><td>{roleLabels[user.role]}</td><td>{user.is_active ? 'Active' : 'Inactive'}</td><td>{formatLastSignIn(user.last_login_at)}</td><td className="space-x-2 whitespace-nowrap"><button className="text-indigo-700" onClick={() => openEdit(user)}>Edit</button><button className="text-indigo-700" onClick={() => void changeStatus(user)}>{user.is_active ? 'Deactivate' : 'Reactivate'}</button></td></tr>)}</tbody>
        </table></div>
        <div className="mt-4 flex justify-between"><button disabled={page.current_page <= 1} onClick={() => void load(page.current_page - 1)}>Previous</button><span>Page {page.current_page} of {page.last_page}</span><button disabled={page.current_page >= page.last_page} onClick={() => void load(page.current_page + 1)}>Next</button></div>
      </section>
      {editing !== undefined && <div className="fixed inset-0 flex items-center justify-center bg-slate-900/40 p-4"><form className="w-full max-w-lg space-y-4 rounded-xl bg-white p-6 shadow-xl" onSubmit={submit}><div className="flex justify-between"><h2 className="text-xl font-bold">{editing ? 'Edit user' : 'Create user'}</h2><button type="button" onClick={closeModal}>Close</button></div>
        <label className="block">Name<input className="mt-1 w-full rounded border p-2" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></label>
        <label className="block">Email<input type="email" className="mt-1 w-full rounded border p-2" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label>
        <label className="block">Role<select className="mt-1 w-full rounded border p-2" value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value as Role })}><option value="">Select a role</option>{Object.entries(roleLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label className="block">{editing ? 'New temporary password (optional)' : 'Temporary password'}<div className="mt-1 flex gap-2"><input type="text" className="w-full rounded border p-2" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} /><button type="button" className="rounded border px-3" onClick={() => setForm({ ...form, password: temporaryPassword() })}>Generate</button></div></label>
        <label className="flex gap-2"><input type="checkbox" checked={form.must_change_password} onChange={(event) => setForm({ ...form, must_change_password: event.target.checked })} />Require password change at next sign-in</label>
        {error && <p role="alert" className="text-red-700">{error}</p>}<button className="rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white" type="submit">{editing ? 'Save changes' : 'Create user'}</button>
      </form></div>}
    </main>
  )
}
