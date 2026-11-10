import { useQuery } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router-dom'
import StatusBadge from '../components/StatusBadge'
import Toast from '../components/Toast'
import { fetchRequirementList } from '../features/criteria/api'
import { isoToDisplayDate } from '../features/requirements/dates'

// Requirements that apply to the organization (applies = true, FR-007).
export default function RequirementListPage() {
  const location = useLocation()
  const toast = (location.state as { toast?: string } | null)?.toast
  const { data: requirements, isLoading } = useQuery({ queryKey: ['requirements', 'list'], queryFn: fetchRequirementList })

  return (
    <main className="mx-auto max-w-5xl p-8">
      {toast && <Toast message={toast} />}
      <h1 className="text-2xl font-bold">Compliance requirements</h1>
      {isLoading && <p className="mt-4">Loading...</p>}
      <table className="mt-6 w-full border-collapse text-sm">
        <thead>
          <tr className="border-b border-gray-300 text-left text-gray-600">
            <th className="py-2 pr-4 font-medium">Name</th>
            <th className="py-2 pr-4 font-medium">Regulation</th>
            <th className="py-2 pr-4 font-medium">Deadline</th>
            <th className="py-2 pr-4 font-medium">Status</th>
            <th className="py-2 font-medium">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {(requirements ?? []).map((requirement) => (
            <tr key={requirement.id} data-testid="requirement-row" className="border-b border-gray-100">
              <td className="py-2 pr-4">
                <Link to={`/requirements/${requirement.id}`} className="text-blue-700 hover:underline">
                  {requirement.name}
                </Link>
              </td>
              <td className="py-2 pr-4">{requirement.regulation}</td>
              <td className="py-2 pr-4">{isoToDisplayDate(requirement.deadline)}</td>
              <td className="py-2 pr-4">
                <StatusBadge code={requirement.status.code} label={requirement.status.label} />
              </td>
              <td className="py-2 text-right">
                <Link to={`/requirements/${requirement.id}/criteria`} className="text-xs text-blue-700 hover:underline">
                  Applicability criteria
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </main>
  )
}
