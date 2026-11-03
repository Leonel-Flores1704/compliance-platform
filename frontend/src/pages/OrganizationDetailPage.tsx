import { useQuery } from '@tanstack/react-query'
import { useLocation, useParams } from 'react-router-dom'
import Toast from '../components/Toast'
import { fetchOrganization } from '../features/organizations/api'
import { ORGANIZATION_LABELS as L } from '../features/organizations/messages'

export default function OrganizationDetailPage() {
  const { id = '' } = useParams()
  const location = useLocation()
  const toast = (location.state as { toast?: string } | null)?.toast

  const { data: organization, isLoading, isError } = useQuery({
    queryKey: ['organization', id],
    queryFn: () => fetchOrganization(id),
  })

  return (
    <main className="mx-auto max-w-2xl p-8">
      {toast && <Toast message={toast} />}
      {isLoading && <p>Loading...</p>}
      {isError && <p role="alert">The organization could not be loaded.</p>}
      {organization && (
        <>
          <h1 className="text-2xl font-bold">{organization.legal_name}</h1>
          <dl className="mt-6 divide-y divide-gray-200 rounded-md border border-gray-200">
            {[
              [L.legal_name, organization.legal_name],
              [L.organization_type, organization.organization_type.name],
              [L.industry, organization.industry.name],
              [L.state, organization.state.name],
              [L.municipality, organization.municipality],
              [L.employees_count, organization.employees_count.toLocaleString('en-US')],
            ].map(([label, value]) => (
              <div key={label} className="grid grid-cols-3 gap-4 px-4 py-3">
                <dt className="text-sm font-medium text-gray-600">{label}</dt>
                <dd className="col-span-2 text-sm">{value}</dd>
              </div>
            ))}
            <div className="grid grid-cols-3 gap-4 px-4 py-3">
              <dt className="text-sm font-medium text-gray-600">{L.business_activities}</dt>
              <dd className="col-span-2 text-sm">
                <ul>
                  {organization.business_activities.map((activity) => (
                    <li key={activity.code}>
                      {activity.code} - {activity.name}
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
