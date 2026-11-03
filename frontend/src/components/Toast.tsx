import { useEffect, useState } from 'react'

export default function Toast({ message, durationMs = 5000 }: { message: string; durationMs?: number }) {
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    const timer = setTimeout(() => setVisible(false), durationMs)
    return () => clearTimeout(timer)
  }, [durationMs])

  if (!visible) return null

  return (
    <div
      role="status"
      className="fixed right-4 top-4 rounded-md bg-green-700 px-4 py-3 text-sm font-medium text-white shadow-lg"
    >
      {message}
    </div>
  )
}
