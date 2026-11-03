import { Route, Routes } from 'react-router-dom'
import DocumentDetailPage from './pages/DocumentDetailPage'
import DocumentUploadPage from './pages/DocumentUploadPage'
import HomePage from './pages/HomePage'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/documents/new" element={<DocumentUploadPage />} />
      <Route path="/documents/:id" element={<DocumentDetailPage />} />
    </Routes>
  )
}