"""Offline contract fixture only. No environment credentials or network calls."""

import base64
import contextlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from unittest.mock import patch


def emit(source_directory):
    sys.path.insert(0, str(Path(source_directory).resolve()))
    import executor

    parameters = json.load(sys.stdin)
    secret = parameters["secret"]
    if not isinstance(secret, str) or not secret.startswith("fixture-"):
        raise ValueError("Only synthetic fixture secrets are allowed")
    environment = {"BACKUP_CALLBACK_URL": "https://example.invalid" + executor.CALLBACK_PATH,
                   "BACKUP_CALLBACK_SECRET": secret}
    with patch.dict(os.environ, environment, clear=True), \
            patch("executor.time.time", return_value=parameters["timestamp"]), \
            patch("executor.uuid.uuid4", return_value=parameters["nonce"]), \
            patch("executor.urllib.request.build_opener") as opener, \
            contextlib.redirect_stdout(io.StringIO()):
        opener.return_value.open.return_value.__enter__.return_value.status = 200
        executor.Callback(parameters["backup_id"], parameters["external_run_id"]).send(
            parameters["status"], **parameters.get("evidence", {}))
        request = opener.return_value.open.call_args.args[0]
    # Private subprocess IPC with synthetic data; never called by the workflow.
    sys.stdout.write(json.dumps({
        "body_base64": base64.b64encode(request.data).decode("ascii"),
        "headers": {name.lower(): value for name, value in request.header_items()},
    }))


def main():
    git_ref = os.environ.get("EIDA_TEST_BACKUP_EXECUTOR_REF")
    if not git_ref:
        return emit(sys.argv[1])
    if not re.fullmatch(r"[0-9a-f]{40}|origin/backup-execution", git_ref):
        raise ValueError("Expected an immutable local Git object or the execution ref")
    # Local Git object reads only. Do not fetch, checkout or execute the branch workflow.
    with tempfile.TemporaryDirectory(prefix="eida-callback-contract-") as temporary:
        for name in ("executor.py", "drive_storage.py", "backup_errors.py"):
            result = subprocess.run(["git", "show", f"{git_ref}:scripts/backups/{name}"],
                                    capture_output=True, check=False)
            if result.returncode != 0:
                raise RuntimeError("Local contract source is unavailable")
            (Path(temporary) / name).write_bytes(result.stdout)
        emit(temporary)


if __name__ == "__main__":
    main()
