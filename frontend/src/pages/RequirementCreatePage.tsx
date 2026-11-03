import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { useMemo, useRef, useState } from 'react'
import { type FieldErrors, type Path, useFieldArray, useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import FormSelect from '../components/FormSelect'
import { createRequirement, fetchDocumentCategories, fetchUserOptions } from '../features/requirements/api'
import { displayDateToIso, isoToDisplayDate } from '../features/requirements/dates'
import { clientErrorKeys, focusFirstInvalid } from '../features/requirements/focus'
import {
  REQUIREMENT_LABELS as L,
  REQUIREMENT_MESSAGES as M,
  REQUIREMENT_STATUSES,
} from '../features/requirements/messages'
import {
  EMPTY_REQUIREMENT_FORM,
  requirementSchema,
  toRequirementPayload,
  type RequirementFormValues,
} from '../features/requirements/schema'
import { canCreateRequirements, useCurrentUser } from '../lib/auth'

const inputClass =
  'mt-1 block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-600'
// Row inputs share the line, so they must not take the full width.
const rowInputClass = inputClass.replace('block w-full', 'block')
const border = (hasError: boolean) => (hasError ? 'border-red-600' : 'border-gray-300')

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null
  return (
    <p id={id} className="mt-1 text-sm text-red-600">
      {message}
    </p>
  )
}

export default function RequirementCreatePage() {
  const navigate = useNavigate()
  const { data: user, isLoading: userLoading } = useCurrentUser()
  const allowed = canCreateRequirements(user?.role)
  const [formError, setFormError] = useState<string | null>(null)
  const datePicker = useRef<HTMLInputElement | null>(null)

  const { data: users } = useQuery({ queryKey: ['users', 'options'], queryFn: fetchUserOptions, enabled: allowed })
  const { data: categories } = useQuery({
    queryKey: ['catalogs', 'document-categories'],
    queryFn: fetchDocumentCategories,
    enabled: allowed,
  })

  const schema = useMemo(() => requirementSchema({ users, categories }), [users, categories])

  const {
    register,
    control,
    handleSubmit,
    setError,
    setValue,
    watch,
    formState: { errors, isSubmitting, isSubmitted },
  } = useForm<RequirementFormValues>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_REQUIREMENT_FORM,
    shouldFocusError: false,
  })
  const { fields, append, remove } = useFieldArray({ control, name: 'required_documents' })

  const onInvalid = (formErrors: FieldErrors<RequirementFormValues>) => focusFirstInvalid(clientErrorKeys(formErrors))

  const onValid = async (values: RequirementFormValues) => {
    setFormError(null)
    try {
      const requirement = await createRequirement(toRequirementPayload(values))
      navigate(`/requirements/${requirement.id}`, { state: { toast: M.created } })
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const serverErrors = (error.response.data?.errors ?? {}) as Record<string, string[]>
        const keys = Object.keys(serverErrors)
        for (const key of keys) {
          setError(key as Path<RequirementFormValues>, { type: 'server', message: serverErrors[key][0] })
        }
        focusFirstInvalid(keys)
        return
      }
      if (axios.isAxiosError(error) && error.response?.status === 403) {
        setFormError(error.response.data?.message ?? M.forbidden)
        return
      }
      setFormError(axios.isAxiosError(error) ? (error.response?.data?.message ?? error.message) : String(error))
    }
  }

  if (userLoading) return <main className="p-8">Loading...</main>

  if (!allowed) {
    return (
      <main className="mx-auto max-w-3xl p-8">
        <h1 className="text-2xl font-bold">New compliance requirement</h1>
        <p role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-700">
          {M.forbidden}
        </p>
      </main>
    )
  }

  const documentsError = errors.required_documents
  // Errors of the whole list (for example "Add at least one required document.") are not tied to a row.
  const listError = documentsError as { message?: string; root?: { message?: string } } | undefined
  const documentsMessage = listError?.message ?? listError?.root?.message
  const deadlineIso = displayDateToIso(watch('deadline') ?? '') ?? ''

  return (
    <main className="mx-auto max-w-3xl p-8">
      <h1 className="text-2xl font-bold">New compliance requirement</h1>

      {formError && (
        <p role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-700">
          {formError}
        </p>
      )}

      <form noValidate className="mt-6 space-y-5" onSubmit={handleSubmit(onValid, onInvalid)}>
        <div>
          <label htmlFor="name" className="block text-sm font-medium">
            {L.name}
          </label>
          <input id="name" type="text" className={`${inputClass} ${border(!!errors.name)}`} aria-invalid={!!errors.name} {...register('name')} />
          <FieldError id="name-error" message={errors.name?.message} />
        </div>

        <div>
          <label htmlFor="description" className="block text-sm font-medium">
            {L.description}
          </label>
          <textarea
            id="description"
            rows={4}
            className={`${inputClass} ${border(!!errors.description)}`}
            aria-invalid={!!errors.description}
            {...register('description')}
          />
          <FieldError id="description-error" message={errors.description?.message} />
        </div>

        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <label htmlFor="regulation" className="block text-sm font-medium">
              {L.regulation}
            </label>
            <input
              id="regulation"
              type="text"
              placeholder="NOM-035-STPS-2018"
              className={`${inputClass} ${border(!!errors.regulation)}`}
              aria-invalid={!!errors.regulation}
              {...register('regulation')}
            />
            <FieldError id="regulation-error" message={errors.regulation?.message} />
          </div>

          <div>
            <label htmlFor="deadline" className="block text-sm font-medium">
              {L.deadline}
            </label>
            <div className="relative mt-1 flex gap-2">
              <input
                id="deadline"
                type="text"
                inputMode="numeric"
                placeholder="DD/MM/YYYY"
                autoComplete="off"
                className={`${inputClass} mt-0 ${border(!!errors.deadline)}`}
                aria-invalid={!!errors.deadline}
                {...register('deadline')}
              />
              <button
                type="button"
                aria-label="Open calendar"
                className="rounded-md border border-gray-300 px-3 text-sm hover:bg-gray-50"
                onClick={() => datePicker.current?.showPicker?.()}
              >
                📅
              </button>
              <input
                ref={datePicker}
                type="date"
                tabIndex={-1}
                aria-hidden="true"
                className="pointer-events-none absolute bottom-0 right-0 h-0 w-0 opacity-0"
                value={deadlineIso}
                onChange={(event) =>
                  setValue('deadline', event.target.value ? isoToDisplayDate(event.target.value) : '', {
                    shouldDirty: true,
                    shouldValidate: isSubmitted,
                  })
                }
              />
            </div>
            <FieldError id="deadline-error" message={errors.deadline?.message} />
          </div>
        </div>

        <fieldset>
          <legend className="block text-sm font-medium">{L.required_documents}</legend>
          <div className={`mt-1 space-y-3 rounded-md border p-3 ${border(!!documentsError)}`}>
            {fields.map((field, index) => {
              const rowError = Array.isArray(documentsError) ? documentsError[index] : undefined
              const rowMessage = rowError?.name?.message ?? rowError?.category?.message
              return (
                <div key={field.id}>
                  <div className="flex items-start gap-2">
                    <input
                      id={`required_documents-${index}-name`}
                      type="text"
                      aria-label={`Required document ${index + 1} name`}
                      placeholder="Document name"
                      className={`${rowInputClass} mt-0 min-w-0 flex-1 ${border(!!rowError?.name)}`}
                      {...register(`required_documents.${index}.name`)}
                    />
                    <FormSelect
                      id={`required_documents-${index}-category`}
                      aria-label={`Required document ${index + 1} category`}
                      placeholder="Category"
                      options={(categories ?? []).map((c) => ({ value: c.code, label: c.name }))}
                      className={`${rowInputClass} mt-0 w-48 ${border(!!rowError?.category)}`}
                      {...register(`required_documents.${index}.category`)}
                    />
                    <button
                      type="button"
                      onClick={() => remove(index)}
                      className="rounded-md border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50"
                      aria-label={`Remove required document ${index + 1}`}
                    >
                      Remove
                    </button>
                  </div>
                  <FieldError id={`required_documents-${index}-error`} message={rowMessage} />
                </div>
              )
            })}
            <button
              id="add-required-document"
              type="button"
              onClick={() => append({ name: '', category: '' })}
              className="rounded-md border border-blue-700 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50"
            >
              Add required document
            </button>
          </div>
          <FieldError id="required_documents-error" message={documentsMessage} />
        </fieldset>

        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <label htmlFor="responsible_user_id" className="block text-sm font-medium">
              {L.responsible_user_id}
            </label>
            <FormSelect
              id="responsible_user_id"
              placeholder="Select"
              options={(users ?? []).map((u) => ({ value: String(u.id), label: u.name }))}
              className={`${inputClass} ${border(!!errors.responsible_user_id)}`}
              aria-invalid={!!errors.responsible_user_id}
              {...register('responsible_user_id')}
            />
            <FieldError id="responsible_user_id-error" message={errors.responsible_user_id?.message} />
          </div>
          <div>
            <label htmlFor="status" className="block text-sm font-medium">
              {L.status}
            </label>
            <FormSelect
              id="status"
              options={REQUIREMENT_STATUSES.map((s) => ({ value: s.code, label: s.label }))}
              className={`${inputClass} ${border(!!errors.status)}`}
              aria-invalid={!!errors.status}
              {...register('status')}
            />
            <FieldError id="status-error" message={errors.status?.message} />
          </div>
        </div>

        <button
          type="submit"
          disabled={isSubmitting}
          className="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
        >
          Create requirement
        </button>
      </form>
    </main>
  )
}
