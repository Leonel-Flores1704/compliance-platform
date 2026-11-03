import { Route, Routes } from 'react-router-dom'
import HomePage from './pages/HomePage'
import OrganizationCreatePage from './pages/OrganizationCreatePage'
import OrganizationDetailPage from './pages/OrganizationDetailPage'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/organizations/new" element={<OrganizationCreatePage />} />
      <Route path="/organizations/:id" element={<OrganizationDetailPage />} />
    </Routes>
  )
}
