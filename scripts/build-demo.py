#!/usr/bin/env python3
"""Copy the unchanged demo performance from the user's original archive.

Usage: python3 scripts/build-demo.py /path/to/original.mid
The source is intentionally kept outside the plugin repository. No MIDI events
are rewritten, clipped, or synthesized; the bundled file must match its hash.
"""

import hashlib
from pathlib import Path
import sys

SOURCE_SHA256 = "e693d173f1e509876f11bdb847cbecb291637ee9ce66b2abddaa7be30a727d6b"


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 scripts/build-demo.py /path/to/original.mid")
    data = Path(sys.argv[1]).read_bytes()
    if hashlib.sha256(data).hexdigest() != SOURCE_SHA256:
        raise SystemExit("Source does not match the archived The Man That Got Away MIDI.")
    output = Path(__file__).resolve().parents[1] / "public/assets/demo-the-man-that-got-away.mid"
    output.write_bytes(data)
    if hashlib.sha256(output.read_bytes()).hexdigest() != SOURCE_SHA256:
        raise SystemExit("Bundled demo does not match the original archive file.")
    print(f"Copied original {output.name}: {len(data)} bytes, SHA-256 {SOURCE_SHA256}")


if __name__ == "__main__":
    main()
