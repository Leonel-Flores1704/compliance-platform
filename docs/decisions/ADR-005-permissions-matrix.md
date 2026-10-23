# ADR-005: Permissions matrix, audit-log access and document visibility

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-20 |
| Open issue | OI-14 |
| Jira | CMP-02 |
| Related requirements | FR-018, BR-002, BR-009, AIR-006, NFR-013, NFR-014, SRS Section 2.7 |
| Affects | CMP-10, CMP-06, CMP-21, CMP-09, CMP-30, CMP-34, CMP-38 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |
| Meeting | [2026-10-20 data model decision meeting](../meetings/2026-10-20-data-model.md) |

## Context

SRS V2 Section 2.7 contains a baseline permissions matrix that had to be confirmed before development (OI-14). Two cells were undefined ("Authorized by" for the dashboard and "Defined by policy" for audit logs), Management appeared as an actor without a role, and there was no rule for which documents a General Employee can see.

## Options considered

1. **Keep the V2 baseline with the undefined cells.** CMP-10 cannot write deterministic tests for undefined cells.
2. **Add a Management role.** Requires a fourth column, more tests and a definition of what Management can do, which no requirement describes yet.
3. **Confirm a three-role matrix with every cell set to Yes or No, plus a document-level visibility flag.** Deterministic, testable (24 cases) and enough for the MVP.

## Decision

Option 3 is adopted.

### Permissions matrix (final)

| # | Function | Administrator | Compliance Staff | General Employee |
|---|---|---|---|---|
| 1 | Manage user accounts | Yes | No | No |
| 2 | Register and edit organization | Yes | No | No |
| 3 | Create and edit compliance requirements and criteria | Yes | Yes | No |
| 4 | Change compliance status | No | Yes | No |
| 5 | Upload documents and new versions | Yes | Yes | No |
| 6 | View and download documents | Yes | Yes | Yes (only `all_staff` documents) |
| 7 | View compliance dashboard | Yes | Yes | No |
| 8 | Use AI assistant | Yes | Yes | Yes |

### Audit log access

| Function | Administrator | Compliance Staff | General Employee |
|---|---|---|---|
| Read audit log | Yes | Yes (read-only) | No |

No role can modify or delete audit records (NFR-013, NFR-014).

### Management role

No Management role is added in the MVP. Users from organization management are given one of the three existing roles according to what they need to do.

### Document visibility for General Employees

- Every document has an `access_level` with two possible values: `all_staff` or `compliance_only`.
- `access_level` is a required metadata field set by the user who uploads the document.
- General Employees can only see, download, search and receive AI answers from `all_staff` documents.
- Administrators and Compliance Staff can see both levels.
- The same filter applies to search results (FR-009), AI retrieval (FR-011, AIR-006) and citation viewing (FR-013, BR-009).

## Consequences

- CMP-10 implements the 8 x 3 matrix above (24 automated cases). The audit-log rule adds 3 cases. Every denied action returns HTTP 403 and creates an access-denial audit record (NFR-014).
- CMP-06 adds `documents.access_level` (`all_staff` or `compliance_only`, not null) and a role reference on `users` limited to Administrator, Compliance Staff and General Employee.
- CMP-21 requires `access_level` when a document is stored.
- CMP-30 and CMP-34 filter by `access_level` for General Employees before retrieval and before displaying a citation.
- CMP-38 exposes the audit log in read-only mode to Administrator and Compliance Staff.
- SRS V3 replaces the Management actor row with "not a separate role in the MVP".
