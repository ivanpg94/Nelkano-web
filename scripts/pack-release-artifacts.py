#!/usr/bin/env python3
"""Transport large release installers in ordinary Git as 40 MiB parts."""
import argparse
import hashlib
import json
import os
from pathlib import Path

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("files", nargs="+", help="Installer filenames from manifest.json")
    parser.add_argument("--directory", type=Path, default=Path(__file__).resolve().parents[1] / "release-artifacts")
    args = parser.parse_args()
    directory = args.directory.resolve(strict=True)
    manifest = json.loads((directory / "manifest.json").read_text(encoding="utf-8-sig"))
    artifacts = []
    for name in args.files:
        if Path(name).name != name or "/" in name or "\\" in name or Path(name).suffix.lower() not in (".exe", ".msi", ".zip", ".apk"):
            raise ValueError("Expected an installer filename, without a path.")
        expected = manifest.get(name, "")
        if len(expected) != 64 or any(c not in "0123456789abcdef" for c in expected):
            raise ValueError("Missing manifest hash: " + name)
        source = directory / name
        if source.is_symlink() or not source.is_file():
            raise ValueError("Missing original installer: " + name)
        part_dir = directory / "parts" / expected
        part_dir.mkdir(parents=True, exist_ok=True)
        if not part_dir.resolve().is_relative_to(directory):
            raise ValueError("Release parts must remain under release-artifacts.")
        parts, total, whole = [], 0, hashlib.sha256()
        with source.open("rb") as stream:
            number = 0
            while block := stream.read(41943040):
                number += 1
                if number > 9999:
                    raise ValueError("Too many release parts.")
                path = part_dir / f"{number:04d}.part"
                temporary = path.with_suffix(".tmp")
                with temporary.open("wb") as target:
                    target.write(block)
                os.replace(temporary, path)
                whole.update(block)
                total += len(block)
                parts.append({"file": path.relative_to(directory).as_posix(), "bytes": len(block), "sha256": hashlib.sha256(block).hexdigest()})
        if total <= 0 or whole.hexdigest() != expected:
            raise ValueError("Original installer does not match manifest: " + name)
        artifacts.append({"file": name, "bytes": total, "sha256": expected, "parts": parts})
    index = directory / "parts.json"
    temporary = index.with_suffix(".json.tmp")
    temporary.write_text(json.dumps({"schemaVersion": 1, "artifacts": artifacts}, indent=2) + "\n", encoding="utf-8")
    os.replace(temporary, index)
    print(f"Packed {len(artifacts)} installers into {sum(len(a['parts']) for a in artifacts)} ordinary Git files.")

if __name__ == "__main__":
    main()
