# ADR-030: Deployment Infrastructure and Hosting Platform

* **Status:** Accepted
* **Date:** 2026-10-19
* **Deciders:** 
  * Fernando Cisneros Torres (Lead Product Engineer)
  * Emilio Rangel Torres (Compliance Product Engineer)
  * Leonel De la Cruz Flores (Product Design Engineer)
  * Hector Ernesto Torres Carrizales (AI Product Engineer)
* **Ticket / Issue:** [CMP-05] Resolve deployment and operations open issues (OI-07)
* **Related Requirements:** NFR-012, NFR-015, NFR-021, NFR-022, CMP-13, CMP-40, CMP-41, CMP-42, CMP-44

---

## 1. Context and Problem Statement

The Compliance Management and Document Intelligence System requires a concrete, reproducible deployment target for production and staging operations. Previous requirements left the target infrastructure unspecified (**OI-07** in SRS V2). Concrete infrastructure specifications are strictly required before implementing:
- HTTPS termination and reverse proxy configuration (**CMP-13**)
- Automated database and file storage backup procedures (**CMP-40**, **CMP-41**, **NFR-015**, **NFR-016**)
- Uptime monitoring and alert routing (**CMP-42**, **NFR-012**)
- Load testing verification at scale (**CMP-44**, **NFR-008**, **NFR-009**, **NFR-011**)

The system architecture is a containerized monorepo consisting of:
1. **Frontend:** React 18 + Vite SPA served via Nginx.
2. **Backend:** Laravel 11/12 REST API (PHP 8.3-FPM + Nginx) handling business logic and authorization.
3. **Queue Worker:** Laravel asynchronous worker consuming jobs from Redis.
4. **AI Microservice:** Python 3.11+ / FastAPI runtime hosting RAG pipelines and vector search endpoints.
5. **Database:** PostgreSQL 16 with the `pgvector` extension for structured and embedding storage.
6. **Cache & Queue:** Redis 7 (Alpine).
7. **Reverse Proxy & SSL:** Nginx with automated Let's Encrypt / Certbot TLS renewal.

We must determine the hosting model, cloud provider, compute sizing, operating system, network/domain configuration, cost, and access management.

---

## 2. Decision Drivers

* **Resource Sufficiency:** Must run 6 concurrent containers (including PostgreSQL with `pgvector`, Redis, and Python AI microservice) and sustain 100 concurrent users (**NFR-011**, **ADR-031**).
* **Parity with Development:** Must preserve the exact Docker Compose environment configured in the repository, minimizing infrastructure drift.
* **Cost Predictability:** Academic / startup project constraints require low and deterministic monthly expenditure with no unexpected usage spikes.
* **Operational Simplicity:** A small team of 4 engineers requires centralized management, streamlined CI/CD deployments, and straightforward access for backups and monitoring.
* **Data Sovereignty and Security:** Direct filesystem access for local pre-upload encryption of backups (**ADR-033**, **NFR-015**) and isolation of organizational documents.

---

## 3. Considered Options

* **Option 1 (Proposed):** Single Virtual Private Server (VPS) running Docker Compose (DigitalOcean Droplet or AWS Lightsail).
* **Option 2 (Alternative):** Managed Platform-as-a-Service (PaaS) ecosystem (Render or Railway).

---

## 4. Evaluation of Options

### Option 1: Single VPS with Docker Compose (DigitalOcean / AWS Lightsail)

Deploying a dedicated virtual machine running Ubuntu Linux and Docker Compose provides an isolated environment where all containers communicate over an internal Docker bridge network (`compliance-platform-network`).

* **DigitalOcean Droplet:** 4 vCPU (Shared AMD/Intel), 8 GB RAM, 160 GB NVMe SSD, 5 TB Transfer = **$48.00 USD / month**.
* **AWS Lightsail:** 4 vCPU, 8 GB RAM, 160 GB SSD, 5 TB Transfer = **$44.00 USD / month**.
* **Pros:**
  * **Direct Docker Compose Compatibility:** The production environment directly mirrors local development (`docker-compose.yml`), eliminating vendor lock-in and multi-manifest maintenance.
  * **Inter-Service Latency:** Internal network calls between Laravel, FastAPI, Redis, and PostgreSQL execute via localhost / Docker bridge with sub-millisecond latency.
  * **Storage & Backup Control:** Native Linux cron can directly execute `pg_dump` and storage snapshot commands, encrypt them with GPG locally (**ADR-033**), and push to off-site object storage.
  * **Cost Control:** Fixed, flat monthly cost ($48/month) with generous bandwidth limits (5 TB), avoiding runaway egress charges.
* **Cons:**
  * Requires basic OS administration, security hardening (UFW firewall, fail2ban, unattended-upgrades), and Docker daemon maintenance.

### Option 2: Managed PaaS (Render / Railway)

Deploying components as separate managed PaaS resources.

* **Render Cost Breakdown:**
  * Backend Web Service (Laravel): Standard (2 GB RAM, 1 vCPU) = $25.00 / month.
  * AI Web Service (FastAPI): Standard (2 GB RAM, 1 vCPU) = $25.00 / month.
  * Background Worker (Laravel Queue): Standard (2 GB RAM) = $25.00 / month.
  * Frontend Static Site: Free - $7.00 / month.
  * Managed PostgreSQL with `pgvector` support: Starter/Pro (1 GB - 4 GB RAM) = $35.00 / month.
  * Managed Redis: Starter = $10.00 / month.
  * **Total Estimated Cost:** **$120.00 - $127.00 USD / month**.
* **Railway Cost Breakdown:**
  * Resource usage metering ($0.000231/GB-hr + $0.000463/vCPU-hr) across 6 active services averaging ~6-8 GB RAM aggregate = **$75.00 - $95.00 USD / month**.
* **Pros:**
  * Automated TLS certificate issuance and zero-downtime rolling deploys managed by the platform.
  * Managed database maintenance and automated snapshot backups.
* **Cons:**
  * **High Cost:** 2.5x more expensive than Option 1, exceeding the allocated semester infrastructure budget.
  * **Architecture Fragmentation:** Requires separate services, environment variable synchronizations, and cross-service HTTP routing over public/private mesh networks.
  * **Queue Worker Costs:** Running a continuous Laravel queue worker requires paying for a full separate service instance.
  * **Limited Pre-upload Backup Encryption Control:** Harder to inject custom GPG client-side symmetric encryption workflows before storing backups.

---

## 5. Decision Outcome

**Selected Option:** **Option 1 — Virtual Private Server (VPS) on DigitalOcean running Docker Compose.**

DigitalOcean is selected due to its competitive pricing, predictable billing, reliable NVMe storage, and developer-friendly tooling.

### Detailed Deployment Specifications

1. **Cloud Provider:** DigitalOcean (Region: `nyc3` or `sfo3` to ensure low-latency access to Mexico/North America).
2. **Compute Instance Size:**
   * **vCPU:** 4 vCPU (Regular / Premium Shared AMD)
   * **RAM:** 8 GB RAM
   * **Storage:** 160 GB NVMe SSD
   * **Network Bandwidth:** 5,000 GB (5 TB) monthly transfer included
3. **Operating System:** Ubuntu 24.04 LTS (Noble Numbat) x86_64.
4. **Container Orchestrator:** Docker Engine 27.x + Docker Compose v2.
5. **Reverse Proxy & SSL:** Nginx container binding ports 80/443 with automated Let's Encrypt certificate renewal via Certbot container.
6. **Domain Name:** `compliance-platform.online`
   * Primary Application URL: `https://compliance-platform.online`
   * API Gateway / Health: `https://compliance-platform.online/api`
   * DNS Management: DigitalOcean DNS / Cloudflare (Proxied TLS with strict origin encryption).
7. **Financial Responsibility (Who Pays):**
   * **Monthly Cost:** **$48.00 USD / month** (Compute instance) + **$1.00 USD / month** (Domain registration amortized at $12.00/year). Total: **$49.00 USD / month**.
   * **Payer:** Funded through the project semester budget, sponsored and managed by Fernando Cisneros Torres (Lead Product Engineer) and Emilio Rangel Torres (Compliance Product Engineer).
8. **Access Management and Security (Who Has Access):**
   * **Infrastructure / Root SSH Access:**
     * Fernando Cisneros Torres (`fernando.cisneros@compliance-platform.online`)
     * Emilio Rangel Torres (`emilio.rangel@compliance-platform.online`)
     * Access enforced via Ed25519 SSH key pairs; root password authentication disabled; SSH port standard with UFW restrictions.
   * **CI/CD Deployment:**
     * GitHub Actions deployment runner using dedicated non-root deploy user (`deployer`) authorized only to pull images and execute `docker compose up -d`.
   * **Secrets & Environment Variables:**
     * Centralized in the team password manager (Bitwarden Organization Vault), accessible by all 4 development team members.

---

## 6. Consequences

### Positive
* Production architecture is completely identical to local containerized development.
* Fixed, transparent monthly cost within the academic team budget ($48/mo).
* Sub-millisecond communication between backend and AI microservice over internal bridge.
* Unlocks concrete targets for CMP-13 (HTTPS domain), CMP-40/41 (backups on local disk/S3), CMP-42 (monitoring endpoint), and CMP-44 (load testing against dedicated host).

### Negative / Risks and Mitigations
* *Single Point of Failure (Single VPS):* Mitigated by automated daily encrypted off-site backups (**ADR-033**) and a documented recovery time objective (RTO) of 4 hours.
* *Resource Contention:* 8 GB RAM is partitioned via Docker Compose resource limits (Postgres: 2 GB, AI Microservice: 2 GB, Laravel Backend & Worker: 2 GB, Redis: 512 MB, Nginx & OS: ~1.5 GB) to prevent OOM termination.

---

## 7. Requirement Traceability

* **Resolves Open Issue:** **OI-07** (Final deployment infrastructure).
* **Satisfies Requirements:** **NFR-012** (Host availability baseline), **NFR-015** (Storage baseline for automated backups), **NFR-021** (Dedicated PostgreSQL 16 instance), **NFR-022** (Internal network separation of Laravel and FastAPI).
