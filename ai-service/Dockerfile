FROM python:3.12-slim-bookworm
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends curl && rm -rf /var/lib/apt/lists/*

# Local embeddings (ADR-010): CPU-only torch keeps the image smaller than the CUDA build.
RUN pip install --no-cache-dir "torch>=2.6,<3" --index-url https://download.pytorch.org/whl/cpu
COPY requirements.txt requirements-embeddings.txt ./
RUN pip install --no-cache-dir -r requirements.txt -r requirements-embeddings.txt

# Model weights and the tokenizer are downloaded at build time only (ADR-010/ADR-011):
# at runtime the service never contacts huggingface.co or the tiktoken CDN.
ENV HF_HOME=/opt/hf-cache \
    TIKTOKEN_CACHE_DIR=/opt/tiktoken-cache \
    EMBEDDING_MODEL=BAAI/bge-m3
RUN python -c "import tiktoken; tiktoken.get_encoding('o200k_base')" \
 && python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('BAAI/bge-m3', device='cpu')"
ENV HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1

COPY . .
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
