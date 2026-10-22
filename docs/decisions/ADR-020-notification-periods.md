# ADR-020: Notification periods and alert generation time

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-10-22 |
| Open issue | OI-04 |
| Jira | CMP-04 |
| Related requirements | FR-015, FR-006, FR-004 |
| Affects | CMP-47, CMP-06 |
| Approved by | Fernando Cisneros Torres, Emilio Rangel Torres, Leonel De La Cruz Flores, Hector Torres Carrizales |

## Context

FR-015 requires an in-application alert when a configured notification period is reached before a compliance deadline or a document expiration date. SRS V2 did not define which periods can be configured or when alerts are generated (OI-04). CMP-47 must only allow the periods defined here.

## Options considered

1. **Free number of days.** Any value could be typed, which makes testing and the user interface harder and allows meaningless values (for example 0 or 900).
2. **Fixed list of periods.** A small set of values that cover the usual compliance horizons (one month, two weeks, one week, one day).
3. **Real-time generation (on every request).** Alerts appear at any time of the day; harder to test and adds load to every request.
4. **One scheduled run per day.** Predictable, easy to test and to repeat if it fails.

## Decision

Options 2 and 4 are adopted.

### Allowed notification periods (days before the deadline or expiration date)

| Period | Days |
|---|---|
| 1 month | 30 |
| 2 weeks | 15 |
| 1 week | 7 |
| 1 day | 1 |

`ALLOWED_NOTIFICATION_PERIODS = [30, 15, 7, 1]`

Any other value is rejected by validation.

### Alert generation time

- Alerts are generated **once per day at 07:00, time zone `America/Mexico_City`**, by a scheduled job.
- An alert is due for an item and a period when: `due_date - period_days == today` (dates in `America/Mexico_City`).
- Channel: in-application only (email is a future version, SRS Section 3.3).

### Clarifications derived from this decision (to confirm in the Pull Request review)

- **Configuration:** each compliance requirement and each document with an expiration date stores the periods it uses, chosen from the allowed list. Default: all four (`[30, 15, 7, 1]`).
- **No duplicates:** at most one alert per item, period and due date. Running the job twice on the same day does not create duplicates.
- **Missed run:** if the 07:00 run does not execute, the next run creates the alerts whose trigger date is today or earlier, as long as the due date has not passed.
- **Recipient:** for a compliance requirement, its responsible user (FR-015). For a document, the user who uploaded its current version.
- **No alerts** for requirements that do not apply (`applies = false`, ADR-021) or have status Compliant, and for archived documents (ADR-004).

## Consequences

- CMP-06 stores the configured periods (for example an integer array or a pivot table) and an `alerts` table with a unique key on item, period and due date.
- CMP-47 registers a Laravel scheduled command at 07:00 `America/Mexico_City` and validates periods against `[30, 15, 7, 1]`.
- Tests can freeze the date and run the command directly to check each period.
