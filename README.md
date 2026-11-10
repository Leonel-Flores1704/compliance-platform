# Compliance Platform

Monorepo: React frontend, Laravel API, FastAPI AI service and PostgreSQL (pgvector), all running locally with Docker Compose.

## Prerequisites

- Docker Desktop with Docker Compose v2 or newer (tested with Docker 29 and Compose v5)
- Git
- Free ports: 5173, 8000, 5432 and 6379
- About 8 GB of RAM assigned to Docker (the ai-service runs the bge-m3 embedding model and needs at least 4 GB by itself, ADR-010)
- About 6 GB of free disk for the ai-service image (CPU PyTorch + bge-m3 weights)

## Setup

```bash
git clone -b develop https://github.com/Leonel-Flores1704/compliance-platform.git
cd compliance-platform
cp .env.example .env
```

Edit `.env` and set `DB_PASSWORD` **before the first start**. Leave `AI_SERVICE_TOKEN` empty for now (defined in CMP-07).

```bash
docker compose up -d --build
docker compose ps
docker compose exec backend php artisan migrate
```

The first build downloads images and can take several minutes; the ai-service build also downloads PyTorch (CPU) and the bge-m3 model (about 2.3 GB) once, so expect 10-20 minutes. At runtime the ai-service never downloads models (`HF_HUB_OFFLINE=1`). Once the images are built, all 6 containers should report `healthy` in about 2 minutes. On the first start the backend installs its Composer dependencies and generates its `.env` and `APP_KEY` by itself.

## Verify

- API: http://localhost:8000/api/health should return `{"status":"ok","database":"ok","ai_service":"ok"}`
- Frontend: http://localhost:5173 should show "Compliance Platform" and "API status: ok"

| Case | HTTP | Response |
|---|---|---|
| Database down | 503 | `{"status":"error","database":"unreachable"}` |
| AI service down | 503 | `{"status":"degraded","database":"ok","ai_service":"unreachable"}` |

## Services

| Service | Port | Notes |
|---|---|---|
| frontend | 5173 | React 18 + Vite |
| backend | 8000 | Laravel 11, PHP 8.3 |
| queue-worker | none | Laravel queue on Redis |
| ai-service | 8001 | FastAPI, internal network only |
| db | 5432 | PostgreSQL 16 + pgvector |
| redis | 6379 | Redis 7 |

## Optional: Nginx reverse proxy

Nginx is not part of the 6 default containers. To run it:

```bash
docker compose --profile proxy up -d
```

The API is then reachable through the proxy at http://localhost:8080/api/health. Serving the frontend through the proxy is not supported yet. Stop it with `docker compose --profile proxy down`.

## Running tests

```bash
# Backend
docker compose exec backend vendor/bin/pest
docker compose exec backend vendor/bin/pint --test

# AI service
docker compose exec ai-service pytest
docker compose exec ai-service ruff check .

# Frontend
docker compose exec frontend npm test
docker compose exec frontend npm run lint
docker compose exec frontend npm run build
```

## Branches and Pull Requests

- `main`: stable. `develop`: integration. Work on `feature/CMP-xx-short-name`.
- Open Pull Requests to `develop`, titled `CMP-xx: <task title>`.
- At least 1 approval and the 3 CI jobs (`frontend`, `backend`, `ai-service`) must pass.
- `main` and `develop` are protected: no direct pushes.

## Troubleshooting

- **`expected 1536 dimensions, not 1024` when indexing documents:** the database was migrated before CMP-29 changed the embedding dimension to 1024 (bge-m3, ADR-010). Recreate the schema with `docker compose exec backend php artisan migrate:fresh --seed` (this deletes local data).
- **Database password error after changing `DB_PASSWORD`:** PostgreSQL only reads the password when its volume is created. Recreate it with `docker compose down -v` (this deletes the local database).
- **Windows line endings:** the repo enforces LF. Keep `entrypoint.sh` in LF or the backend will not start.