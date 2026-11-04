"""Compare the main and fallback Groq models (ADR-010 validation checklist).

Usage (from ai-service/):
    pip install openai pypdf python-docx
    export GROQ_API_KEY=...        # never commit it
    python scripts/compare_models.py --out eval/reports/model_comparison.csv

For 10 seed questions (5 Spanish, 5 English) it sends the text of the expected
document as context to each model, records latency and the answer, and writes a
CSV with an empty "quality_1_5" column for two reviewers to fill in.
It sleeps between calls to stay under the free-plan limit of 8,000 tokens/minute.
"""
import argparse
import csv
import json
import os
import time
from pathlib import Path


MODELS = ["openai/gpt-oss-120b", "openai/gpt-oss-20b"]
ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / "tests" / "fixtures" / "documents"
SYSTEM = ("Answer only with the information in the context. Answer in the language of the question. "
          "If the context does not contain the answer, say you could not find enough information. "
          "This is not legal advice.")

def doc_text(name: str, max_chars: int = 6000) -> str:
    p = DOCS / name
    if p.suffix == ".pdf":
        from pypdf import PdfReader
        t = "\n".join((pg.extract_text() or "") for pg in PdfReader(p).pages)
    elif p.suffix == ".docx":
        import docx
        t = "\n".join(par.text for par in docx.Document(p).paragraphs)
    else:
        raw = p.read_bytes()
        try:
            t = raw.decode("utf-8")
        except UnicodeDecodeError:
            t = raw.decode("cp1252")
    return t[:max_chars]

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="eval/reports/model_comparison.csv")
    a = ap.parse_args()
    from openai import OpenAI
    client = OpenAI(api_key=os.environ["GROQ_API_KEY"], base_url="https://api.groq.com/openai/v1")
    qs = [json.loads(line) for line in open(ROOT / "eval" / "seed_questions.jsonl", encoding="utf-8")]
    qs = [q for q in qs if q["answerable"] and "+" not in q["expected_document"] and "ocr" not in q["tags"]]
    pick = [q for q in qs if q["lang"] == "es"][:5] + [q for q in qs if q["lang"] == "en"][:5]
    out = Path(ROOT / a.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with open(out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["question_id", "model", "latency_s", "total_tokens", "answer", "expected_answer", "quality_1_5"])
        for q in pick:
            ctx = doc_text(q["expected_document"])
            for m in MODELS:
                t0 = time.perf_counter()
                r = client.chat.completions.create(model=m, temperature=0.1, max_tokens=600, messages=[
                    {"role": "system", "content": SYSTEM},
                    {"role": "user", "content": f"Context:\n{ctx}\n\nQuestion: {q['question']}"}])
                dt = time.perf_counter() - t0
                w.writerow([q["id"], m, f"{dt:.2f}", r.usage.total_tokens if r.usage else "", r.choices[0].message.content.strip(), q["expected_answer"], ""])
                print(q["id"], m, f"{dt:.2f}s")
                f.flush()
                time.sleep(20)  # stay under 8,000 TPM
    print("Saved", out)

if __name__ == "__main__":
    main()
