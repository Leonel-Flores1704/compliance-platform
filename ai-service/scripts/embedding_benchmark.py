"""Measure bge-m3 on CPU with the test documents (ADR-010 checklist) and preview
the similarity threshold (ADR-013).

Usage (from ai-service/):
    pip install sentence-transformers pypdf python-docx psutil
    python scripts/embedding_benchmark.py

Prints: model load time, embedding time for all text documents, peak RAM, and for
every seed question the best document-level similarity (answerable vs unanswerable).
Scanned PDFs are skipped here because they need OCR (CMP-26).
"""
import json
import time

import psutil
from sentence_transformers import SentenceTransformer
from compare_models import doc_text, DOCS, ROOT

def chunks(text, size=1800):  # ~500 tokens of Spanish/English text
    return [text[i:i + size] for i in range(0, len(text), size - 250)] or [""]

t0 = time.perf_counter()
model = SentenceTransformer("BAAI/bge-m3", device="cpu")
print(f"Model load: {time.perf_counter() - t0:.1f}s")
manifest = json.load(open(DOCS / "manifest.json", encoding="utf-8"))
texts, owners = [], []
for d in manifest:
    if d["kind"] == "scan":
        continue
    for c in chunks(doc_text(d["file"], max_chars=100000)):
        texts.append(c)
        owners.append(d["file"])
t0 = time.perf_counter()
emb = model.encode(texts, normalize_embeddings=True, batch_size=16)
print(f"Embedded {len(texts)} chunks in {time.perf_counter() - t0:.1f}s; dim={emb.shape[1]}")
print(f"Peak RSS: {psutil.Process().memory_info().rss / 1e9:.2f} GB")
qs = [json.loads(line) for line in open(ROOT / "eval" / "seed_questions.jsonl", encoding="utf-8")]
qe = model.encode([q["question"] for q in qs], normalize_embeddings=True)
for q, v in zip(qs, qe):
    sims = emb @ v
    i = int(sims.argmax())
    print(f"{q['id']} answerable={q['answerable']!s:5} top1={sims[i]:.3f} doc={owners[i]}")
