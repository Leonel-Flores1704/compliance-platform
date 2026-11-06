import { Navigate, Route, Routes } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import DocumentDetailPage from './pages/DocumentDetailPage'
import DocumentUploadPage from './pages/DocumentUploadPage'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<ProtectedRoute />}>
        <Route path="/" element={<HomePage />} />
        <Route path="/dashboard" element={<HomePage />} />
        <Route path="/assistant" element={<HomePage />} />
        <Route path="/documents/new" element={<DocumentUploadPage />} />
        <Route path="/documents/:id" element={<DocumentDetailPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
