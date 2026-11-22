"""Portable executor: Python + Docker + private Drive, independent of GitHub's event format.

Only safe error codes reach stdout/callback. No exception text or subprocess output
is printed. Run only in an authorized environment; not from a Laravel request.
"""

import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import signal
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

from backup_errors import Failure
from drive_storage import upload

CALLBACK_PATH = "/api/v1/backup-executor/callback"

CALLBACK_CODES = frozenset({
    "EXECUTOR_UNAUTHENTICATED", "BACKUPS_DISABLED", "INVALID_BACKUP_TRANSITION", "CALLBACK_REPLAY",
    "STORAGE_UNAVAILABLE", "STORAGE_NOT_CONFIGURED", "DRIVE_RATE_LIMITED", "FILE_MISSING",
    "INTEGRITY_MISMATCH", "DRIVE_PERMISSION_DENIED", "OAUTH_REVOKED",
})
CALLBACK_FIELDS = frozenset({
    "backup_id", "external_run_id", "status", "storage_provider", "file_id", "manifest_id",
    "parent_id", "bytes", "sha256", "storage_version", "md5_checksum", "integrity_method",
    "postgres_version", "error_code",
})


def callback_diagnostic(phase, attempt, http_status=None, response=None, transport_error=None):
    """Only fixed codes/field names and numbers reach logs, never provider text."""
    categories = {401: "authentication_rejected", 403: "authorization_rejected",
                  404: "callback_not_found", 409: "state_conflict", 413: "payload_too_large",
                  419: "csrf_rejected", 422: "payload_invalid", 429: "rate_limited"}
    category = categories.get(http_status, "unexpected_http_response")
    if http_status is not None and 300 <= http_status < 400:
        category = "redirect_rejected"
    elif http_status is not None and http_status >= 500:
        category = "server_unavailable"
    if transport_error:
        category = "transport_failure"
    code, fields = None, []
    if response is not None:
        try:
            raw = response.read(4097)
            data = json.loads(raw) if len(raw) <= 4096 else None
            if isinstance(data, dict):
                candidate = data.get("code")
                code = candidate if isinstance(candidate, str) and candidate in CALLBACK_CODES else None
                errors = data.get("errors")
                if isinstance(errors, dict):
                    fields = sorted(field for field in errors if field in CALLBACK_FIELDS)
        except (OSError, ValueError, TypeError):
            pass
    states = {"RUNNING": "claim_pending", "UPLOADING": "running",
              "AVAILABLE": "uploading", "FAILED": "terminating"}
    print(json.dumps({
        "event": "BACKUP_CALLBACK_DIAGNOSTIC",
        "phase": phase if phase in states else "UNKNOWN",
        "attempt": attempt,
        "http_status": http_status,
        "category": category,
        "response_code": code,
        "validation_fields": fields,
        "execution_state": states.get(phase, "unknown"),
        "transport_error": transport_error,
    }, separators=(",", ":")), flush=True)


def callback_transport_error(error):
    reason = getattr(error, "reason", error)
    if isinstance(reason, (TimeoutError, socket.timeout)):
        return "timeout"
    if isinstance(reason, ssl.SSLError):
        return "tls"
    if isinstance(reason, socket.gaierror):
        return "dns"
    if isinstance(reason, ConnectionError):
        return "connection"
    return "transport_error"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def required(name):
    value = os.environ.get(name, "")
    if not value:
        raise Failure("CONFIGURATION_INVALID")
    return value


def https_url(value, path=None):
    parsed = urllib.parse.urlsplit(value)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username
            or parsed.password or parsed.query or parsed.fragment
            or (path and parsed.path != path)):
        raise Failure("CONFIGURATION_INVALID")
    return value


class Callback:
    def __init__(self, backup_id, run_id):
        self.backup_id = backup_id
        self.run_id = run_id
        self.url = https_url(required("BACKUP_CALLBACK_URL"), CALLBACK_PATH)
        self.secret = required("BACKUP_CALLBACK_SECRET")
        if len(self.secret) < 32:
            raise Failure("CONFIGURATION_INVALID")

    def send(self, status, **evidence):
        body = json.dumps({"backup_id": self.backup_id, "external_run_id": self.run_id,
                           "status": status, **evidence}, separators=(",", ":")).encode()
        nonce = str(uuid.uuid4())
        for attempt in range(4):
            timestamp = str(int(time.time()))
            canonical = (timestamp + "\n" + nonce + "\nPOST\n" + CALLBACK_PATH + "\n").encode() + body
            signature = hmac.new(self.secret.encode(), canonical, hashlib.sha256).hexdigest()
            request = urllib.request.Request(self.url, data=body, method="POST", headers={
                "Content-Type": "application/json", "Accept": "application/json",
                "X-Backup-Timestamp": timestamp, "X-Backup-Nonce": nonce,
                "X-Backup-Signature": signature,
            })
            try:
                with urllib.request.build_opener(NoRedirect).open(request, timeout=20) as response:
                    if response.status == 200:
                        return
                    callback_diagnostic(status, attempt + 1, response.status)
            except urllib.error.HTTPError as error:
                try:
                    callback_diagnostic(status, attempt + 1, error.code, error)
                finally:
                    error.close()
                if error.code < 500 and error.code != 429:
                    raise Failure("CALLBACK_REJECTED") from None
            except (OSError, urllib.error.URLError) as error:
                callback_diagnostic(status, attempt + 1, transport_error=callback_transport_error(error))
            if attempt < 3:
                time.sleep(2 ** attempt)
        raise Failure("CALLBACK_UNREACHABLE")


def command(arguments, code, timeout=120):
    try:
        try:
            result = subprocess.run(arguments, check=False, capture_output=True, timeout=timeout)
        except (OSError, subprocess.TimeoutExpired):
            raise Failure(code) from None
        if result.returncode != 0:
            raise Failure(code)
        return result.stdout.decode("utf-8", errors="strict").strip()
    finally:
        # Killing the Docker CLI does not necessarily stop its container (especially
        # on a future laboratory host). Stop it before deleting the mounted directory.
        if "--cidfile" in arguments:
            cidfile = Path(arguments[arguments.index("--cidfile") + 1])
            if cidfile.is_file():
                container_id = cidfile.read_text().strip()
                if re.fullmatch(r"[a-f0-9]{64}", container_id):
                    try:
                        subprocess.run(["docker", "rm", "--force", container_id],
                                       capture_output=True, timeout=15, check=False)
                    except (OSError, subprocess.TimeoutExpired):
                        pass


def pg_command(image, directory, program, arguments):
    # Argument arrays only. PG credentials are inherited env, never shell/dispatch/argv values.
    args = ["docker", "run", "--rm", "--network", "host", "--user", f"{os.getuid()}:{os.getgid()}",
            "--cidfile", str(directory / (str(uuid.uuid4()) + ".cid"))]
    for name in ("PGHOST", "PGPORT", "PGDATABASE", "PGUSER", "PGPASSWORD", "PGSSLMODE", "PGCONNECT_TIMEOUT"):
        args += ["--env", name]
    if os.environ.get("BACKUP_PG_CA_PEM"):
        args += ["--env", "PGSSLROOTCERT=/backup/root.crt"]
    return args + ["--mount", f"type=bind,source={directory},target=/backup", image, program] + arguments


def archive(directory, backup_id):
    for name in ("PGHOST", "PGDATABASE", "PGUSER", "PGPASSWORD"):
        required(name)
    if (not re.fullmatch(r"[a-zA-Z0-9.-]+", os.environ["PGHOST"])
            or not re.fullmatch(r"[a-zA-Z0-9_-]+", os.environ["PGDATABASE"])
            or not re.fullmatch(r"[0-9]{1,5}", os.environ.get("PGPORT", ""))):
        raise Failure("CONFIGURATION_INVALID")
    os.environ["PGSSLMODE"] = os.environ.get("PGSSLMODE") or "require"
    os.environ["PGCONNECT_TIMEOUT"] = "15"
    if os.environ["PGSSLMODE"] not in ("require", "verify-full"):
        raise Failure("TLS_REQUIRED")
    if os.environ.get("BACKUP_PG_CA_PEM"):
        (directory / "root.crt").write_text(os.environ["BACKUP_PG_CA_PEM"])

    # psql's protocol works across server versions. It is used only for discovery.
    query = ("SELECT current_setting('server_version_num'), current_setting('server_version'), ssl "
             "FROM pg_stat_ssl WHERE pid = pg_backend_pid()")
    discovered = command(pg_command("postgres:17-bookworm", directory, "psql",
                         ["-X", "-A", "-t", "-v", "ON_ERROR_STOP=1", "-c", query]), "TLS_REQUIRED")
    parts = discovered.split("|")
    if len(parts) != 3 or parts[2] != "t" or not parts[0].isdigit():
        raise Failure("TLS_REQUIRED")
    major = int(parts[0]) // 10000
    if not 14 <= major <= 19 or not re.fullmatch(r"[0-9][0-9A-Za-z .()+_-]{0,99}", parts[1]):
        raise Failure("PG_VERSION_UNSUPPORTED")
    image = f"postgres:{major}-bookworm"
    version = command(pg_command(image, directory, "pg_dump", ["--version"]), "PG_VERSION_UNSUPPORTED")
    match = re.search(r"\(PostgreSQL\) (\d+)\.", version)
    if not match or int(match[1]) != major:
        raise Failure("PG_VERSION_UNSUPPORTED")
    filename = f"eida-{backup_id}.dump"
    # Full selected database: no table/schema/data exclusions, no owner stripping.
    command(pg_command(image, directory, "pg_dump", ["--format=custom", "--no-password", "--lock-wait-timeout=30s",
            "--file", f"/backup/{filename}"]), "DUMP_FAILED", timeout=900)
    command(pg_command(image, directory, "pg_restore", ["--list", f"/backup/{filename}"]), "INVALID_ARCHIVE")
    return directory / filename, parts[1]


def cancelled(_signal, _frame):
    raise Failure("EXECUTOR_CANCELLED")


def main():
    callback = None
    claimed = False
    try:
        backup_id = required("BACKUP_ID")
        run_id = required("BACKUP_RUN_ID")
        if str(uuid.UUID(backup_id)) != backup_id or not re.fullmatch(r"[0-9]{1,30}\.[0-9]{1,10}", run_id):
            raise Failure("CONFIGURATION_INVALID")
        callback = Callback(backup_id, run_id)
        callback.send("RUNNING")  # Claims UUID before any DB connection/dump; reruns are denied.
        claimed = True
        signal.signal(signal.SIGTERM, cancelled)
        signal.signal(signal.SIGINT, cancelled)
        with tempfile.TemporaryDirectory(prefix="eida-backup-") as temporary:
            directory = Path(temporary)
            file, version = archive(directory, backup_id)
            callback.send("UPLOADING")
            evidence = upload(file, backup_id, run_id, version)
            callback.send("AVAILABLE", **evidence)
        print("BACKUP_VERIFIED")
        return 0
    except Exception as error:
        code = error.code if isinstance(error, Failure) else "EXECUTOR_FAILED"
        if callback and claimed and code not in ("CALLBACK_REJECTED", "CALLBACK_UNREACHABLE"):
            try:
                callback.send("FAILED", error_code=code)
            except Exception:
                pass  # Persisted deadline reconciliation is authoritative after hard cancellation.
        print(code)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
