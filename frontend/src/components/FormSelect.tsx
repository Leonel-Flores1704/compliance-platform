import { forwardRef, type SelectHTMLAttributes } from 'react'

export type SelectOption = { value: string; label: string }

type Props = { id: string; options: SelectOption[]; placeholder?: string } & SelectHTMLAttributes<HTMLSelectElement>

// forwardRef is required so React Hook Form can read the value of the native <select>.
const FormSelect = forwardRef<HTMLSelectElement, Props>(function FormSelect({ id, options, placeholder, ...props }, ref) {
  return (
    <select id={id} ref={ref} {...props}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  )
})

export default FormSelect
