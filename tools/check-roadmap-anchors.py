"""
Cek anchor Markdown di docs/roadmap.md.

Meniru github-slugger (algoritma yang dipakai GitHub) supaya hasilnya
cocok dengan link yang benar-benar bekerja di GitHub.

Aturan github-slugger:
  1. Ambil teks heading (buang markup inline).
  2. Lowercase.
  3. Hapus semua karakter yang bukanhuruf/angka/spasi/hyphen (dalam unicode).
  4. Ganti spasi dengan '-'.
  5. Anchor duplikat diberi suffix -1, -2, ... (kepada hitungan global per dokumen).
"""

import re
import sys
from pathlib import Path

# Windows console default-nya cp1252; heading roadmap memakai emoji + VS16.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

DOC = Path(__file__).resolve().parent.parent / "docs" / "roadmap.md"


def slugify(text: str) -> str:
    """github-slugger: lowercase + buang karakter non [a-z0-9-] + spasi -> '-'."""
    text = text.strip().lower()
    # Buang karakter yang bukan alphanumeric, spasi, atau hyphen.
    text = re.sub(r"[^\w\- ]", "", text, flags=re.UNICODE)
    text = text.replace(" ", "-")
    return text


def strip_inline_markdown(text: str) -> str:
    """Buang markup inline yang dipakai di heading."""
    text = re.sub(r"`([^`]*)`", r"\1", text)          # inline code
    text = re.sub(r"\*\*([^*]*)\*\*", r"\1", text)    # bold
    text = re.sub(r"\*([^*]*)\*", r"\1", text)        # italic
    text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)  # link
    return text


def main() -> int:
    lines = DOC.read_text(encoding="utf-8").splitlines()

    headings: list[tuple[int, str, str]] = []   # (line_no, raw, slug)
    used: dict[str, int] = {}
    in_fence = False

    for idx, line in enumerate(lines, start=1):
        stripped = line.strip()
        if stripped.startswith("```"):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        m = re.match(r"^(#{1,6})\s+(.*)$", line)
        if not m:
            continue
        raw = m.group(2).rstrip()
        # Buang trailing '#' (closing sequence) ala GitHub.
        raw = re.sub(r"\s+#+\s*$", "", raw)
        plain = strip_inline_markdown(raw)
        base = slugify(plain)
        count = used.get(base, 0)
        used[base] = count + 1
        slug = base if count == 0 else f"{base}-{count}"
        headings.append((idx, raw, slug))

    anchors = {slug for _, _, slug in headings}

    # Kumpulkan semua link internal.
    link_re = re.compile(r"\]\(#([^)]+)\)")
    broken: list[tuple[int, str]] = []
    total = 0
    for idx, line in enumerate(lines, start=1):
        for target in link_re.findall(line):
            total += 1
            if target.lower() not in anchors:
                broken.append((idx, target))

    print(f"heading: {len(headings)} | anchor unik: {len(anchors)} | link internal: {total}")

    if broken:
        print(f"\nLINK MATI ({len(broken)}):")
        for idx, target in broken:
            print(f"  baris {idx}: #{target}")
        return 1

    print("\nSemua link internal resolve ke heading yang ada.")

    # Laporan heading yang tidak punya anchor (info saja).
    heading_lines = {idx for idx, _, _ in headings}
    print(f"\nBaris heading terdeteksi: {len(heading_lines)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
