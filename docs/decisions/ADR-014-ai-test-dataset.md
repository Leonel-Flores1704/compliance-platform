# ADR-014: AI test organization and test documents (OI-06)

| | |
|---|---|
| **Status** | Proposed, pending team approval |
| **Date** | 2026-10-19 |
| **Owner** | Fernando Cisneros Torres |
| **Jira** | CMP-03 (used by CMP-06 seeder, CMP-25, CMP-26, CMP-29 to CMP-36, CMP-47) |
| **Related requirements** | AIR-008, OI-06 |
| **Approved by** | _pending (4 team members)_ |

## Context

The AI pipeline must be tested and evaluated with documents that look like real compliance documents but contain no confidential data. The set must cover every format (text PDF, scanned PDF, DOCX, TXT), both languages, both visibility levels, versions, conflicts and expiration dates.

## Decision

### Test organization

| Field | Value |
|---|---|
| Legal name | Manufacturas Alfa Demo, S.A. de C.V. (fictional) |
| Organization type | Private company |
| Industry | Manufacturing |
| Location | Nuevo León, Apodaca |
| Number of employees | 120 |
| Business activities | Metal parts manufacturing; Warehousing; Distribution |

### Test users (for `DemoSeeder`, CMP-06)

| Role | Name (fictional) | Email | Purpose |
|---|---|---|---|
| Administrator | Admin Demo | admin@alfademo.example | User management, organization |
| Compliance Staff | Laura Méndez Garza | compliance@alfademo.example | Sees all documents, including compliance_only |
| General Employee | Jorge Peña | employee@alfademo.example | Sees only all_staff documents (permission tests, CMP-30) |

Passwords come from `.env` (`DEMO_USER_PASSWORD`), never from the repository.

### Test documents

Location: `ai-service/tests/fixtures/documents/`. 18 documents: 8 text PDFs, 3 scanned PDFs, 4 DOCX, 3 TXT. Languages: 11 Spanish, 7 English. Visibility: 15 all_staff, 3 compliance_only.

| ID | File | Format | Lang | Category | Visibility | Version | Expiration | Test purpose |
|---|---|---|---|---|---|---|---|---|
| D01 | `licencia_ambiental_unica_2026.pdf` | PDF (text) | ES | Permit/License | all_staff | 1.0 | 2027-03-31 |  |
| D02 | `politica_riesgos_psicosociales_v1.pdf` | PDF (text) | ES | Policy | all_staff | 1.0 | - | Versión anterior (2024). Subir primero como versión 1.0 del documento; luego D03 la reemplaza como 2.0. |
| D03 | `politica_riesgos_psicosociales_v2.pdf` | PDF (text) | ES | Policy | all_staff | 2.0 | - | Versión vigente. Subir como versión 2.0 del mismo documento que D02. |
| D04 | `information_security_policy.pdf` | PDF (text) | EN | Policy | compliance_only | 3.1 | - |  |
| D05 | `programa_interno_proteccion_civil_2026.pdf` | PDF (text) | ES | Other | all_staff | 1.0 | 2026-12-31 |  |
| D06 | `supplier_code_of_conduct.pdf` | PDF (text) | EN | Policy | all_staff | 1.2 | - | Conflict test with D14 (gift limit). |
| D07 | `aviso_privacidad_integral.pdf` | PDF (text) | ES | Policy | all_staff | 2.0 | - |  |
| D08 | `internal_audit_report_2025.pdf` | PDF (text) | EN | Report | compliance_only | 1.0 | - |  |
| D09 | `dictamen_instalaciones_electricas_escaneado.pdf` | PDF (scanned, no text layer) | ES | Certificate | all_staff | 1.0 | 2028-05-15 |  |
| D10 | `constancia_uso_suelo_escaneada.pdf` | PDF (scanned, no text layer) | ES | Permit/License | all_staff | 1.0 | 2026-11-30 | Vence pronto: sirve para probar alertas de 30 días (CMP-47). |
| D11 | `fire_extinguisher_inspection_scanned.pdf` | PDF (scanned, no text layer) | EN | Certificate | all_staff | 1.0 | 2026-12-15 |  |
| D12 | `procedimiento_atencion_emergencias.docx` | DOCX | ES | Policy | all_staff | 1.0 | - |  |
| D13 | `data_retention_disposal_procedure.docx` | DOCX | EN | Policy | compliance_only | 1.0 | - |  |
| D14 | `reglamento_interior_trabajo_extracto.docx` | DOCX | ES | Regulation | all_staff | 4.0 | - | Prueba de conflicto con D06 (límite de regalos). |
| D15 | `lockout_tagout_procedure.docx` | DOCX | EN | Policy | all_staff | 2.0 | - |  |
| D16 | `minuta_comite_seguridad_2026_09.txt` | TXT | ES | Report | all_staff | 1.0 | - | Codificada en Windows-1252 para probar detección de codificación (CMP-25). |
| D17 | `training_log_summary_2026.txt` | TXT | EN | Report | all_staff | 1.0 | - |  |
| D18 | `directorio_emergencias.txt` | TXT | ES | Other | all_staff | 1.0 | - |  |

### Negative fixtures

| File | Expected result |
|---|---|
| `invalid/password_protected.pdf` | Extraction fails: 422 `EXTRACTION_FAILED`; UI shows "The file could not be read. It may be damaged or password-protected." (CMP-25) |
| `invalid/corrupted.pdf` | Same as above (CMP-25) |
| `invalid/blank_scan.pdf` | OCR produces no text: 422 `OCR_FAILED`; UI shows "The scanned document could not be read (OCR failed). Upload a clearer scan or a text-based PDF." (CMP-26) |
| `invalid/fake_executable.pdf` | Upload rejected in Laravel: "The file content does not match its extension (.pdf)." or "The file was rejected because it contains executable content." (CMP-20). Harmless: it is not a real program. |
| `invalid/empty.txt` | Upload rejected: "The file is empty." (CMP-20) |

Not included as files (generate them inside the tests): a 26,214,401-byte PDF for the size limit, and a .docm renamed to .docx for macros.

### Evaluation seed

`ai-service/eval/seed_questions.jsonl` with 42 questions: 32 answerable (Spanish and English, with expected document, page or section, and expected answer) and 10 unanswerable. Tags mark special cases: `version`, `conflict`, `compliance_only`, `cross-lingual`, `ocr`, `docx-section`, `cp1252`, `unanswerable`.

## Consequences

- CMP-06 `DemoSeeder` creates the organization and the 3 users above.
- CMP-36 extends the seed to at least 40 questions in its final form (already met) and adds reviewer ratings.
- Any new test document must be fictional and added through `content.py` so the set can be rebuilt.
