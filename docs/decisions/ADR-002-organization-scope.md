# ADR-002: Organization scope, editing and deactivation

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-20 |
| Open issue | OI-10 |
| Jira | CMP-02 |
| Related requirements | FR-001, FR-018, NFR-013 |
| Affects | CMP-06, CMP-14, CMP-10 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |
| Meeting | [2026-10-20 data model decision meeting](../meetings/2026-10-20-data-model.md) |

## Context

SRS V2 did not define whether one installation serves one organization or several, whether a user can belong to more than one organization, or whether an organization can be edited, deactivated or deleted (OI-10). These decisions change the data model (tenant column on every table or not) and the authorization rules.

## Options considered

1. **Multi-organization (multi-tenant) installation.** Every table needs an organization reference and every query needs a tenant filter. More flexible, but it adds data-isolation risk and work that the MVP does not need.
2. **One organization per installation.** Simpler data model and authorization. A second organization requires a second installation.
3. **Physical deletion of organizations.** Removes data permanently and breaks audit history and foreign keys (NFR-013, NFR-021).
4. **Soft delete (deactivation).** The record stays in the database and is marked inactive.

## Decision

- **Scope:** one organization per installation for the MVP (option 2). Every user of the installation belongs to that organization, so a user cannot belong to more than one organization.
- **Registration and editing:** only the Administrator can register and edit the organization.
- **Deactivation:** soft delete only (option 4). Deactivation sets `is_active = false`. Organizations are never physically deleted.
- **Reactivation:** only the Administrator can set `is_active` back to `true`.

## Consequences

- CMP-06 keeps an `organizations` table with an `is_active` boolean (default `true`). Other tables still reference `organization_id` with a foreign key, so a later move to multi-organization does not require a schema redesign.
- CMP-14 blocks the registration of a second organization once one exists and exposes edit, deactivate and reactivate actions to the Administrator only.
- No delete endpoint exists for organizations. Any delete attempt returns HTTP 403 or 405 and is recorded in the audit log.
- CMP-10 includes "Register and edit organization" as an Administrator-only function in the permissions matrix (ADR-005).
- Supporting several organizations in one installation is a future change and requires a new ADR.
