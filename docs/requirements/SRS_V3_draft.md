# Software Requirements Specification (SRS) - Version 3 (Draft)

**Project:** Legal / Regulatory Compliance Assistant for Small Organizations (`compliance-platform`)  
**Document Version:** V3 (Draft)  
**Date:** 2026-10-19  
**Course:** Software Management Projects / Software Engineering (9B BIS ISW)  
**Institution:** Universidad Politécnica de Durango  
**Teacher:** José Luis Bautista  
**Authors / Team Members:**
* Fernando Cisneros Torres (Lead Product Engineer)
* Emilio Rangel Torres (Compliance Product Engineer)
* Leonel De la Cruz Flores (Product Design Engineer)
* Hector Ernesto Torres Carrizales (AI Product Engineer)

---

## 1. Document Control

| Version | Date | Description |
|:---:|:---:|---|
| **V1** | 21/09/2026 | Initial Software Requirements Specification. |
| **Audit** | 23/09/2026 | Requirements quality audit: individual requirements, conflicts, incomplete areas, non-functional coverage, traceability, and clarification questions. |
| **V2** | 28/09/2026 | Corrected requirements from the audit; standardized terminology and priorities; added permissions matrix, glossary, expanded traceability matrix, audit resolution log, and consolidated open issues (OI-01 to OI-22). |
| **V3 (Draft)** | **19/10/2026** | **Resolved deployment and operations open issues (OI-07, OI-15, OI-16, OI-17) through Architecture Decision Records ADR-030, ADR-031, ADR-032, and ADR-033.** Defined target cloud infrastructure (DigitalOcean VPS, 4 vCPU / 8 GB RAM), authenticated normal-load testing profile (100 concurrent users with 50/20/10/10/10% distribution), scheduled weekly maintenance window (Sundays 02:00-04:00 America/Mexico_City; 1-minute HTTP health probe downtime rule), and disaster recovery standards (RTO 4 hours, RPO 24 hours, GPG AES-256 pre-upload backup encryption). |

---

## 2. Executive Summary of V3 Changes

This Version 3 (V3 Draft) specifically resolves the operational and deployment bottlenecks documented as open issues in V2, thereby unlocking concrete downstream implementation for:
1. **CMP-13:** HTTPS domain termination and reverse proxy hardening.
2. **CMP-40 / CMP-41:** Automated database/document backup routines and verification of the tested recovery runbook.
3. **CMP-42:** Synthetic uptime monitoring probes, alert thresholds, and SLA reporting.
4. **CMP-44:** Automated multi-user performance and load testing.

---

## 3. Updated Non-Functional Requirements (NFR)

The following non-functional requirements have been refined with the concrete operational decisions accepted in ADR-030 through ADR-033:

### NFR-008: Standard Operations Response Time under Normal Load
* **Priority:** Should
* **Category:** Performance Requirements
* **Requirement:** Under the defined normal-load test profile (**ADR-031**), at least 95% of standard operations (excluding AI inference and document processing) shall complete within 2.0 seconds.
* **Acceptance Criteria:** A performance test executed with the documented normal-load profile of 100 concurrent authenticated users (**ADR-031 / OI-15**) shall show that $\text{Percentile}_{95}(\text{Duration}) \le 2.0\text{ s}$ across dashboard requests, requirements browsing, document listings, searches, and document downloads.
* **Status:** **Resolved via ADR-031**.

### NFR-009: AI Assistant Response Time under Normal Load
* **Priority:** Should
* **Category:** AI Requirements / Performance Requirements
* **Requirement:** Under the defined normal-load test profile (**ADR-031**), at least 90% of AI compliance questions shall receive a response within 10.0 seconds.
* **Acceptance Criteria:** A performance test executed with the documented normal-load profile (**ADR-031 / OI-15**) shall demonstrate that at least 90% of `POST /api/v1/ai/ask` requests receive an authoritative response (grounded with citations or an insufficient-information disclaimer) within 10.0 seconds.
* **Status:** **Resolved via ADR-031**.

### NFR-011: Concurrent User Capacity
* **Priority:** Should
* **Category:** Performance / Scalability Requirements
* **Requirement:** The system shall support at least 100 concurrent authenticated users while meeting the response-time requirements defined in NFR-008 and NFR-009.
* **Acceptance Criteria:** A load test simulating 100 concurrent virtual users following the workload distribution defined in **ADR-031** (50% dashboard/requirements, 20% document list/search, 10% document detail/download, 10% document upload, 10% AI questions) on the target host (**ADR-030**) shall demonstrate sustained stability with error rate $< 1\%$ while satisfying NFR-008 and NFR-009.
* **Status:** **Resolved via ADR-030 and ADR-031**.

### NFR-012: Monthly Availability and Scheduled Maintenance
* **Priority:** Should
* **Category:** Availability Requirements
* **Requirement:** The system shall maintain at least 99.0% monthly availability, excluding scheduled maintenance periods that are documented in advance (**ADR-032**).
* **Acceptance Criteria:** 
  1. Scheduled maintenance is restricted to **every Sunday between 02:00 and 04:00 America/Mexico_City** (**ADR-032 / OI-16**).
  2. Unscheduled downtime is measured by external synthetic monitoring checking `GET /api/health` every 1 minute (**CMP-42**). Any non-200 check outside maintenance windows is logged as 1 minute of downtime.
  3. Total unscheduled downtime in any calendar month shall not exceed $1.0\%$ of available operating time ($\le 427.2\text{ minutes}$ in a 30-day month).
* **Status:** **Resolved via ADR-032**.

### NFR-015: Automated Database and Document Backups
* **Priority:** Must
* **Category:** Security / Data Protection Requirements
* **Requirement:** The system shall automatically perform at least one database and document backup every 24 hours. Each completed backup shall have a recorded creation date and time, and backup completion shall be monitored for failures.
* **Acceptance Criteria:** 
  1. An automated daily cron job executes at 03:00 America/Mexico_City generating a complete PostgreSQL (`pg_dump`) and document file archive.
  2. The system enforces a **Recovery Point Objective (RPO) of 24 hours** (**ADR-033 / OI-17**).
  3. All backups are symmetrically encrypted locally on the server using **GPG with AES-256** before transmission to off-site cloud storage (**ADR-033**).
  4. Any failed backup triggers an immediate notification to the lead product engineer.
* **Status:** **Resolved via ADR-030 and ADR-033**.

### NFR-016: Backup Retention and Disaster Recovery Runbook
* **Priority:** Should
* **Category:** Data Protection / Business Continuity
* **Requirement:** The system shall retain database backups for at least 30 days. The organization shall define and test a backup restoration procedure to verify that retained backups can be restored successfully within the defined Recovery Time Objective.
* **Acceptance Criteria:** 
  1. Off-site backup storage retains at least 30 daily encrypted snapshots with automated lifecycle pruning.
  2. The system enforces a **Recovery Time Objective (RTO) of 4 hours** (**ADR-033 / OI-17**).
  3. A documented restoration procedure (`restore-backup.sh`) successfully restores an encrypted backup into an isolated staging environment during scheduled monthly drills in $< 4\text{ hours}$.
* **Status:** **Resolved via ADR-033**.

---

## 4. Open Issues Resolution Matrix (Section 15 Update)

| ID | Open Issue | Related | Needed Before | Status in V3 | Resolution / Reference |
|:---:|---|:---:|:---:|:---:|---|
| **OI-01** | Exact AI model to be used. | AIR-002, NFR-009 | AI development | Open | Pending evaluation of local vs API models. |
| **OI-02** | List of AI providers authorized to process organizational documents. | NFR-004, BR-010 | AI development | Open | Pending stakeholder privacy sign-off. |
| **OI-03** | Final vector storage solution. | NFR-019 | AI development | Open | Evaluation of `pgvector` vs standalone vector store. |
| **OI-04** | Supported notification periods; whether email will be added later. | FR-015 | Alerts module | Open | In-app alerts defined; email delivery pending. |
| **OI-05** | AI evaluation method, relevance and accuracy thresholds, definition of a "relevant" section, and handling of conflicting documents. | FR-011, FR-012, NFR-018, AIR-008 | AI testing | Open | Test rubric in development. |
| **OI-06** | Organization and documents to be used for testing. | AIR-008 | Testing phase | Open | Test dataset collection in progress. |
| **OI-07** | **Final deployment infrastructure.** | **NFR-012, NFR-015** | **Deployment** | **Resolved** | **Resolved in ADR-030:** Deployed on a single VPS on DigitalOcean (4 vCPU / 8 GB RAM / 160 GB NVMe SSD) running Ubuntu 24.04 LTS and Docker Compose. Domain: `compliance-platform.online`. Monthly Cost: $48 USD/mo Droplet + $1/mo domain. Payer: Semester project budget (Fernando Cisneros / Emilio Rangel). Access: Fernando Cisneros & Emilio Rangel (SSH keys), Bitwarden team vault. |
| **OI-08** | Organization characteristics that affect applicability, criteria configuration, and inactive requirements. | FR-007 | Compliance module | Open | In design. |
| **OI-09** | Allowed organization types, industries, and field formats. | FR-001 | Organization module | Open | In design. |
| **OI-10** | Multi-organization tenancy, user memberships, and lifecycle. | FR-001, FR-018 | Data model design | Open | Single-tenant MVP with multi-org schema prep. |
| **OI-11** | Document categories and expiration date mandates. | FR-004 | Document module | Open | Categories defined; expiration rule pending. |
| **OI-12** | Document deletion/archival, version restore limits, and searchability of expired files. | FR-005, BR-003 | Document module | Open | Soft deletion planned. |
| **OI-13** | Rule to decide when an uploaded document satisfies a required document. | FR-014 | Compliance module | Open | Manual compliance staff approval flow. |
| **OI-14** | Confirmation of permissions matrix, audit-log access, and Management role separation. | FR-018, Sec 2.7 | Authorization design | Open | Matrix approved in V2; final review pending. |
| **OI-15** | **Normal-load test profile (concurrent users and request distribution).** | **NFR-008, NFR-009, NFR-011** | **Performance testing** | **Resolved** | **Resolved in ADR-031:** Established authenticated profile with **100 concurrent users** and request distribution totaling **100%**: 50% Dashboard/Requirements GET, 20% Document List & Search, 10% Document Detail & Download, 10% Document Upload, 10% AI Questions. User pacing 2-5s. Enforced in CMP-44 load tests. |
| **OI-16** | **Scheduled maintenance windows and downtime measurement.** | **NFR-012** | **Deployment** | **Resolved** | **Resolved in ADR-032:** Maintenance window scheduled **every Sunday 02:00-04:00 America/Mexico_City** (2h/wk excluded from SLA). Downtime defined as **any 1-minute probe of `GET /api/health` that does not return HTTP 200** outside approved maintenance windows. SLA: 99.0% monthly availability. |
| **OI-17** | **Recovery time objective (RTO), recovery point objective (RPO), and backup encryption.** | **NFR-015, NFR-016** | **Deployment** | **Resolved** | **Resolved in ADR-033:** Targets set to **RTO = 4 hours** and **RPO = 24 hours**. Backups are **encrypted with GPG AES-256 before leaving the server**. Symmetric encryption passphrase stored exclusively in the team's Bitwarden password manager vault. Daily automated backup at 03:00, 30-day retention, monthly restoration drill. |
| **OI-18** | Retention periods for audit logs and documents; privacy requirements. | NFR-001, NFR-014 | Data model design | Open | 5-year default retention proposed. |
| **OI-19** | Password policy, password reset, account recovery, and incident handling. | NFR-005, NFR-006 | Authentication module | Open | In design. |
| **OI-20** | Usability and accessibility standards. | All UI requirements | UI design | Open | WCAG 2.1 AA target proposed. |
| **OI-21** | Scalability targets for documents, storage, organizations, AI requests. | NFR-011 | Architecture design | Open | Baseline 10,000 docs / 100 GB. |
| **OI-22** | Measurable maintainability requirements. | NFR-021, NFR-022 | Architecture design | Open | CI test coverage > 80% target proposed. |

---

## 5. Detailed Resolution Records (Section 15.1)

### 5.1 Resolution of OI-07: Final Deployment Infrastructure
* **Reference:** [ADR-030: Deployment Infrastructure and Hosting Platform](file:///home/erangel/Desktop/uni/9no/compliance-platform/docs/decisions/ADR-030-deployment.md)
* **Summary of Decision:**
  * Evaluated dedicated VPS running Docker Compose vs. managed PaaS (Render / Railway).
  * Selected a dedicated VPS on **DigitalOcean** sized at **4 vCPU, 8 GB RAM, 160 GB NVMe SSD, and 5 TB monthly transfer** running **Ubuntu 24.04 LTS**.
  * Total monthly expenditure is **$48.00 USD/month** ($49.00 USD/month including domain registration), saving >60% compared to PaaS ($120+/month) while maintaining complete architectural parity with local containerized development.
  * Primary application domain: **`compliance-platform.online`**.
  * Financial responsibility: Managed under the semester engineering budget sponsored by Fernando Cisneros Torres and Emilio Rangel Torres.
  * Access control: Restrictive SSH key authentication (Ed25519) for Fernando Cisneros and Emilio Rangel; CI/CD deployment runner token via GitHub Actions; team secrets stored in Bitwarden Organization Vault.

### 5.2 Resolution of OI-15: Normal-Load Test Profile
* **Reference:** [ADR-031: Normal-Load Test Profile and Workload Distribution](file:///home/erangel/Desktop/uni/9no/compliance-platform/docs/decisions/ADR-031-load-profile.md)
* **Summary of Decision:**
  * Adopted a standard workload profile of **100 concurrent authenticated virtual users** executing tasks representative of small organization compliance workflows.
  * Request mix distribution sums to exactly **100%**:
    1. **50%:** Dashboard and compliance requirement views (`GET /api/v1/dashboard`, `GET /api/v1/requirements`). Target: 95% of requests $\le 2.0\text{ s}$ (**NFR-008**).
    2. **20%:** Document list and metadata search queries (`GET /api/v1/documents`, `GET /api/v1/documents/search`). Target: 95% of requests $\le 2.0\text{ s}$ (**NFR-008**).
    3. **10%:** Document details and binary file downloads (`GET /api/v1/documents/{id}/download`). Target: 95% of requests $\le 2.0\text{ s}$ (**NFR-008**).
    4. **10%:** Multipart document uploads (`POST /api/v1/documents`). Target: 95% of requests $\le 2.0\text{ s}$ API acknowledge, queue ingestion $\le 5.0\text{ s}$ (**NFR-010**).
    5. **10%:** AI regulatory assistance questions (`POST /api/v1/ai/ask`). Target: 90% of requests $\le 10.0\text{ s}$ (**NFR-009**).
  * Pacing: 2.0 to 5.0 seconds user think time between interactions.

### 5.3 Resolution of OI-16: Scheduled Maintenance Windows and Downtime Measurement
* **Reference:** [ADR-032: Service Availability, Scheduled Maintenance Window, and Downtime Measurement](file:///home/erangel/Desktop/uni/9no/compliance-platform/docs/decisions/ADR-032-availability.md)
* **Summary of Decision:**
  * **Recurring Maintenance Window:** Established every **Sunday from 02:00 to 04:00 America/Mexico_City** (UTC-6). Downtime during this 2-hour window is formally excluded from the monthly availability SLA.
  * **Downtime Measurement Rule:** Continuous automated synthetic monitoring queries `GET /api/health` every 60 seconds (1 minute). Any probe outside the scheduled maintenance window that fails to return HTTP 200 (including $5xx$ errors, gateway timeouts $> 10\text{ s}$, or connection dropped) is recorded as exactly one (1) minute of downtime.
  * **SLA Baseline:** Enforces $\ge 99.0\%$ monthly uptime ($\le 427.2\text{ minutes}$ of allowable unscheduled outage per 30-day billing cycle).

### 5.4 Resolution of OI-17: Recovery Time, Recovery Point, and Backup Encryption
* **Reference:** [ADR-033: Disaster Recovery Objectives and Pre-Upload Backup Encryption](file:///home/erangel/Desktop/uni/9no/compliance-platform/docs/decisions/ADR-033-backup-recovery.md)
* **Summary of Decision:**
  * **Recovery Point Objective (RPO):** Fixed at **24 hours**, achieved via daily automated backups executed every day at 03:00 America/Mexico_City.
  * **Recovery Time Objective (RTO):** Fixed at **4 hours**, governing the maximum duration to provision replacement infrastructure and restore data from archives.
  * **Backup Encryption:** Backups are **encrypted prior to network egress using GPG with AES-256** symmetric cipher (`gpg --symmetric --cipher-algo AES256`).
  * **Key Management:** The encryption passphrase is a 64-character pseudo-random string stored **exclusively in the team's password manager** (Bitwarden Organization Vault) and never committed to code repositories.
  * **Retention & Drills:** 30-day retention on secure off-site object storage with automated monthly restoration drills into an isolated environment.

---

## 6. Traceability and Downstream Ticket Mapping

| Open Issue | Status | ADR Document | Unblocked Implementation Tickets |
|---|:---:|---|---|
| **OI-07** | **Resolved** | `docs/decisions/ADR-030-deployment.md` | **CMP-13** (HTTPS / Let's Encrypt), **CMP-40** (Backup infra) |
| **OI-15** | **Resolved** | `docs/decisions/ADR-031-load-profile.md` | **CMP-44** (Load & performance testing) |
| **OI-16** | **Resolved** | `docs/decisions/ADR-032-availability.md` | **CMP-42** (Uptime & health monitoring) |
| **OI-17** | **Resolved** | `docs/decisions/ADR-033-backup-recovery.md` | **CMP-40** (Automated backup job), **CMP-41** (Restoration test) |
