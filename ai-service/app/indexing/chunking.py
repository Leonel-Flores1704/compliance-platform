"""Split extracted segments into token-bounded chunks (CMP-29, NFR-019, ADR-013).

Rules:
- A chunk never mixes two sections or two PDF pages (consecutive segments with the same
  section and page form a group), so every citation points to one exact page (FR-012).
- Split order inside a group: paragraph (line) -> sentence -> word -> token.
- Chunks hold at most ``chunk_size`` tokens; consecutive chunks of a group share
  about ``overlap`` tokens of trailing words.
- A last chunk under ``min_tokens`` is merged into the previous chunk of the same section.
- Each chunk keeps page_start/page_end (PDF) and section (DOCX/TXT).
"""

import re
from dataclasses import dataclass
from itertools import groupby

import tiktoken

from app.extraction import Segment

_SENTENCE_END = re.compile(r"(?<=[.!?;:])\s+")


@dataclass(frozen=True)
class Chunk:
    index: int
    content: str
    page_start: int | None
    page_end: int | None
    section: str | None
    token_count: int


@dataclass(frozen=True)
class _Atom:
    text: str
    page: int | None


class Chunker:
    def __init__(
        self,
        chunk_size: int = 500,
        overlap: int = 75,
        min_tokens: int = 30,
        encoding: str = "o200k_base",
    ) -> None:
        if not 0 <= overlap < chunk_size:
            raise ValueError("overlap must be >= 0 and smaller than chunk_size")
        self.chunk_size = chunk_size
        self.overlap = overlap
        self.min_tokens = min_tokens
        self._encoding = tiktoken.get_encoding(encoding)

    def count(self, text: str) -> int:
        return len(self._encoding.encode(text, disallowed_special=()))

    def chunk(self, segments: list[Segment]) -> list[Chunk]:
        chunks: list[Chunk] = []
        for (section, _page), group in groupby(segments, key=lambda segment: (segment.section, segment.page)):
            for atoms in self._pack(self._atoms(list(group))):
                content = "\n".join(atom.text for atom in atoms)
                pages = [atom.page for atom in atoms if atom.page is not None]
                chunks.append(
                    Chunk(
                        index=len(chunks),
                        content=content,
                        page_start=min(pages) if pages else None,
                        page_end=max(pages) if pages else None,
                        section=section,
                        token_count=self.count(content),
                    )
                )
        return chunks

    # --- Splitting ----------------------------------------------------------------

    def _atoms(self, segments: list[Segment]) -> list[_Atom]:
        atoms: list[_Atom] = []
        for segment in segments:
            for line in segment.text.split("\n"):
                line = line.strip()
                if line:
                    atoms.extend(_Atom(text, segment.page) for text in self._split(line))
        return atoms

    def _split(self, text: str) -> list[str]:
        """Split a paragraph into pieces of at most chunk_size tokens."""
        if self.count(text) <= self.chunk_size:
            return [text]
        pieces: list[str] = []
        for sentence in _SENTENCE_END.split(text):
            if self.count(sentence) <= self.chunk_size:
                pieces.append(sentence)
            else:
                pieces.extend(self._split_words(sentence))
        return pieces

    def _split_words(self, text: str) -> list[str]:
        pieces: list[str] = []
        current: list[str] = []
        for word in text.split():
            if self.count(word) > self.chunk_size:
                if current:
                    pieces.append(" ".join(current))
                    current = []
                pieces.extend(self._split_tokens(word))
                continue
            if current and self.count(" ".join([*current, word])) > self.chunk_size:
                pieces.append(" ".join(current))
                current = []
            current.append(word)
        if current:
            pieces.append(" ".join(current))
        return pieces

    def _split_tokens(self, word: str) -> list[str]:
        tokens = self._encoding.encode(word, disallowed_special=())
        return [
            self._encoding.decode(tokens[start : start + self.chunk_size])
            for start in range(0, len(tokens), self.chunk_size)
        ]

    # --- Packing ------------------------------------------------------------------

    def _tokens(self, atoms: list[_Atom]) -> int:
        return self.count("\n".join(atom.text for atom in atoms))

    def _pack(self, atoms: list[_Atom]) -> list[list[_Atom]]:
        """Greedily pack atoms into chunks. Returns (overlap + own) atoms per chunk."""
        packed: list[tuple[list[_Atom], list[_Atom]]] = []  # (overlap atoms, own atoms)
        overlap: list[_Atom] = []
        own: list[_Atom] = []

        for atom in atoms:
            if own and self._tokens([*overlap, *own, atom]) > self.chunk_size:
                packed.append((overlap, own))
                overlap = self._overlap_atoms([*overlap, *own])
                own = []
            own.append(atom)
            # The overlap is a courtesy: drop it if it does not fit with the new content.
            while overlap and self._tokens([*overlap, *own]) > self.chunk_size:
                overlap = overlap[1:] if len(overlap) > 1 else []
        if own:
            packed.append((overlap, own))

        if len(packed) > 1 and self._tokens(packed[-1][1]) < self.min_tokens:
            _, small_own = packed.pop()
            previous_overlap, previous_own = packed.pop()
            packed.append((previous_overlap, [*previous_own, *small_own]))

        return [[*overlap_atoms, *own_atoms] for overlap_atoms, own_atoms in packed]

    def _overlap_atoms(self, atoms: list[_Atom]) -> list[_Atom]:
        """Trailing words of a chunk, at most ``overlap`` tokens, keeping their pages."""
        if self.overlap == 0:
            return []
        groups: list[tuple[int | None, list[str]]] = []
        taken: list[str] = []
        for atom in reversed(atoms):
            words: list[str] = []
            for word in reversed(atom.text.split()):
                if self.count(" ".join([word, *taken])) > self.overlap:
                    break
                taken.insert(0, word)
                words.insert(0, word)
            else:
                if words:
                    groups.insert(0, (atom.page, words))
                continue
            if words:
                groups.insert(0, (atom.page, words))
            break
        return [_Atom(" ".join(words), page) for page, words in groups]
