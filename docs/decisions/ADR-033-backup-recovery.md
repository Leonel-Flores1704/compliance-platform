# ADR-033: Disaster Recovery Objectives and Pre-Upload Backup Encryption

* **Status:** Accepted
* **Date:** 2026-10-19
* **Deciders:** 
  * Fernando Cisneros Torres (Lead Product Engineer)
  * Emilio Rangel Torres (Compliance Product Engineer)
  * Leonel De la Cruz Flores (Product Design Engineer)
  * Hector Ernesto Torres Carrizales (AI Product Engineer)
* **Ticket / Issue:** [CMP-05] Resolve deployment and operations open issues (OI-17)
* **Related Requirements:** NFR-015, NFR-016, CMP-40, CMP-41, NFR-001, NFR-002, NFR-013

---

## 1. Context and Problem Statement

Data protection and disaster recovery requirements dictate that the system protect organizational assets, compliance records, and embeddings against data corruption, hardware destruction, or accidental loss:
- **NFR-015:** The system shall automatically perform at least one database backup every 24 hours, recording creation timestamps and alerting on failures.
- **NFR-016:** The system shall retain backups for at least 30 days and maintain a tested restoration procedure.
- **NFR-002:** The system shall prevent unauthorized access to organizational documents stored on disk.

Previously, the exact recovery time objective (RTO), recovery point objective (RPO), encryption algorithms, and key management schemes remained unresolved (**OI-17** in SRS V2). Concrete disaster recovery targets are required before implementing automated backup scripts (**CMP-40**) and testing the documented restoration runbook (**CMP-41**).

---

## 2. Decision Drivers

* **Business Continuity:** Small organizations need clear assurances regarding maximum potential data loss and system restoration downtime.
* **Information Security & Privacy:** Compliance documents and database dumps contain confidential organizational policies and audit trails. Backups stored off-site must be protected against unauthorized disclosure or interception.
* **Regulatory Compliance:** Adherence to data protection principles ensuring that credentials and encryption passphrases are not committed to source control or exposed in server logs.
* **Operational Simplicity:** Recovery procedures must be executable within the stated time objective using straightforward, standard open-source tools.

---

## 3. Decision Outcome

### 3.1 Disaster Recovery Objectives

The team formally establishes the following recovery objectives:

* **Recovery Point Objective (RPO):** **24 hours**
  * *Definition:* In the catastrophic event of unrecoverable server failure, maximum tolerable data loss is limited to data created in the preceding 24 hours.
  * *Implementation:* Aligned with the daily automated backup cycle scheduled every day at **03:00 America/Mexico_City** (falling within the Sunday maintenance window or lowest usage period).
* **Recovery Time Objective (RTO):** **4 hours**
  * *Definition:* The maximum acceptable duration between the declaration of a disaster and the full restoration of operational service (PostgreSQL database, documents, and application containers).
  * *Implementation:* Validated through a documented, repeatable restoration script tested in an isolated staging environment (**CMP-41**).

---

### 3.2 Backup Scope and Strategy

The daily backup procedure captures two primary assets:
1. **Relational Database & Vector Embeddings:**
   * PostgreSQL database dump generated using `pg_dump -Fc` (custom binary compressed format), capturing all schema definitions, relational records, user credentials, audit trails, and `pgvector` embeddings.
2. **Document File Storage:**
   * Compressed archive (`tar.gz`) of the persistent volume storing uploaded PDFs, DOCXs, and TXT files (`/var/www/html/storage/app/documents`).

---

### 3.3 Backup Encryption Standard

* **Are backups encrypted?** **YES**
* **Encryption Algorithm:** **GPG with AES-256** (GNU Privacy Guard symmetric encryption using `AES256` cipher).
* **Encryption Timing (Pre-Upload):**
  * Backups are encrypted **locally on the VPS host BEFORE leaving the server** or being transmitted over the network.
  * Command pattern:
    ```bash
    gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file /run/secrets/backup_passphrase.key -o backup_YYYYMMDD_HHMMSS.dump.gpg backup_YYYYMMDD_HHMMSS.dump
    ```
* **Off-Site Transmission & Storage:**
  * Once encrypted on the local host, the `.gpg` files are transmitted over TLS 1.3 to off-site cloud object storage (AWS S3 / Backblaze B2 / Cloudflare R2) with bucket-level server-side encryption and versioning.
  * Local unencrypted dump files are immediately securely deleted (`shred` / `rm -f`) after encryption.

---

### 3.4 Encryption Passphrase Management

* **No Plaintext Passphrases in Git or Code:** The encryption passphrase shall **NEVER** be committed to git, recorded in `docker-compose.yml`, or stored in persistent, unencrypted disk files.
* **Team Password Manager Storage:**
  * The GPG encryption passphrase is a 64-character cryptographically secure pseudo-random string.
  * It is stored **strictly and exclusively in the team's password manager** (Bitwarden Organization Vault).
  * Access is restricted to authorized team members:
    * Fernando Cisneros Torres (Lead Product Engineer)
    * Emilio Rangel Torres (Compliance Product Engineer)
* **Host Injection:**
  * During automated execution on the production host, the passphrase is read by the backup script via a restricted, root-owned file permission (`chmod 400 /run/secrets/backup_passphrase.key`) or Docker secret.

---

### 3.5 Retention and Restoration Testing

1. **Retention Period (NFR-016):**
   * Daily backups are retained on off-site storage for **30 days**.
   * An automated lifecycle pruning policy deletes snapshots older than 30 days.
2. **Restoration Runbook & Drills (CMP-41):**
   * A step-by-step restoration script (`infra/scripts/restore-backup.sh`) decrypts the archive using the passphrase, restores database schemas via `pg_restore`, unpacks document storage volumes, and validates integrity.
   * Restoration drills shall be rehearsed monthly to verify that end-to-end recovery completes well within the **4-hour RTO** limit.

---

## 4. Consequences

### Positive
* Assures organizational stakeholders that data loss will not exceed 24 hours (RPO) and downtime will not exceed 4 hours (RTO).
* Military-grade client-side encryption (AES-256) guarantees that compromised off-site storage or intermediate network sniffing will not expose sensitive organizational compliance documents.
* Strictly fulfills the acceptance criteria of **CMP-05** and provides clear technical instructions for **CMP-40** and **CMP-41**.

### Negative / Risks and Mitigations
* *Lost Passphrase Risk:* If the encryption passphrase is lost, the encrypted backups are completely unrecoverable.
  * *Mitigation:* The passphrase is saved in redundant secure vaults within the Bitwarden Organization account and backed up securely in the team's physical emergency procedures.

---

## 5. Requirement Traceability

* **Resolves Open Issue:** **OI-17** (RTO, RPO, and backup encryption).
* **Satisfies Requirements:** **NFR-015** (Daily automated backups), **NFR-016** (30-day retention and tested procedure), **CMP-40** (Backup automation), **CMP-41** (Restoration procedure).
