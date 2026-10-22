# ADR-021: Applicability criteria format and requirements that stop applying

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-22 |
| Open issue | OI-08 |
| Jira | CMP-04 |
| Related requirements | FR-007, FR-008, FR-009, FR-017 |
| Affects | CMP-17, CMP-06, CMP-15, CMP-19, CMP-27 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |

## Context

FR-007 requires the system to decide which compliance requirements apply to the organization using regulatory criteria that Compliance Staff can maintain. SRS V2 left open which organization characteristics criteria can use, how criteria are configured, and what happens to a requirement that stops applying (OI-08). CMP-17 needs an exact format and evaluation rule.

## Options considered

1. **Hard-coded rules in PHP.** Every change needs a deployment; Compliance Staff cannot maintain criteria (contradicts FR-007).
2. **One database row per condition.** Queryable, but nested `all` / `any` groups need extra tables and joins.
3. **Criteria stored as JSON in the requirement.** One column, editable from a form, easy to validate and to evaluate; supports `all` / `any` groups.
4. **Delete requirements that stop applying.** Loses history and audit records (NFR-013).
5. **Keep them with `applies = false`.** History is kept and the requirement can come back automatically.

## Decision

Options 3 and 5 are adopted.

### Characteristics that criteria can use

The values come from the organization record (ADR-001). Catalog values are compared using the catalog `code` from `backend/database/seeders/data/`.

| Field | Source | Value type |
|---|---|---|
| `organization_type` | Organization type | Code from `organization_types.json` |
| `industry` | Industry | Code from `industries.json` |
| `state` | Location: state | Code from `mexican_states.json` (`"01"` to `"32"`) |
| `employees_count` | Number of employees | Integer 1 to 1,000,000 |
| `business_activities` | Business activities (1 to 10) | Codes from `business_activities.json` |

### Allowed operators per characteristic

| Field | Operators | `value` format |
|---|---|---|
| `organization_type`, `industry`, `state` | `eq` (equals) | one code (string) |
| | `in` | array of 1 or more codes |
| | `not_in` | array of 1 or more codes |
| `employees_count` | `gte` (greater than or equal) | integer |
| | `lte` (less than or equal) | integer |
| | `between` (inclusive) | array `[min, max]` with `min <= max` |
| `business_activities` | `contains_any` | array of 1 or more codes; true when the organization has at least one of them |

Any other field and operator combination is rejected.

### JSON format

Criteria are stored in `compliance_requirements.criteria` (PostgreSQL `jsonb`).

```
criteria   := group
group      := {"all": [item, ...]} | {"any": [item, ...]}
item       := condition | group
condition  := {"field": <field>, "op": <operator>, "value": <value>}
```

- A group has exactly one key: `all` (every item must be true) or `any` (at least one item must be true).
- Groups can be nested up to 3 levels.
- `{"all": []}` means the requirement applies to every organization (default for a new requirement).
- `{"any": []}` is invalid.

### Validation (when Compliance Staff saves criteria)

1. The root is a group.
2. Every `field` is one of the 5 characteristics.
3. Every `op` is allowed for its `field`.
4. Every `value` has the type required by the operator.
5. Every code exists in its catalog.
6. For `between`, `min <= max`; integers are between 1 and 1,000,000.
7. Nesting depth is 3 or less.

Invalid criteria are not saved and the error indicates the condition to correct.

### Examples

**Example 1:** manufacturing organizations with 51 or more employees.

```json
{"all": [
  {"field": "industry", "op": "in", "value": ["manufacturing"]},
  {"field": "employees_count", "op": "gte", "value": 51}
]}
```

The proposal wrote this example with the display name `"Manufacturing"`. The stored value is the catalog code `"manufacturing"`, so names can be translated or corrected without breaking criteria.

**Example 2:** organizations in Durango or Coahuila that have mining or construction activities.

```json
{"all": [
  {"field": "state", "op": "in", "value": ["10", "05"]},
  {"field": "business_activities", "op": "contains_any", "value": ["21", "23"]}
]}
```

**Example 3:** private companies or cooperatives with 11 to 250 employees, excluding the retail industry.

```json
{"all": [
  {"field": "organization_type", "op": "in", "value": ["private_company", "cooperative"]},
  {"field": "employees_count", "op": "between", "value": [11, 250]},
  {"field": "industry", "op": "not_in", "value": ["retail"]}
]}
```

**Example 4:** healthcare organizations, or any organization with health-care activities (SCIAN 62) and more than 100 employees (nested group).

```json
{"any": [
  {"field": "industry", "op": "eq", "value": "healthcare"},
  {"all": [
    {"field": "business_activities", "op": "contains_any", "value": ["62"]},
    {"field": "employees_count", "op": "gte", "value": 101}
  ]}
]}
```

**Example 5:** applies to every organization.

```json
{"all": []}
```

### Evaluation

```
evaluate(group, org):
    if "all" in group: return every(evaluate_item(i, org) for i in group["all"])   # empty -> true
    if "any" in group: return some(evaluate_item(i, org) for i in group["any"])

evaluate_item(item, org):
    if item has "all" or "any": return evaluate(item, org)
    v = org[item.field]
    eq           -> v == item.value
    in           -> v in item.value
    not_in       -> v not in item.value
    gte          -> v >= item.value
    lte          -> v <= item.value
    between      -> item.value[0] <= v <= item.value[1]
    contains_any -> intersection(v, item.value) is not empty
```

Evaluation runs:

- after the organization is created or updated, for every requirement;
- after Compliance Staff saves the criteria of a requirement, for that requirement.

### Requirements that stop applying

- The requirement stays stored with `applies = false`. It is never deleted.
- It disappears from the dashboard (FR-008) and from the default lists and search results (FR-009).
- Its history, status, required documents and audit records are kept.
- It reappears automatically, with its last status, when a later evaluation returns true (`applies = true`).
- Every change of `applies` creates a functional audit record (FR-017) with the system as actor and the reason (organization updated or criteria changed).
- No alerts are generated while `applies = false` (ADR-020).

## Consequences

- CMP-06 adds `criteria jsonb not null default '{"all": []}'` and `applies boolean not null default true` to `compliance_requirements`.
- CMP-17 implements the validator and the evaluator above, with unit tests for one matching and one non-matching case per characteristic and operator.
- CMP-15 shows a criteria editor that builds this JSON; Compliance Staff do not type JSON by hand.
- CMP-19 and CMP-27 filter by `applies = true` by default.
- A new characteristic needs a new ADR, a new field name and its operators.
