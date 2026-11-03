import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  withCredentials: true,
  // Send the XSRF-TOKEN cookie set by /sanctum/csrf-cookie (frontend and API run on different ports).
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})
