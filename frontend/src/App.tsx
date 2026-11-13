import { Navigate, Route, Routes } from 'react-router-dom'
import ProtectedRoute from './components/ProtectedRoute'
import DocumentDetailPage from './pages/DocumentDetailPage'
import DocumentUploadPage from './pages/DocumentUploadPage'
import ForbiddenPage from './pages/ForbiddenPage'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/403" element={<ForbiddenPage />} />
      <Route element={<ProtectedRoute />}>
        <Route path="/" element={<HomePage />} />
        <Route element={<ProtectedRoute ability="view-dashboard" />}>
          <Route path="/dashboard" element={<HomePage />} />
        </Route>
        <Route element={<ProtectedRoute ability="use-ai-assistant" />}>
          <Route path="/assistant" element={<HomePage />} />
        </Route>
        <Route element={<ProtectedRoute ability="upload-documents" />}>
          <Route path="/documents/new" element={<DocumentUploadPage />} />
        </Route>
        <Route element={<ProtectedRoute ability="view-documents" />}>
          <Route path="/documents/:id" element={<DocumentDetailPage />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
