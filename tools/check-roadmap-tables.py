"""
Cek struktur Markdown di docs/roadmap.md.

Fokus:
  1. Code fence harus berpasangan (ganjil = ada yang tidak ditutup).
  2. Baris separator tabel harus punya kolom yang sama banyak dengan header.
  3. Tabel tidak boleh punya baris data dengan jumlah kolom berbeda.
"""

import re
import sys
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

DOC = Path(__file__).resolve().parent.parent / "docs" / "roadmap.md"


def split_row(line: str) -> list[str]:
    line = line.strip()
    if line.startswith("|"):
        line = line[1:]
    if line.endswith("|"):
        line = line[:-1]
    # Pipe di dalam backtick tidak boleh dihitung sebagai pemisah kolom.
    parts, buf, in_code = [], [], False
    for ch in line:
        if ch == "`":
            in_code = not in_code
        if ch == "|" and not in_code:
            parts.append("".join(buf).strip())
            buf = []
        else:
            buf.append(ch)
    parts.append("".join(buf).strip())
    return parts


def main() -> int:
    lines = DOC.read_text(encoding="utf-8").splitlines()

    errors: list[str] = []

    # 1. Code fence berpasangan.
    fence = 0
    for i, line in enumerate(lines, 1):
        if line.strip().startswith("```"):
            fence += 1
    if fence % 2 != 0:
        errors.append(f"Code fence ganjil ({fence}) -- ada fence yang tidak ditutup.")

    # 2 & 3. Konsistensi tabel.
    in_code = False
    i = 0
    tables = 0
    while i < len(lines):
        line = lines[i]
        if line.strip().startswith("```"):
            in_code = not in_code
            i += 1
            continue
        if in_code:
            i += 1
            continue

        if line.strip().startswith("|") and i + 1 < len(lines):
            sep = lines[i + 1].strip()
            # Baris separator tabel: |---|---|  (boleh ada : dan spasi)
            if re.fullmatch(r"\|[\s:\-|]+\|", sep):
                tables += 1
                header_cols = len(split_row(line))
                sep_cols = len(split_row(sep))
                if sep_cols != header_cols:
                    errors.append(
                        f"Baris {i + 2}: separator tabel punya {sep_cols} kolom, "
                        f"header punya {header_cols}."
                    )
                j = i + 2
                while j < len(lines) and lines[j].strip().startswith("|"):
                    cols = len(split_row(lines[j]))
                    if cols != header_cols:
                        errors.append(
                            f"Baris {j + 1}: baris tabel punya {cols} kolom, "
                            f"header punya {header_cols} -> {lines[j].strip()[:90]}"
                        )
                    j += 1
                i = j
                continue
        i += 1

    print(f"Tabel terdeteksi: {tables} | Code fence: {fence}")

    if errors:
        print(f"\nMASALAH ({len(errors)}):")
        for e in errors:
            print(f"  {e}")
        return 1

    print("\nStruktur Markdown bersih: fence berpasangan, kolom tabel konsisten.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
