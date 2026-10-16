from fastapi import FastAPI

app = FastAPI(title="Compliance AI Service")


@app.get("/health")
def health():
    return {"status": "ok"}