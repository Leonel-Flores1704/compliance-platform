import { useQuery } from '@tanstack/react-query'
import { Link, useLocation, useParams } from 'react-router-dom'
import StatusBadge from '../components/StatusBadge'
import Toast from '../components/Toast'
import { fetchRequirement } from '../features/requirements/api'
import { isoToDisplayDate } from '../features/requirements/dates'
import { REQUIREMENT_LABELS as L } from '../features/requirements/messages'
import StatusChanger from '../features/requirements/StatusChanger'
import { canCreateRequirements, useCurrentUser } from '../lib/auth'

export default function RequirementDetailPage() {
  const { id = '' } = useParams()
  const location = useLocation()
  const toast = (location.state as { toast?: string } | null)?.toast
  const { data: user } = useCurrentUser()

  const { data: requirement, isLoading, isError } = useQuery({
    queryKey: ['requirements', id],
    queryFn: () => fetchRequirement(id),
  })

  return (
    <main className="mx-auto max-w-3xl p-8">
      {toast && <Toast message={toast} />}
      {isLoading && <p>Loading...</p>}
      {isError && <p role="alert">The compliance requirement could not be loaded.</p>}
      {requirement && (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-bold">{requirement.name}</h1>
            <StatusBadge code={requirement.status.code} label={requirement.status.label} />
          </div>
          {/* ADR-005, function 4: only Compliance Staff can change the status. */}
          {user?.role === 'compliance_staff' && <StatusChanger requirement={requirement} />}
          {canCreateRequirements(user?.role) && (
            <Link to={`/requirements/${requirement.id}/criteria`} className="mt-4 inline-block text-sm text-blue-700 hover:underline">
              Applicability criteria
            </Link>
          )}
          <dl className="mt-6 divide-y divide-gray-200 rounded-md border border-gray-200">
            {[
              [L.name, requirement.name],
              [L.description, requirement.description],
              [L.regulation, requirement.regulation],
              [L.deadline, isoToDisplayDate(requirement.deadline)],
              [L.responsible_user_id, requirement.responsible_user?.name ?? ''],
              ['Status', requirement.status.label],
            ].map(([label, value]) => (
              <div key={label} className="grid grid-cols-3 gap-4 px-4 py-3">
                <dt className="text-sm font-medium text-gray-600">{label}</dt>
                <dd className="col-span-2 whitespace-pre-line text-sm">{value}</dd>
              </div>
            ))}
            <div className="grid grid-cols-3 gap-4 px-4 py-3">
              <dt className="text-sm font-medium text-gray-600">{L.required_documents}</dt>
              <dd className="col-span-2 text-sm">
                <ul className="space-y-1">
                  {requirement.required_documents.map((document) => (
                    <li key={document.id}>
                      {document.name} <span className="text-gray-500">({document.category.name})</span>
                    </li>
                  ))}
                </ul>
              </dd>
            </div>
          </dl>
        </>
      )}
    </main>
  )
}
