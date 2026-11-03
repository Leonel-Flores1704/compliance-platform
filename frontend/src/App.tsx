import { Route, Routes } from 'react-router-dom'
import HomePage from './pages/HomePage'
import OrganizationCreatePage from './pages/OrganizationCreatePage'
import OrganizationDetailPage from './pages/OrganizationDetailPage'
import RequirementCreatePage from './pages/RequirementCreatePage'
import RequirementDetailPage from './pages/RequirementDetailPage'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/organizations/new" element={<OrganizationCreatePage />} />
      <Route path="/organizations/:id" element={<OrganizationDetailPage />} />
      <Route path="/requirements/new" element={<RequirementCreatePage />} />
      <Route path="/requirements/:id" element={<RequirementDetailPage />} />
    </Routes>
  )
}
