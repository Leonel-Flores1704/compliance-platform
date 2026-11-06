import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  withCredentials: true,
  // Send the XSRF-TOKEN cookie when the SPA and the API run on different ports (localhost:5173 -> :8000).
  withXSRFToken: true,
})

let onUnauthorized: (() => void) | undefined

export function setUnauthorizedHandler(handler?: () => void) {
  onUnauthorized = handler
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      if (onUnauthorized) {
        onUnauthorized()
      } else if (window.location.pathname !== '/login') {
        window.location.assign(`/login?redirect=${encodeURIComponent(window.location.pathname)}`)
      }
    }

    return Promise.reject(error)
  },
)
