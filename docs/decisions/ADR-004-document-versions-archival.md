# ADR-004: Document version immutability, archival and no physical deletion

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-20 |
| Open issue | OI-12 |
| Jira | CMP-02 |
| Related requirements | FR-005, BR-003, NFR-013, FR-017 |
| Affects | CMP-06, CMP-21, CMP-23, CMP-27, CMP-29, CMP-30 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |
| Meeting | [2026-10-20 data model decision meeting](../meetings/2026-10-20-data-model.md) |

## Context

FR-005 and BR-003 require that a new version never deletes the previous one, and NFR-013 forbids unauthorized modification or deletion of versions. SRS V2 left open how deletion and archival work, whether older versions can be restored, whether there is a maximum number of versions, and whether expired documents remain searchable (OI-12).

## Options considered

1. **Editable versions with physical deletion by authorized users.** Flexible, but it breaks the version history, the AI citations that point to a version (NFR-019) and the audit trail.
2. **Immutable versions, archival instead of deletion.** History and citations stay valid; documents that are no longer in use are removed from daily views without losing them.

## Decision

Option 2 is adopted.

- **Immutable versions.** Once a version is stored, its file and its metadata cannot be modified. Any change is made by uploading a new version (FR-005).
- **Archived documents.** A document can be archived. An archived document and all its versions are read-only and are hidden from default lists. They remain available through an explicit "Archived" filter for users who are allowed to view the document.
- **No physical deletion.** No document and no version is physically deleted from the database or from file storage. The application does not expose a delete action for documents or versions.
- Archiving and unarchiving are recorded in the functional audit log (FR-017).

### Clarifications derived from this decision (to confirm in the Pull Request review)

The meeting approved the three rules above. The following points close the rest of OI-12 and follow directly from them:

- **Restoring an older version:** there is no in-place rollback. To reinstate older content, a user with upload permission uploads that file again as a new version, so the history stays linear.
- **Maximum number of versions:** no limit in the MVP.
- **Expired documents:** they stay searchable and visible with an "Expired" indication; expiration does not archive a document automatically.
- **Archive and unarchive permission:** the same roles that can upload documents and new versions (Administrator and Compliance Staff, ADR-005).
- **AI retrieval:** archived documents are excluded from AI retrieval because they are hidden from default use.

## Consequences

- CMP-06: `document_versions` has no update or delete path in the application; `documents` has `is_archived` (default `false`) and `archived_at`, `archived_by`. Foreign keys use `RESTRICT` on delete.
- CMP-21 and CMP-23: version metadata is validated before storage and is never edited afterward.
- CMP-27: default document lists and searches exclude archived documents; an "Archived" filter includes them.
- CMP-29 and CMP-30: archived documents are not retrieved for AI answers.
- Storage grows with every version. Retention limits are part of OI-18 and are not decided here.
