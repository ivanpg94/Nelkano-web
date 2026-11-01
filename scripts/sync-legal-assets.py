#!/usr/bin/env python3
"""Atomically synchronize exact legal assets; retire only previously managed files."""
from pathlib import Path
import argparse
import hashlib
import json
import os
import re
import tempfile


def relative_path(value):
    if not isinstance(value, str) or (value != 'catalog.json' and not re.fullmatch(r'(?:notices|documents)/[A-Za-z0-9._-]+\.txt', value)):
        raise SystemExit('Invalid managed legal path: ' + str(value))
    return value


def destination_path(root, relative):
    path = root / relative_path(relative)
    if not path.resolve().is_relative_to(root) or path.is_symlink() or path.parent.is_symlink():
        raise SystemExit('Unsafe legal destination: ' + relative)
    if path.exists() and not path.is_file():
        raise SystemExit('Legal destination is not a regular file: ' + relative)
    return path


def atomic_write(destination, payload):
    destination.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix='.legal-sync-', suffix='.tmp', dir=destination.parent)
    temporary = Path(temporary)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            output.write(payload)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, destination)
    finally:
        if temporary.exists():
            temporary.unlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-root', type=Path, required=True, help='Nelkano src/nelkano/legal directory')
    args = parser.parse_args()
    source = args.source_root.resolve(strict=True)
    target = Path(__file__).resolve().parents[1] / 'public_html/modules/custom/nelkano_home/legal'
    if target.is_symlink():
        raise SystemExit('The legal asset directory must not be a symlink')
    repository = Path(__file__).resolve().parents[1]
    target = target.resolve()
    if not target.is_relative_to(repository):
        raise SystemExit('Legal asset directory escapes the repository')
    catalog_source = source / 'catalog.json'
    if not catalog_source.is_file() or not catalog_source.resolve().is_relative_to(source):
        raise SystemExit('Missing or unsafe source catalog')
    catalog_bytes = catalog_source.read_bytes()
    catalog = json.loads(catalog_bytes.decode('utf-8'))
    paths = {'catalog.json'}
    for entry in catalog.get('entries', []) + catalog.get('documents', []):
        for key in ('noticePath', 'noticePathEs', 'noticePathEn'):
            path = entry.get(key)
            if not path:
                continue
            if not isinstance(path, str) or not re.fullmatch(r'legal/(?:notices|documents)/[A-Za-z0-9._-]+\.txt', path):
                raise SystemExit('Invalid catalog legal path: ' + str(path))
            paths.add(relative_path(path.removeprefix('legal/')))

    previous = {}
    previous_manifest = target / 'sync-manifest.json'
    if previous_manifest.is_symlink():
        raise SystemExit('The previous manifest must not be a symlink')
    if previous_manifest.exists():
        managed = json.loads(previous_manifest.read_text(encoding='utf-8'))
        if managed.get('schemaVersion') != 1 or not isinstance(managed.get('files'), dict):
            raise SystemExit('Invalid previous legal sync manifest')
        previous = managed['files']
        for relative, digest in previous.items():
            relative_path(relative)
            if not isinstance(digest, str) or not re.fullmatch(r'[a-f0-9]{64}', digest):
                raise SystemExit('Invalid previous legal SHA-256: ' + relative)

    # Validate every input and destination before any write or deletion.
    payloads = {}
    for relative in sorted(paths):
        original = source / relative
        if not original.is_file() or not original.resolve().is_relative_to(source):
            raise SystemExit('Missing or unsafe legal source: ' + relative)
        destination_path(target, relative)
        payloads[relative] = catalog_bytes if relative == 'catalog.json' else original.read_bytes()
    stale = sorted(set(previous) - paths)
    for relative in stale:
        path = destination_path(target, relative)
        if path.exists() and hashlib.sha256(path.read_bytes()).hexdigest() != previous[relative]:
            raise SystemExit('Previously managed file was edited; refusing to remove: ' + relative)

    # Publish referenced documents before the catalog that points at them.
    for relative in sorted(paths - {'catalog.json'}):
        atomic_write(destination_path(target, relative), payloads[relative])
    atomic_write(destination_path(target, 'catalog.json'), catalog_bytes)
    removed = []
    for relative in stale:
        path = destination_path(target, relative)
        if path.exists():
            path.unlink()  # exact previous-manifest member, never a directory scan
            removed.append(relative)
    manifest = {'schemaVersion': 1, 'files': {relative: hashlib.sha256(payloads[relative]).hexdigest() for relative in sorted(paths)}}
    atomic_write(previous_manifest, (json.dumps(manifest, indent=2) + '\n').encode('utf-8'))
    print(json.dumps({'synchronizedFiles': len(paths), 'catalogEntries': len(catalog.get('entries', [])),
                      'catalogDocuments': len(catalog.get('documents', [])), 'catalogSha256': manifest['files']['catalog.json'],
                      'removedManagedFiles': removed, 'unmanagedFilesPreserved': True, 'atomicFileReplacement': True}))


if __name__ == '__main__':
    main()
