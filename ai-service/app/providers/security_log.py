"""Security log for blocked AI calls: one JSON line per event in logs/ai-security.log and stdout."""

import json
import os
import sys
import threading
from datetime import datetime, timezone
from pathlib import Path

DEFAULT_LOG_PATH = Path(__file__).resolve().parents[2] / "logs" / "ai-security.log"

_lock = threading.Lock()


def log_path() -> Path:
    return Path(os.getenv("AI_SECURITY_LOG_PATH", str(DEFAULT_LOG_PATH)))


def log_provider_blocked(host: str, purpose: str, request_id: str | None) -> dict:
    event = {
        "timestamp": datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z"),
        "event": "provider_blocked",
        "host": host,
        "purpose": purpose,
        "request_id": request_id,
    }
    line = json.dumps(event, ensure_ascii=False)

    with _lock:
        path = log_path()
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("a", encoding="utf-8") as file:
            file.write(line + "\n")
        sys.stdout.write(line + "\n")
        sys.stdout.flush()

    return event
