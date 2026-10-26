import json
from pathlib import Path

from app.main import app


def export_openapi() -> Path:
    openapi_schema = app.openapi()
    output_path = Path(__file__).resolve().parent.parent / "openapi.json"
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(openapi_schema, f, indent=2)
        f.write("\n")
    return output_path


if __name__ == "__main__":
    path = export_openapi()
    print(f"Exported OpenAPI 3.1 specification to {path}")
