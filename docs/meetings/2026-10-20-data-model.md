# Decision meeting: data model and authorization open issues

| Field | Value |
|---|---|
| Date | 2026-10-20 |
| Duration | 1 hour |
| Jira | CMP-02 |
| Facilitator | Hector Torres Carrizales |
| Purpose | Resolve OI-09, OI-10, OI-11, OI-12 and OI-14 of SRS V2 so CMP-06, CMP-14, CMP-21 and CMP-10 can start |

## Attendees

| Name | Present |
|---|---|
| Fernando Cisneros Torres | Yes |
| Emilio Rangel Torres | Yes |
| Leonel De La Cruz Flores | Yes |
| Hector Torres Carrizales | Yes |

## Agenda

1. OI-09: organization types, industries and field formats.
2. OI-10: one or several organizations per installation; editing and deactivation.
3. OI-11: document categories and expiration date rule.
4. OI-12: version immutability, archival and deletion.
5. OI-14: permissions matrix, audit-log access, Management role and document visibility.

## Decisions

All proposals presented were approved by the four attendees.

| Open issue | Decision | Record |
|---|---|---|
| OI-09 | Organization types: Private company, Public institution, Non-profit organization, Cooperative. Industries: Manufacturing, Retail, Services, Construction, Healthcare, Education, Technology, Food and beverages, Transportation, Other. Legal name 3-150 characters; location = Mexican state (catalog of 32) + municipality (2-100 characters); employees = whole number 1-1,000,000; business activities = 1 to 10 values from a catalog based on the SCIAN sectors. | [ADR-001](../decisions/ADR-001-organization-fields.md) |
| OI-10 | One organization per installation for the MVP. Only the Administrator registers and edits the organization. Deactivation is a soft delete (`is_active = false`); no physical deletion. | [ADR-002](../decisions/ADR-002-organization-scope.md) |
| OI-11 | Categories: Permit/License, Policy, Contract, Certificate, Report, Regulation, Other. Expiration date required for Permit/License and Certificate. | [ADR-003](../decisions/ADR-003-document-categories.md) |
| OI-12 | Versions are immutable once stored. Archived documents are read-only and hidden from default lists. No document or version is physically deleted. | [ADR-004](../decisions/ADR-004-document-versions-archival.md) |
| OI-14 | Permissions matrix (8 functions x 3 roles) confirmed. Compliance Staff can read the audit log. No Management role in the MVP. Each document has `access_level` = `all_staff` or `compliance_only`; General Employees only see `all_staff` documents. | [ADR-005](../decisions/ADR-005-permissions-matrix.md) |

## Action items

| Action | Owner | Jira |
|---|---|---|
| Write ADR-001 to ADR-005 and these minutes | Hector Torres Carrizales | CMP-02 |
| Deliver catalogs as JSON in `backend/database/seeders/data/` | Hector Torres Carrizales | CMP-02 |
| Update SRS to V3 draft and share it with the team | Hector Torres Carrizales | CMP-02 |
| Review and approve the CMP-02 Pull Request | Leonel De La Cruz Flores | CMP-02 |
| Apply decisions to the database schema | Leonel De La Cruz Flores | CMP-06 |
| Apply decisions to organization registration | Hector Torres Carrizales | CMP-14 |
| Apply decisions to document metadata | Emilio Rangel Torres | CMP-21 |
| Apply the final matrix to role-based access control | Leonel De La Cruz Flores | CMP-10 |
