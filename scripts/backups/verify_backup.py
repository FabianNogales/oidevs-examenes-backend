"""Offline recovery check; no Laravel, Railway, OAuth, or database connection is used."""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess

from backup_errors import Failure


def verify(dump_path, manifest_path):
    dump_path = Path(dump_path).resolve()
    manifest_path = Path(manifest_path).resolve()
    if manifest_path.stat().st_size > 8192:
        raise Failure("INVALID_MANIFEST")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    digest = hashlib.sha256()
    with dump_path.open("rb") as stream:
        while chunk := stream.read(1024 * 1024):
            digest.update(chunk)
    if (manifest.get("format") != "custom" or manifest.get("bytes") != dump_path.stat().st_size
            or manifest.get("sha256") != digest.hexdigest() or manifest.get("readback_sha256") != digest.hexdigest()):
        raise Failure("INTEGRITY_MISMATCH")
    # --list inspects archive structure; it never connects to/restores a database.
    result = subprocess.run(["pg_restore", "--list", str(dump_path)], capture_output=True, timeout=120, check=False)
    if result.returncode != 0:
        raise Failure("INVALID_ARCHIVE")
    return True


def main():
    parser = argparse.ArgumentParser(description="Offline SHA-256 and archive inspection; never restores a database.")
    parser.add_argument("--dump", required=True)
    parser.add_argument("--manifest", required=True)
    args = parser.parse_args()
    try:
        verify(args.dump, args.manifest)
        print("BACKUP_VERIFIED_OFFLINE")
        return 0
    except Exception as error:
        print(error.code if isinstance(error, Failure) else "INVALID_ARCHIVE")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
