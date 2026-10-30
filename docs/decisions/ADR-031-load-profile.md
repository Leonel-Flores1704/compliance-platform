# ADR-031: Normal-Load Test Profile and Workload Distribution

* **Status:** Accepted
* **Date:** 2026-10-19
* **Deciders:** 
  * Fernando Cisneros Torres (Lead Product Engineer)
  * Emilio Rangel Torres (Compliance Product Engineer)
  * Leonel De la Cruz Flores (Product Design Engineer)
  * Hector Ernesto Torres Carrizales (AI Product Engineer)
* **Ticket / Issue:** [CMP-05] Resolve deployment and operations open issues (OI-15)
* **Related Requirements:** NFR-008, NFR-009, NFR-011, CMP-44, FR-002, FR-003, FR-004, FR-010, FR-011

---

## 1. Context and Problem Statement

Non-functional requirements **NFR-008**, **NFR-009**, and **NFR-011** specify system performance standards that must be validated during performance and load testing (**CMP-44**):
- **NFR-008:** Under the defined normal-load test profile, at least 95% of standard operations (excluding AI inference and document processing) must complete within 2.0 seconds.
- **NFR-009:** Under the defined normal-load test profile, at least 90% of AI questions must receive a response within 10.0 seconds.
- **NFR-011:** The system must support at least 100 concurrent authenticated users while meeting NFR-008 and NFR-009.

Previously, the workload composition (number of concurrent sessions, request mix, and behavior) was marked as an open issue (**OI-15** in SRS V2). Without an approved workload profile, load testing tools (e.g., k6, Locust, or JMeter) cannot simulate realistic user behavior or generate valid pass/fail assertions.

---

## 2. Decision Drivers

* **Realistic Operational Behavior:** Reflect the daily workflow of compliance staff and general employees in small organizations.
* **Coverage of Core Capabilities:** Must stress read operations (dashboard, searches, downloads), write/disk operations (multipart document uploads), and compute-intensive operations (vector similarity search + LLM inference).
* **Strict Mathematical Integrity:** Request distribution percentages must sum to exactly 100%.
* **Test Automation Suitability:** Profile must translate directly into automated performance scripts for **CMP-44**.

---

## 3. Decision Outcome

**Selected Workload Profile:**
The system adopts an authenticated normal-load profile based on **100 concurrent active users** distributed across 5 operational categories totaling exactly **100%**.

### 3.1 Workload Distribution Table

| Category | Workload % | Representative HTTP Endpoint(s) | Typical Payload / Method | Target SLA |
|---|:---:|---|---|---|
| **1. Dashboard & Requirement Views** | **50%** | `GET /api/v1/dashboard`<br>`GET /api/v1/requirements`<br>`GET /api/v1/requirements/{id}` | Cacheable / light JSON responses | **NFR-008** (p95 < 2.0s) |
| **2. Document List & Search** | **20%** | `GET /api/v1/documents`<br>`GET /api/v1/documents/search?q={query}` | Filtered queries with pagination and metadata | **NFR-008** (p95 < 2.0s) |
| **3. Document Detail & Download** | **10%** | `GET /api/v1/documents/{id}`<br>`GET /api/v1/documents/{id}/download` | Metadata read + binary streaming (PDF/DOCX ~1-5 MB) | **NFR-008** (p95 < 2.0s) |
| **4. Document Upload** | **10%** | `POST /api/v1/documents` | Multipart/form-data upload (PDF/DOCX <= 25 MB) with validation and job queue dispatch | **NFR-008** (p95 < 2.0s API ack)<br>**NFR-010** (< 5s queueing) |
| **5. AI Compliance Questions** | **10%** | `POST /api/v1/ai/ask` | JSON payload with user prompt, vector embedding lookup via `pgvector`, and LLM completion | **NFR-009** (p90 < 10.0s) |
| **Total** | **100%** | — | — | — |

*Check:* $50\% + 20\% + 10\% + 10\% + 10\% = 100\%$.

---

## 4. Execution Parameters for Load Testing (CMP-44)

To simulate authentic human interaction rather than aggressive denial-of-service traffic, the load testing harness shall adhere to the following test execution parameters:

1. **Concurrent Virtual Users (VUs):** 100 concurrent authenticated virtual users.
2. **Session Authentication:** Each virtual user authenticates via `POST /api/v1/auth/login` to obtain a valid bearer token before executing actions.
3. **User Think Time (Pacing):** Virtual users wait between 2.0 and 5.0 seconds (uniformly distributed) between consecutive HTTP requests.
4. **Test Phases:**
   * **Ramp-up:** 0 to 100 virtual users over 5 minutes.
   * **Steady State (Sustained Load):** 100 concurrent users sustained for 20 minutes.
   * **Ramp-down:** 100 to 0 virtual users over 3 minutes.
5. **Data Set Requirements:**
   * Pre-seeded test database containing at least 200 documents, 50 compliance requirements, and pre-computed embeddings.
   * Upload test payloads comprising realistic sample PDF and DOCX documents between 500 KB and 5 MB.
   * Realistic user queries for the AI endpoint (e.g., questions regarding vacation policies, fire safety regulations, and data retention deadlines).

---

## 5. Acceptance and Verification Criteria

A load test executed against the deployment target (**ADR-030**) shall be considered **PASSED** if and only if all the following conditions are simultaneously met:

1. **Standard Operations Response Time (NFR-008):**
   $$\text{Percentile}_{95}(\text{Duration}_{\text{Categories 1, 2, 3, 4}}) \le 2.00\text{ seconds}$$
2. **AI Assistance Response Time (NFR-009):**
   $$\text{Percentile}_{90}(\text{Duration}_{\text{Category 5}}) \le 10.00\text{ seconds}$$
3. **Queue Ingestion Time (NFR-010):**
   100% of successfully uploaded documents enter the background processing queue in $\le 5.0\text{ seconds}$.
4. **Overall Error Rate:**
   Non-AI requests must exhibit an HTTP failure rate ($5xx$ or connection drop) of $< 1.0\%$.

---

## 6. Consequences

### Positive
* Provides an unambiguous, mathematically balanced benchmark (100% total) for automated performance testing in **CMP-44**.
* Accurately reflects real-world compliance portal usage where read operations predominate over writes and AI prompts.
* Integrates directly into CI/CD performance testing pipelines.

### Negative / Risks and Mitigations
* *AI Service Throttling / Rate Limits:* 10 concurrent AI queries per 100 active users might pressure external LLM provider quotas.
  * *Mitigation:* The AI microservice implements token bucket rate limiting and uses Redis semantic caching for repeated compliance queries.

---

## 7. Requirement Traceability

* **Resolves Open Issue:** **OI-15** (Normal-load test profile).
* **Satisfies Requirements:** **NFR-008** (Standard response time under normal load), **NFR-009** (AI response time under normal load), **NFR-011** (100 concurrent authenticated users capacity).
