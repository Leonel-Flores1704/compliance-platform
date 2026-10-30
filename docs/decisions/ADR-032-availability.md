# ADR-032: Service Availability, Scheduled Maintenance Window, and Downtime Measurement

* **Status:** Accepted
* **Date:** 2026-10-19
* **Deciders:** 
  * Fernando Cisneros Torres (Lead Product Engineer)
  * Emilio Rangel Torres (Compliance Product Engineer)
  * Leonel De la Cruz Flores (Product Design Engineer)
  * Hector Ernesto Torres Carrizales (AI Product Engineer)
* **Ticket / Issue:** [CMP-05] Resolve deployment and operations open issues (OI-16)
* **Related Requirements:** NFR-012, CMP-42

---

## 1. Context and Problem Statement

Non-functional requirement **NFR-012** dictates that:
> *"The system shall maintain at least 99% monthly availability, excluding scheduled maintenance periods that are defined and documented before the measurement period."*

Previously, the specification did not define what constitutes an approved maintenance window, nor did it define the technical metric or mechanism to measure downtime and service degradation (**OI-16** in SRS V2). 

Without formal rules:
1. Operational availability calculations cannot be audited objectively.
2. Routine system maintenance, kernel updates, database schema migrations, and container upgrades could inappropriately count against the 99% availability service level objective (SLO).
3. Synthetic uptime monitoring tools (**CMP-42**) lack unambiguous probe rules and thresholds.

---

## 2. Decision Drivers

* **Operational Predictability:** Provide a regular, low-impact time window for routine maintenance, security patching, and major releases.
* **Objective Measurement:** Downtime must be programmatically measurable using standard HTTP health checks without human subjectivity.
* **Alignment with User Activity:** Scheduled maintenance must occur during the period with minimal organizational usage in the local time zone (`America/Mexico_City`).
* **SLA Compliance:** Enable straightforward auditing to verify compliance with the 99% availability commitment ($< 1\%$ unscheduled downtime).

---

## 3. Decision Outcome

### 3.1 Scheduled Maintenance Window Specification

To ensure high availability during business operations while permitting essential system maintenance, the recurring scheduled maintenance window is established as follows:

* **Frequency & Day:** **Every Sunday**
* **Start Time:** **02:00** (2:00 AM)
* **End Time:** **04:00** (4:00 AM)
* **Time Zone:** **America/Mexico_City** (UTC-6)
* **Window Duration:** **2 hours per week** (maximum 8 to 10 hours per calendar month).

#### Operational Rules for Maintenance Windows
1. **Advance Notification:**
   * Recurring weekly maintenance within this window is pre-approved and documented in this ADR.
   * Any non-standard maintenance window or extended maintenance window must be announced to users via the application banner and email at least 24 hours in advance.
2. **Exclusion from Availability Calculations:**
   * Any downtime or service disruption occurring between 02:00 and 04:00 America/Mexico_City on Sundays is formally **excluded** from the monthly availability percentage calculation mandated by **NFR-012**.
3. **Graceful Maintenance Handling:**
   * During scheduled maintenance, the reverse proxy (Nginx) shall serve an HTTP 503 "Under Scheduled Maintenance" JSON response/page containing the estimated completion time.

---

### 3.2 Downtime Measurement Methodology

Downtime shall be monitored and computed objectively via synthetic HTTP probes managed by the automated monitoring system (**CMP-42**, using Uptime Kuma / Better Stack):

1. **Target Health Check Endpoint:**
   * `GET https://compliance-platform.online/api/health`
2. **Health Check Validation Criteria:**
   The probe evaluates the application status and core dependencies. A check is considered **HEALTHY (UP)** if and only if:
   * The HTTP response status code is exactly `200 OK`.
   * The response body contains `{"status": "ok", "database": "connected", "redis": "connected"}`.
   * The total HTTP response time is $\le 10.0\text{ seconds}$.
3. **Formal Definition of Downtime:**
   * **Any 1-minute probe of `GET /api/health` that fails to return HTTP 200 (including HTTP $5xx$ status codes, HTTP $4xx$ gateway errors, connection timeouts $> 10\text{s}$, or TCP connection refused), occurring OUTSIDE scheduled maintenance windows, shall be recorded as exactly one (1) minute of service downtime.**
4. **Availability Calculation Formula:**
   For any given monthly billing cycle of duration $T_{\text{total}}$ minutes:

   $$\text{Availability } (\%) = \left( 1 - \frac{\text{Unscheduled Downtime (minutes)}}{T_{\text{total}} - T_{\text{scheduled\_maintenance}}} \right) \times 100\%$$

   *Example for a 30-day month (43,200 minutes) with 4 weekly 2-hour maintenance windows (480 minutes scheduled):*
   * Available non-maintenance minutes = $43,200 - 480 = 42,720\text{ minutes}$.
   * At 99.0% availability, maximum allowable unscheduled downtime per month = $427.2\text{ minutes}$ ($\approx 7.12\text{ hours}$).

---

### 3.3 Performance Degradation vs. Outage

* **Hard Downtime (Outage):** Measured per the 1-minute probe rule above.
* **Partial Degradation:**
  * Defined as a condition where `GET /api/health` returns HTTP 200, but response latency exceeds 5.0 seconds for 3 consecutive minutes, or when asynchronous document ingestion queues stall for $> 15\text{ minutes}$.
  * Partial degradation generates a high-severity alert to the engineering team via webhook/Discord, but is logged as degraded performance rather than complete downtime unless the health check times out or returns non-200.

---

## 4. Consequences

### Positive
* Provides clear, legally and technically defensible rules for validating **NFR-012**.
* Predictable maintenance schedule allowing unattended OS security patching and container redeployment every Sunday morning without SLA penalties.
* Health check endpoint verifies not just the web server, but database and cache operational integrity.

### Negative / Risks and Mitigations
* *Automated Maintenance Failures:* If an automated upgrade fails at 03:55 Sunday and persists past 04:00, downtime calculation begins immediately at 04:01.
  * *Mitigation:* Backups are taken before maintenance (**ADR-033**), and automated rollback scripts restore previous container images if health checks fail.

---

## 5. Requirement Traceability

* **Resolves Open Issue:** **OI-16** (Scheduled maintenance windows and downtime measurement).
* **Satisfies Requirements:** **NFR-012** (Monthly 99% availability), **CMP-42** (Uptime monitoring configuration).
