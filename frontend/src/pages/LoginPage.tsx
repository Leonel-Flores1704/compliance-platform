import axios from 'axios'
import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useLocation, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { useAuth, type AuthUser } from '../auth/AuthContext'
import { api } from '../lib/api'

export const loginSchema = z.object({
  email: z.string().min(1, 'Email is required.').email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
})

type LoginValues = z.infer<typeof loginSchema>

export default function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { setUser } = useAuth()
  const [showPassword, setShowPassword] = useState(false)
  const [serverError, setServerError] = useState<string | null>(null)
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
  })

  const onSubmit = async (values: LoginValues) => {
    setServerError(null)
    try {
      await api.get('/sanctum/csrf-cookie')
      const response = await api.post<AuthUser>('/api/auth/login', values)
      setUser(response.data)
      const redirect = new URLSearchParams(location.search).get('redirect')
      const defaultPath = ['General Employee', 'general_employee'].includes(response.data.role)
        ? '/assistant'
        : '/dashboard'
      navigate(redirect?.startsWith('/') ? redirect : defaultPath, { replace: true })
    } catch (error) {
      const message = axios.isAxiosError<{ message?: string }>(error) ? error.response?.data?.message : undefined
      setServerError(message ?? 'The email or password is incorrect.')
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <form className="w-full max-w-sm space-y-4" onSubmit={handleSubmit(onSubmit)} noValidate>
        <h1 className="text-2xl font-bold">Sign in</h1>
        {serverError && <p role="alert">{serverError}</p>}
        <div>
          <label htmlFor="email">Email</label>
          <input id="email" type="email" autoComplete="email" {...register('email')} />
          {errors.email && <p role="alert">{errors.email.message}</p>}
        </div>
        <div>
          <label htmlFor="password">Password</label>
          <div className="flex gap-2">
            <input id="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" {...register('password')} />
            <button type="button" onClick={() => setShowPassword((visible) => !visible)}>
              {showPassword ? 'Hide password' : 'Show password'}
            </button>
          </div>
          {errors.password && <p role="alert">{errors.password.message}</p>}
        </div>
        <button type="submit" disabled={isSubmitting}>
          {isSubmitting && <span role="status" aria-label="Signing in" className="mr-2">Loading...</span>}
          Sign in
        </button>
      </form>
    </main>
  )
}
