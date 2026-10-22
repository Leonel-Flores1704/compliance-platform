# ADR-003: Document categories and expiration date rule

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-20 |
| Open issue | OI-11 |
| Jira | CMP-02 |
| Related requirements | FR-004, FR-009, FR-015 |
| Affects | CMP-06, CMP-21, CMP-27, CMP-47 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |
| Meeting | [2026-10-20 data model decision meeting](../meetings/2026-10-20-data-model.md) |

## Context

FR-004 requires every document to have a category and states that the expiration date is stored "when applicable". SRS V2 did not define the categories or which ones need an expiration date (OI-11). Without this, CMP-21 cannot validate metadata and CMP-47 cannot know which documents generate expiration alerts.

## Options considered

1. **Free-text category.** Cannot be filtered reliably (FR-009) and cannot drive the expiration rule.
2. **Fixed category catalog with a per-category expiration flag.** Consistent values and a simple validation rule.
3. **Expiration date optional for every category.** Simple, but permits and certificates could be stored without the date that the alerts depend on.

## Decision

Option 2 is adopted. Categories are stored in `backend/database/seeders/data/document_categories.json`.

| Code | Category | Expiration date |
|---|---|---|
| `permit_license` | Permit/License | Required |
| `policy` | Policy | Optional |
| `contract` | Contract | Optional |
| `certificate` | Certificate | Required |
| `report` | Report | Optional |
| `regulation` | Regulation | Optional |
| `other` | Other | Optional |

Rules:

- Category is required for every document (FR-004).
- When the category has `requires_expiration_date = true`, the document is rejected if the expiration date is missing.
- For the other categories the expiration date is optional and is stored when provided.
- The expiration date, when present, is a calendar date (`YYYY-MM-DD`).

## Consequences

- CMP-06 creates a `document_categories` table with `code`, `name` and `requires_expiration_date`, and `documents.category_id` references it.
- CMP-21 applies the expiration rule server-side and indicates the missing field.
- CMP-27 can filter documents by category and expiration date.
- CMP-47 generates expiration alerts for every document with an expiration date, which always includes Permit/License and Certificate documents.
- Changing which categories require an expiration date is a seeder change, not a code change.
