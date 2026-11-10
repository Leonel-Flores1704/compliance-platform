"""PostgreSQL access for the AI service (pgvector, ADR-012). The schema is owned by Laravel migrations."""

import os

import psycopg


class DatabaseNotConfiguredError(RuntimeError):
    pass


def connect() -> psycopg.Connection:
    url = os.getenv("DATABASE_URL")
    if not url:
        raise DatabaseNotConfiguredError("DATABASE_URL is not set.")
    return psycopg.connect(url, connect_timeout=5)
