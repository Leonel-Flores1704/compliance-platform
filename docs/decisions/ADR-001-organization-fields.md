# ADR-001: Organization fields, catalogs and formats

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-20 |
| Open issue | OI-09 |
| Jira | CMP-02 |
| Related requirements | FR-001 |
| Affects | CMP-06, CMP-14, CMP-17 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |
| Meeting | [2026-10-20 data model decision meeting](../meetings/2026-10-20-data-model.md) |

## Context

FR-001 requires the Administrator to register an organization with six fields: legal name, organization type, industry, location, number of employees and business activities. The organization must not be saved if any field is empty or fails its validation rule. SRS V2 left the allowed values and formats undefined (OI-09), so the organizations table (CMP-06) and the registration form (CMP-14) could not be built without guessing.

## Options considered

1. **Free text for every field.** Simple to build, but the values cannot be validated and cannot be used by the applicability criteria engine (FR-007, CMP-17), because the same value can be written in many ways.
2. **Fixed catalogs for the classification fields and explicit formats for the rest.** Values are consistent, can be validated against a list and can be compared by the criteria engine.
3. **Full official classifications (complete SCIAN tree, INEGI municipality catalog).** Most precise, but the full SCIAN tree has hundreds of entries and the municipality catalog has more than 2,400, which is too large for the MVP form and seeders.

## Decision

Option 2 is adopted.

| Field | Type | Rule |
|---|---|---|
| Legal name | Text | Required. 3 to 150 characters after trimming spaces. |
| Organization type | Catalog (single value) | Required. One value from `organization_types.json`. |
| Industry | Catalog (single value) | Required. One value from `industries.json`. |
| Location: state | Catalog (single value) | Required. One of the 32 federal entities in `mexican_states.json` (INEGI codes 01 to 32). |
| Location: municipality | Text | Required. 2 to 100 characters after trimming spaces. |
| Number of employees | Whole number | Required. Integer from 1 to 1,000,000. Decimals, negatives and zero are rejected. |
| Business activities | Catalog (multiple values) | Required. 1 to 10 distinct values from `business_activities.json`. Duplicates are rejected. |

**Organization types:** Private company, Public institution, Non-profit organization, Cooperative.

**Industries:** Manufacturing, Retail, Services, Construction, Healthcare, Education, Technology, Food and beverages, Transportation, Other.

**Business activities:** the 20 sectors of the Mexican North American Industry Classification System (SCIAN 2023, INEGI). Each entry stores the SCIAN code (for example `31-33`), the English name and the official Spanish name.

All catalogs are delivered as JSON files in `backend/database/seeders/data/` so CMP-06 and CMP-14 can seed them directly:

- `organization_types.json`
- `industries.json`
- `mexican_states.json`
- `business_activities.json`

Every catalog entry has a stable `code` (used as the stored value and foreign key) and a display `name`.

## Consequences

- CMP-06 creates catalog tables (or enum-backed lookups) for organization types, industries, states and business activities, and a pivot table between organizations and business activities.
- CMP-14 validates every field server-side with the rules above and returns the specific field to correct, as FR-001 requires.
- CMP-17 can use organization type, industry, state, number of employees and business activities as criteria inputs (final list of criteria characteristics is decided in OI-08, CMP-04).
- The municipality is free text in the MVP. Replacing it with the INEGI municipality catalog is possible later without changing the state field.
- Adding a value to a catalog only requires a seeder change, not a code change.
