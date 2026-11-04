import { useMutation, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useEffect, useRef, useState } from 'react'
import Toast from '../../components/Toast'
import { updateRequirementStatus, type ComplianceRequirement } from './api'
import {
  REQUIREMENT_STATUSES,
  statusChangeQuestion,
  statusUpdatedMessage,
  type RequirementStatus,
} from './messages'

const labelOf = (code: string) => REQUIREMENT_STATUSES.find((s) => s.code === code)?.label ?? code

function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    return data?.errors?.status?.[0] ?? data?.message ?? error.message
  }
  return String(error)
}

// Status dropdown + confirmation dialog (FR-016, CMP-16). Only rendered for Compliance Staff.
export default function StatusChanger({ requirement }: { requirement: ComplianceRequirement }) {
  const queryClient = useQueryClient()
  const [target, setTarget] = useState<RequirementStatus | null>(null)
  const [toast, setToast] = useState<{ id: number; message: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const confirmButton = useRef<HTMLButtonElement | null>(null)

  const mutation = useMutation({
    mutationFn: (status: RequirementStatus) => updateRequirementStatus(requirement.id, status),
    onSuccess: async (response, status) => {
      setTarget(null)
      setError(null)
      setToast({ id: Date.now(), message: response.message ?? statusUpdatedMessage(labelOf(status)) })
      // Detail page uses ['requirements', id]; the dashboard (CMP-19) uses ['dashboard'].
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['requirements'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ])
    },
    onError: (err) => {
      setTarget(null)
      setError(errorMessage(err))
    },
  })

  useEffect(() => {
    if (target) confirmButton.current?.focus()
  }, [target])

  const close = () => setTarget(null)

  return (
    <div className="mt-4">
      {toast && <Toast key={toast.id} message={toast.message} />}

      <label htmlFor="status-change" className="block text-sm font-medium">
        Change status
      </label>
      <select
        id="status-change"
        className="mt-1 block w-56 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-600"
        value={requirement.status.code}
        disabled={mutation.isPending}
        onChange={(event) => {
          const value = event.target.value as RequirementStatus
          setError(null)
          if (value !== requirement.status.code) setTarget(value)
        }}
      >
        {REQUIREMENT_STATUSES.map((status) => (
          <option key={status.code} value={status.code}>
            {status.label}
          </option>
        ))}
      </select>

      {error && (
        <p role="alert" className="mt-2 text-sm text-red-600">
          {error}
        </p>
      )}

      {target && (
        <div className="fixed inset-0 z-10 flex items-center justify-center bg-black/40 p-4" onKeyDown={(e) => e.key === 'Escape' && close()}>
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="status-dialog-title"
            className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl"
          >
            <p id="status-dialog-title" className="text-base font-semibold">
              {statusChangeQuestion(requirement.status.label, labelOf(target))}
            </p>
            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                onClick={close}
                className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                ref={confirmButton}
                type="button"
                disabled={mutation.isPending}
                onClick={() => mutation.mutate(target)}
                className="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
              >
                Change status
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

