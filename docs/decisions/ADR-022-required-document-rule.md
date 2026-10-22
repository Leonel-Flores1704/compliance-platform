# ADR-022: Rule for a satisfied required document

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-22 |
| Open issue | OI-13 |
| Jira | CMP-04 |
| Related requirements | FR-014, FR-006, FR-005, FR-004 |
| Affects | CMP-18, CMP-06, CMP-19 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |

## Context

FR-014 requires the system to show "Missing Document" when no uploaded document associated with a compliance requirement satisfies a required document. SRS V2 did not define when a document satisfies it (OI-13). CMP-18 needs a rule that can be implemented and tested directly.

## Options considered

1. **Any linked document satisfies.** Simple, but an expired permit or a document that failed processing would hide a real gap.
2. **Manual check by Compliance Staff.** Depends on people; the indication can be wrong without anyone noticing.
3. **Automatic rule based on link, category, processing status and expiration date.** Deterministic and testable.

## Decision

Option 3 is adopted.

Each required document of a compliance requirement has a **required category** (ADR-003).

### Rule

A required document `R` is **satisfied** when **at least one** document `D` meets **all** of these conditions:

1. `D` is linked to `R`.
2. `D.category == R.required_category`.
3. `D.current_version.status == "Processed"`.
4. `D.expiration_date` is empty **or** `D.expiration_date > today`.

`today` is the current date in `America/Mexico_City`. A document that expires today does not satisfy the rule.

A compliance requirement shows **"Missing Document"** when at least one of its required documents is not satisfied.

```php
function isSatisfied(RequiredDocument $r, Carbon $today): bool
{
    return $r->linkedDocuments->contains(fn (Document $d) =>
        $d->category_id === $r->required_category_id
        && $d->currentVersion?->status === 'Processed'
        && ($d->expiration_date === null || $d->expiration_date->gt($today))
    );
}

function hasMissingDocument(ComplianceRequirement $c, Carbon $today): bool
{
    return $c->requiredDocuments->contains(fn (RequiredDocument $r) => ! isSatisfied($r, $today));
}
```

### Clarifications derived from this decision (to confirm in the Pull Request review)

- **Computed, not stored:** the result depends on today's date, so it is calculated when the requirement is displayed (or by a daily job if performance requires it), not saved once.
- **Archived documents:** an archived document (ADR-004) does not satisfy a required document.
- **No current version:** a document whose first version is still processing or failed has no "Processed" current version and does not satisfy the rule.

## Consequences

- CMP-06: `required_documents` has `compliance_requirement_id`, `name` and `required_category_id`; a pivot table links documents to required documents.
- CMP-18 implements the rule with tests for: missing (no link), wrong category, version not processed, expired, expires today, and satisfied. A replaced document is covered because the rule always uses the current version.
- CMP-19 shows the "Missing Document" indication from `hasMissingDocument`.
- The indication changes without user action when a document expires.
