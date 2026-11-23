"""Google Drive v3, offline OAuth, fixed IDs and resumable upload. Standard library only."""

import hashlib
import http.client
import io
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request

from backup_errors import Failure

API = "https://www.googleapis.com/drive/v3/files"
UPLOAD = "https://www.googleapis.com/upload/drive/v3/files"
FIELDS = ("id,name,mimeType,size,trashed,parents,shared,ownedByMe,isAppAuthorized,version,"
          "md5Checksum,sha256Checksum,appProperties,capabilities(canDownload)")
CHUNK_BYTES = 8 * 1024 * 1024  # Multiple of Google's 256 KiB granularity, not an archive-size limit.
ID = re.compile(r"[A-Za-z0-9_-]{1,200}")

INTEGRITY_STAGES = frozenset({
    "metadata", "media", "resumable", "dump_upload", "manifest_upload",
    "file_identity", "dump_identity", "manifest_identity", "dump_metadata",
    "dump_readback", "manifest_metadata", "manifest_readback", "dump_final_metadata",
})
INTEGRITY_CHECKS = frozenset({
    "INVALID_FILE_IDENTIFIER", "FILE_IDENTIFIER_MISMATCH", "NAME_MISMATCH", "PARENT_MISMATCH",
    "BACKUP_BINDING_MISMATCH", "RUN_BINDING_MISMATCH", "KIND_MISMATCH", "DOWNLOAD_NOT_ALLOWED",
    "MIME_TYPE_MISMATCH", "SIZE_MISMATCH", "SHA256_MISMATCH", "MD5_MISMATCH",
    "VERSION_INVALID", "SIZE_EXCEEDED", "MANIFEST_CONTENT_MISMATCH", "VERSION_CHANGED",
})


def integrity_failure(stage, check):
    # One event per failure. No IDs, values, checksums, credentials or provider text.
    print(json.dumps({
        "event": "BACKUP_DRIVE_DIAGNOSTIC",
        "stage": stage if stage in INTEGRITY_STAGES else "metadata",
        "check": check if check in INTEGRITY_CHECKS else "FILE_IDENTIFIER_MISMATCH",
    }, separators=(",", ":")), flush=True)
    raise Failure("INTEGRITY_MISMATCH")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def required(name):
    value = os.environ.get(name, "")
    if not value:
        raise Failure("CONFIGURATION_INVALID")
    return value


def parse_json(body):
    try:
        value = json.loads(body)
        if isinstance(value, dict):
            return value
    except (ValueError, UnicodeError):
        pass
    raise Failure("STORAGE_UNAVAILABLE")


class DriveClient:
    def __init__(self, require_folder=True):
        self.client_id = required("BACKUP_DRIVE_CLIENT_ID")
        self.client_secret = required("BACKUP_DRIVE_CLIENT_SECRET")
        self.refresh_token = required("BACKUP_DRIVE_REFRESH_TOKEN")
        self.folder_id = required("BACKUP_DRIVE_FOLDER_ID") if require_folder else os.environ.get("BACKUP_DRIVE_FOLDER_ID", "")
        if require_folder and not ID.fullmatch(self.folder_id):
            raise Failure("CONFIGURATION_INVALID")
        self.access_token = None
        self.expires_at = 0
        self.opener = urllib.request.build_opener(NoRedirect)

    def token(self):
        if self.access_token and self.expires_at > time.time() + 60:
            return self.access_token
        body = urllib.parse.urlencode({"client_id": self.client_id, "client_secret": self.client_secret,
                                      "refresh_token": self.refresh_token, "grant_type": "refresh_token"}).encode()
        request = urllib.request.Request("https://oauth2.googleapis.com/token", data=body,
                                         headers={"Content-Type": "application/x-www-form-urlencoded"})
        try:
            with self.opener.open(request, timeout=30) as response:
                value = parse_json(response.read(65537))
        except urllib.error.HTTPError as error:
            try:
                value = json.loads(error.read(65536))
            except (ValueError, OSError):
                value = {}
            error.close()
            raise Failure("OAUTH_REVOKED" if value.get("error") == "invalid_grant" else "STORAGE_UNAVAILABLE") from None
        except (OSError, urllib.error.URLError, http.client.HTTPException):
            raise Failure("STORAGE_UNAVAILABLE") from None
        if not isinstance(value.get("access_token"), str) or not value["access_token"]:
            raise Failure("OAUTH_REVOKED")
        if "scope" in value and "https://www.googleapis.com/auth/drive.file" not in value["scope"].split():
            raise Failure("DRIVE_PERMISSION_DENIED")
        self.access_token = value["access_token"]
        self.expires_at = time.time() + int(value.get("expires_in", 0))
        return self.access_token

    def request(self, method, url, body=None, headers=None, expected=(200,), retry=False, stream=False):
        parsed = urllib.parse.urlsplit(url)
        if (parsed.scheme != "https" or parsed.netloc != "www.googleapis.com" or parsed.fragment
                or not (parsed.path.startswith("/drive/v3/files") or parsed.path == "/upload/drive/v3/files")):
            raise Failure("CONFIGURATION_INVALID")
        for attempt in range(4 if retry else 2):
            request = urllib.request.Request(url, data=body, method=method,
                                             headers={"Authorization": "Bearer " + self.token(), **(headers or {})})
            try:
                response = self.opener.open(request, timeout=90)
            except urllib.error.HTTPError as error:
                response = error  # A resumable 308 is a protocol response, never a redirect.
            except (OSError, urllib.error.URLError, http.client.HTTPException):
                if retry and attempt < 3:
                    time.sleep(2 ** attempt)
                    continue
                raise Failure("STORAGE_UNAVAILABLE") from None
            if response.status in expected:
                if stream:
                    return response
                try:
                    data = response.read(65537)
                    if len(data) > 65536:
                        raise Failure("STORAGE_UNAVAILABLE")
                    return response.status, response.headers, data
                finally:
                    response.close()
            status = response.status
            try:
                value = json.loads(response.read(65536))
                reason = value.get("error", {}).get("errors", [{}])[0].get("reason", "")
            except (ValueError, IndexError, AttributeError, OSError):
                reason = ""
            finally:
                response.close()
            if status == 401 and attempt == 0:
                self.access_token = None
                continue
            if status == 429 or reason in ("rateLimitExceeded", "userRateLimitExceeded"):
                code = "DRIVE_RATE_LIMITED"
            elif reason in ("storageQuotaExceeded", "dailyLimitExceeded", "downloadQuotaExceeded"):
                code = "DRIVE_QUOTA_EXCEEDED"
            elif status == 401:
                code = "OAUTH_REVOKED"
            elif status == 403:
                code = "DRIVE_PERMISSION_DENIED"
            elif status in (404, 410):
                code = "FILE_MISSING"
            elif status == 409:
                code = "DUPLICATE_FILE"
            else:
                code = "STORAGE_UNAVAILABLE"
            if retry and code in ("DRIVE_RATE_LIMITED", "STORAGE_UNAVAILABLE") and attempt < 3:
                time.sleep(2 ** attempt)
                continue
            raise Failure(code)
        raise Failure("STORAGE_UNAVAILABLE")

    def metadata(self, file_id):
        if not ID.fullmatch(file_id):
            integrity_failure("metadata", "INVALID_FILE_IDENTIFIER")
        value = parse_json(self.request("GET", API + "/" + file_id + "?" + urllib.parse.urlencode({"fields": FIELDS}), retry=True)[2])
        if value.get("id") != file_id:
            integrity_failure("metadata", "FILE_IDENTIFIER_MISMATCH")
        return value

    def media(self, file_id):
        if not ID.fullmatch(file_id):
            integrity_failure("media", "INVALID_FILE_IDENTIFIER")
        return self.request("GET", API + "/" + file_id + "?alt=media", retry=True, stream=True)

    def generated_ids(self, count):
        result = parse_json(self.request("GET", API + "/generateIds?" + urllib.parse.urlencode({
            "count": count, "space": "drive", "type": "files"}), retry=True)[2])
        ids = result.get("ids", [])
        if len(ids) != count or len(set(ids)) != count or not all(isinstance(value, str) and ID.fullmatch(value) for value in ids):
            raise Failure("STORAGE_UNAVAILABLE")
        return ids

    def assert_private(self, metadata, name=None, backup_id=None, run_id=None, kind=None):
        if metadata.get("trashed") is not False:
            raise Failure("FILE_MISSING")
        if metadata.get("shared") is not False or metadata.get("ownedByMe") is not True or metadata.get("isAppAuthorized") is not True:
            raise Failure("DRIVE_PERMISSION_DENIED")
        if name is not None:
            props = metadata.get("appProperties", {})
            stage = {"dump": "dump_identity", "manifest": "manifest_identity"}.get(kind, "file_identity")
            if metadata.get("name") != name:
                integrity_failure(stage, "NAME_MISMATCH")
            if metadata.get("parents") != [self.folder_id]:
                integrity_failure(stage, "PARENT_MISMATCH")
            if props.get("backup_id") != backup_id:
                integrity_failure(stage, "BACKUP_BINDING_MISMATCH")
            if props.get("run_id") != run_id:
                integrity_failure(stage, "RUN_BINDING_MISMATCH")
            if props.get("kind") != kind:
                integrity_failure(stage, "KIND_MISMATCH")
            if metadata.get("capabilities", {}).get("canDownload") is not True:
                integrity_failure(stage, "DOWNLOAD_NOT_ALLOWED")

    def check_folder(self):
        folder = self.metadata(self.folder_id)
        self.assert_private(folder)
        if folder.get("mimeType") != "application/vnd.google-apps.folder" or folder.get("appProperties", {}).get("eida_backup_root") != "1":
            raise Failure("DRIVE_PERMISSION_DENIED")

    def require_no_backup(self, backup_id):
        # The UUID comes from the authenticated domain claim; IDs and query are not shell input.
        query = f"'{self.folder_id}' in parents and trashed = false and appProperties has {{ key='backup_id' and value='{backup_id}' }}"
        result = parse_json(self.request("GET", API + "?" + urllib.parse.urlencode({"q": query, "fields": "files(id)", "pageSize": 1}), retry=True)[2])
        if result.get("files"):
            raise Failure("DUPLICATE_FILE")

    def resumable(self, file_id, name, mime, stream, size, backup_id, run_id, kind):
        metadata = {"id": file_id, "name": name, "mimeType": mime, "parents": [self.folder_id],
                    "appProperties": {"backup_id": backup_id, "run_id": run_id, "kind": kind}}
        _, headers, _ = self.request("POST", UPLOAD + "?uploadType=resumable&fields=id",
                                     json.dumps(metadata).encode(), {"Content-Type": "application/json; charset=UTF-8",
                                     "X-Upload-Content-Type": mime, "X-Upload-Content-Length": str(size)})
        location = headers.get("Location", "")
        parsed = urllib.parse.urlsplit(location)
        if parsed.scheme != "https" or parsed.netloc != "www.googleapis.com" or parsed.path != "/upload/drive/v3/files":
            raise Failure("UPLOAD_INCOMPLETE")
        offset = 0
        stalls = 0
        while offset < size:
            stream.seek(offset)
            chunk = stream.read(min(CHUNK_BYTES, size - offset))
            if not chunk:
                raise Failure("UPLOAD_INCOMPLETE")
            old_offset = offset
            try:
                status, headers, data = self.request("PUT", location, chunk, {
                    "Content-Type": mime, "Content-Length": str(len(chunk)),
                    "Content-Range": f"bytes {offset}-{offset + len(chunk) - 1}/{size}"}, expected=(200, 201, 308))
            except Failure as failure:
                if failure.code not in ("STORAGE_UNAVAILABLE", "DRIVE_RATE_LIMITED"):
                    raise
                # An accepted chunk can have a lost response. Probe THIS session; never create a new ID.
                status, headers, data = self.request("PUT", location, b"", {
                    "Content-Length": "0", "Content-Range": f"bytes */{size}"}, expected=(200, 201, 308), retry=True)
            if status in (200, 201):
                if parse_json(data).get("id") != file_id:
                    stage = {"dump": "dump_upload", "manifest": "manifest_upload"}.get(kind, "resumable")
                    integrity_failure(stage, "FILE_IDENTIFIER_MISMATCH")
                return file_id
            acknowledged = headers.get("Range")
            if acknowledged is None:
                offset = 0
            else:
                match = re.fullmatch(r"bytes=0-([0-9]+)", acknowledged)
                if not match:
                    raise Failure("UPLOAD_INCOMPLETE")
                offset = int(match[1]) + 1
            if offset < old_offset or offset > old_offset + len(chunk) or offset >= size:
                raise Failure("UPLOAD_INCOMPLETE")
            stalls = stalls + 1 if offset == old_offset else 0
            if stalls >= 4:
                raise Failure("UPLOAD_INCOMPLETE")
            if stalls:
                time.sleep(2 ** (stalls - 1))
        raise Failure("UPLOAD_INCOMPLETE")


def upload(file, backup_id, run_id, postgres_version):
    client = DriveClient()
    client.check_folder()
    client.require_no_backup(backup_id)
    sha256 = hashlib.sha256()
    md5 = hashlib.md5(usedforsecurity=False)
    size = file.stat().st_size
    if size < 1:
        raise Failure("INVALID_ARCHIVE")
    with file.open("rb") as stream:
        while chunk := stream.read(1024 * 1024):
            sha256.update(chunk)
            md5.update(chunk)
    digest = sha256.hexdigest()
    file_id, manifest_id = client.generated_ids(2)
    with file.open("rb") as stream:
        client.resumable(file_id, file.name, "application/octet-stream", stream, size, backup_id, run_id, "dump")
    metadata = client.metadata(file_id)
    client.assert_private(metadata, file.name, backup_id, run_id, "dump")
    if metadata.get("mimeType") != "application/octet-stream":
        integrity_failure("dump_metadata", "MIME_TYPE_MISMATCH")
    if metadata.get("size") != str(size):
        integrity_failure("dump_metadata", "SIZE_MISMATCH")
    if "sha256Checksum" in metadata and metadata["sha256Checksum"] != digest:
        integrity_failure("dump_metadata", "SHA256_MISMATCH")
    if "md5Checksum" in metadata and metadata["md5Checksum"] != md5.hexdigest():
        integrity_failure("dump_metadata", "MD5_MISMATCH")
    if not re.fullmatch(r"[0-9]{1,100}", metadata.get("version", "")):
        integrity_failure("dump_metadata", "VERSION_INVALID")
    remote_sha = hashlib.sha256()
    remote_size = 0
    try:
        with client.media(file_id) as response:
            while chunk := response.read(1024 * 1024):
                remote_sha.update(chunk)
                remote_size += len(chunk)
                if remote_size > size:
                    integrity_failure("dump_readback", "SIZE_EXCEEDED")
    except (OSError, http.client.HTTPException):
        raise Failure("STORAGE_UNAVAILABLE") from None
    if remote_size != size:
        integrity_failure("dump_readback", "SIZE_MISMATCH")
    if remote_sha.hexdigest() != digest:
        integrity_failure("dump_readback", "SHA256_MISMATCH")
    # Drive versions include metadata changes. Bind the manifest to the last
    # observed version only after rechecking the content of a changed version.
    current = client.metadata(file_id)
    client.assert_private(current, file.name, backup_id, run_id, "dump")
    if current.get("version") != metadata["version"]:
        if current.get("mimeType") != "application/octet-stream":
            integrity_failure("dump_final_metadata", "MIME_TYPE_MISMATCH")
        if current.get("size") != str(size):
            integrity_failure("dump_final_metadata", "SIZE_MISMATCH")
        if "sha256Checksum" in current and current["sha256Checksum"] != digest:
            integrity_failure("dump_final_metadata", "SHA256_MISMATCH")
        if "md5Checksum" in current and current["md5Checksum"] != md5.hexdigest():
            integrity_failure("dump_final_metadata", "MD5_MISMATCH")
        if not re.fullmatch(r"[0-9]{1,100}", current.get("version", "")):
            integrity_failure("dump_final_metadata", "VERSION_INVALID")
        remote_sha = hashlib.sha256()
        remote_size = 0
        try:
            with client.media(file_id) as response:
                while chunk := response.read(1024 * 1024):
                    remote_sha.update(chunk)
                    remote_size += len(chunk)
                    if remote_size > size:
                        integrity_failure("dump_readback", "SIZE_EXCEEDED")
        except (OSError, http.client.HTTPException):
            raise Failure("STORAGE_UNAVAILABLE") from None
        if remote_size != size:
            integrity_failure("dump_readback", "SIZE_MISMATCH")
        if remote_sha.hexdigest() != digest:
            integrity_failure("dump_readback", "SHA256_MISMATCH")
    evidence = {"storage_provider": "google_drive", "file_id": file_id, "manifest_id": manifest_id,
                "parent_id": client.folder_id, "bytes": size, "sha256": digest,
                "md5_checksum": md5.hexdigest(), "postgres_version": postgres_version,
                "storage_version": current["version"],
                "integrity_method": "DRIVE_SHA256_AND_READBACK" if "sha256Checksum" in metadata else "READBACK_SHA256"}
    manifest = {**evidence, "backup_id": backup_id, "external_run_id": run_id, "filename": file.name,
                "format": "custom", "pg_restore_verified": True, "readback_sha256": remote_sha.hexdigest(),
                "verified_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
    encoded = json.dumps(manifest, separators=(",", ":"), sort_keys=True).encode()
    client.resumable(manifest_id, file.name + ".manifest.json", "application/json", io.BytesIO(encoded), len(encoded),
                     backup_id, run_id, "manifest")
    manifest_meta = client.metadata(manifest_id)
    client.assert_private(manifest_meta, file.name + ".manifest.json", backup_id, run_id, "manifest")
    if manifest_meta.get("mimeType") != "application/json":
        integrity_failure("manifest_metadata", "MIME_TYPE_MISMATCH")
    if manifest_meta.get("size") != str(len(encoded)):
        integrity_failure("manifest_metadata", "SIZE_MISMATCH")
    with client.media(manifest_id) as response:
        if response.read(8193) != encoded:
            integrity_failure("manifest_readback", "MANIFEST_CONTENT_MISMATCH")
    return evidence
