import os, sys, subprocess, random, tempfile, shutil, json
sys.path.insert(0, os.path.dirname(__file__))
from content import DOCS, ORG
from reportlab.lib.pagesizes import letter
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, PageBreak
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from docx import Document
from docx.shared import Pt
from PIL import Image, ImageFilter
import numpy as np

OUT = os.path.join(os.path.dirname(__file__), "..", "..", "ai-service", "tests", "fixtures", "documents")
FONT = "/usr/share/fonts/truetype/dejavu/"
pdfmetrics.registerFont(TTFont("DV", FONT + "DejaVuSans.ttf"))
pdfmetrics.registerFont(TTFont("DVB", FONT + "DejaVuSans-Bold.ttf"))
ST = {
    "h1": ParagraphStyle("h1", fontName="DVB", fontSize=16, leading=20, spaceAfter=12, spaceBefore=4),
    "h2": ParagraphStyle("h2", fontName="DVB", fontSize=13, leading=17, spaceAfter=8),
    "p": ParagraphStyle("p", fontName="DV", fontSize=11, leading=15, spaceAfter=8),
    "li": ParagraphStyle("li", fontName="DV", fontSize=11, leading=15, spaceAfter=6, leftIndent=14),
}
FOOT = {"es": "Documento ficticio de prueba - {org} - Página {n} de {m}",
        "en": "Fictional test document - {org} - Page {n} of {m}"}

def make_pdf(d, path, scan_style=False):
    m = len(d["pages"])
    def footer(c, doc):
        c.saveState(); c.setFont("DV", 8)
        c.drawString(54, 30, FOOT[d["lang"]].format(org=ORG, n=doc.page, m=m))
        c.drawRightString(558, 30, f"v{d['version']}")
        c.restoreState()
    doc = SimpleDocTemplate(path, pagesize=letter, leftMargin=54, rightMargin=54, topMargin=60, bottomMargin=60,
                            title=d["title"], author=ORG)
    story = []
    for i, page in enumerate(d["pages"]):
        for style, text in page:
            st = ST[style]
            if scan_style:
                st = ParagraphStyle(st.name + "s", parent=st, fontSize=st.fontSize + 3, leading=st.leading + 5)
            story.append(Paragraph(text, st))
        if i < m - 1:
            story.append(PageBreak())
    doc.build(story, onFirstPage=footer, onLaterPages=footer)

def make_scan(d, path):
    tmp = tempfile.mkdtemp()
    src = os.path.join(tmp, "src.pdf"); make_pdf(d, src, scan_style=True)
    subprocess.run(["pdftoppm", "-r", "150", "-gray", "-png", src, os.path.join(tmp, "pg")], check=True)
    imgs = []
    rnd = random.Random(d["id"])
    for f in sorted(x for x in os.listdir(tmp) if x.endswith(".png")):
        im = Image.open(os.path.join(tmp, f)).convert("L")
        im = im.rotate(rnd.uniform(-1.2, 1.2), expand=False, fillcolor=255, resample=Image.BICUBIC)
        a = np.asarray(im).astype(np.int16)
        a = a - 18 + np.random.default_rng(rnd.randint(0, 999)).integers(-14, 15, a.shape)  # paper tone + noise
        im = Image.fromarray(np.clip(a, 0, 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(0.5))
        imgs.append(im.convert("RGB"))
    imgs[0].save(path, save_all=True, append_images=imgs[1:], resolution=150)
    shutil.rmtree(tmp)

def make_docx(d, path):
    doc = Document()
    doc.core_properties.title = d["title"]; doc.core_properties.author = ORG
    doc.add_heading(d["title"], level=0)
    doc.add_paragraph(f"{ORG}. Versión {d['version']}." if d["lang"] == "es" else f"{ORG}. Version {d['version']}.")
    for heading, paras in d["sections"]:
        doc.add_heading(heading, level=1)
        for p in paras:
            doc.add_paragraph(p)
    doc.add_paragraph("Documento ficticio de prueba." if d["lang"] == "es" else "Fictional test document.")
    doc.save(path)

os.makedirs(OUT, exist_ok=True)
for d in DOCS:
    p = os.path.join(OUT, d["file"])
    if d["kind"] == "pdf": make_pdf(d, p)
    elif d["kind"] == "scan": make_scan(d, p)
    elif d["kind"] == "docx": make_docx(d, p)
    elif d["kind"] == "txt":
        open(p, "w", encoding=d["encoding"], newline="\r\n" if d["encoding"] == "cp1252" else "\n").write(d["text"])

# ---- negative fixtures
inv = os.path.join(OUT, "invalid"); os.makedirs(inv, exist_ok=True)
base = os.path.join(OUT, "aviso_privacidad_integral.pdf")
subprocess.run(["qpdf", "--encrypt", "demo-user-pass", "demo-owner-pass", "256", "--", base, os.path.join(inv, "password_protected.pdf")], check=True)
data = open(base, "rb").read(); open(os.path.join(inv, "corrupted.pdf"), "wb").write(data[: len(data) // 3])
Image.new("RGB", (1275, 1650), (238, 238, 238)).save(os.path.join(inv, "blank_scan.pdf"), resolution=150)
open(os.path.join(inv, "fake_executable.pdf"), "wb").write(b"MZ" + b"\x00" * 62 + b"This is NOT a real program. Harmless test file that starts with the Windows executable signature MZ.\n")
open(os.path.join(inv, "empty.txt"), "wb").close()

# ---- manifest
man = [{k: d.get(k) for k in ("id", "file", "kind", "lang", "title", "category", "access", "version", "expiration", "note")} for d in DOCS]
json.dump(man, open(os.path.join(OUT, "manifest.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=2)
print("built", len(DOCS))
