"""Isolated unit tests. Never invoke a Docker container, database or remote API."""

import hashlib
import hmac
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import MagicMock, patch

import executor
import drive_storage


class ExecutorTest(unittest.TestCase):
    def test_callback_signature_binds_body_nonce_method_path_and_timestamp(self):
        environment = {"BACKUP_CALLBACK_URL": "https://example.invalid" + executor.CALLBACK_PATH,
                       "BACKUP_CALLBACK_SECRET": "t" * 48}
        with patch.dict(os.environ, environment), patch("executor.urllib.request.build_opener") as opener:
            response = opener.return_value.open.return_value.__enter__.return_value
            response.status = 200
            executor.Callback("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "123.1").send("RUNNING")
            request = opener.return_value.open.call_args.args[0]
            headers = {key.lower(): value for key, value in request.header_items()}
            canonical = (headers["x-backup-timestamp"] + "\n" + headers["x-backup-nonce"]
                         + "\nPOST\n" + executor.CALLBACK_PATH + "\n").encode() + request.data
            self.assertEqual(headers["x-backup-signature"], hmac.new(b"t" * 48, canonical, hashlib.sha256).hexdigest())
            self.assertEqual(json.loads(request.data)["status"], "RUNNING")

    def test_callback_refuses_http_and_redirects(self):
        with self.assertRaises(executor.Failure):
            executor.https_url("http://example.invalid/callback")
        self.assertIsNone(executor.NoRedirect().redirect_request(None, None, 302, None, None, "https://other.invalid"))

    def test_unknown_uuid_claim_failure_prevents_any_database_operation(self):
        with patch.dict(os.environ, {"BACKUP_ID": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "BACKUP_RUN_ID": "123.1"}), \
                patch("executor.Callback") as callback, patch("executor.archive") as archive, patch("builtins.print"):
            callback.return_value.send.side_effect = executor.Failure("CALLBACK_REJECTED")
            self.assertEqual(executor.main(), 1)
            archive.assert_not_called()

    def test_tls_must_be_confirmed_by_server(self):
        environment = {"PGHOST": "db.example.invalid", "PGPORT": "5432", "PGDATABASE": "eida",
                       "PGUSER": "backup", "PGPASSWORD": "test", "PGSSLMODE": "require"}
        # Simulate the Linux runner identity even when unittest runs on Windows.
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, environment, clear=True), \
                patch.object(executor.os, "getuid", return_value=1000, create=True), \
                patch.object(executor.os, "getgid", return_value=1000, create=True), \
                patch("executor.command", return_value="170007|17.7|f") as command:
            with self.assertRaises(executor.Failure) as failure:
                executor.archive(Path(temporary), "id")
            self.assertEqual(failure.exception.code, "TLS_REQUIRED")
            command.assert_called_once()
            arguments = command.call_args.args[0]
            self.assertEqual(arguments[arguments.index("--user") + 1], "1000:1000")
            self.assertEqual(arguments[arguments.index("--network") + 1], "host")
            self.assertIn("psql", arguments)
            self.assertIn("pg_stat_ssl", arguments[-1])
            self.assertIn("PGSSLMODE", arguments)

    def test_remote_readback_hash_mismatch_never_uploads_a_verified_manifest(self):
        with tempfile.TemporaryDirectory() as temporary, patch("drive_storage.DriveClient", autospec=True) as factory:
            file = Path(temporary) / "test.dump"
            file.write_bytes(b"original")
            client = factory.return_value
            client.generated_ids.return_value = ["dump-id", "manifest-id"]
            client.metadata.return_value = {"mimeType": "application/octet-stream", "size": "8", "version": "1"}
            # Same length as "original": the failure must come from SHA-256, not size.
            client.media.return_value = io.BytesIO(b"tampered")
            with self.assertRaises(executor.Failure) as failure:
                executor.upload(file, "id", "123.1", "17.7")
            self.assertEqual(failure.exception.code, "INTEGRITY_MISMATCH")
            client.assert_private.assert_called_once_with(client.metadata.return_value, file.name, "id", "123.1", "dump")
            client.media.assert_called_once_with("dump-id")
            self.assertEqual(client.resumable.call_count, 1)
            self.assertEqual(client.resumable.call_args.args[-1], "dump")

    def client(self):
        environment = {"BACKUP_DRIVE_CLIENT_ID": "test", "BACKUP_DRIVE_CLIENT_SECRET": "test",
                       "BACKUP_DRIVE_REFRESH_TOKEN": "test", "BACKUP_DRIVE_FOLDER_ID": "folder-id"}
        with patch.dict(os.environ, environment):
            return drive_storage.DriveClient()

    def test_resumable_upload_probes_ambiguous_chunk_and_resumes_same_file_id(self):
        client = self.client()
        location = drive_storage.UPLOAD + "?uploadType=resumable&upload_id=test-session"
        client.request = MagicMock(side_effect=[
            (200, {"Location": location}, b""), executor.Failure("STORAGE_UNAVAILABLE"),
            (308, {"Range": "bytes=0-3"}, b""), (200, {}, b'{"id":"dump-id"}'),
        ])
        with patch("drive_storage.CHUNK_BYTES", 4):
            self.assertEqual(client.resumable("dump-id", "file.dump", "application/octet-stream", io.BytesIO(b"12345678"),
                                             8, "uuid", "123.1", "dump"), "dump-id")
        calls = client.request.call_args_list
        self.assertEqual(sum(call.args[0] == "POST" for call in calls), 1)
        self.assertEqual(calls[2].args[3]["Content-Range"], "bytes */8")
        self.assertEqual(calls[3].args[3]["Content-Range"], "bytes 4-7/8")
        self.assertTrue(all(call.args[1] == location for call in calls[1:]))

    def test_existing_backup_is_not_uploaded_again(self):
        client = self.client()
        client.request = MagicMock(return_value=(200, {}, b'{"files":[{"id":"existing"}]}'))
        with self.assertRaises(executor.Failure) as failure:
            client.require_no_backup("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")
        self.assertEqual(failure.exception.code, "DUPLICATE_FILE")

    def test_public_file_is_rejected(self):
        with self.assertRaises(executor.Failure):
            self.client().assert_private({"trashed": False, "shared": True, "ownedByMe": True, "isAppAuthorized": True})

    def test_revoked_refresh_token_is_distinguished(self):
        import urllib.error
        client = self.client()
        client.opener = MagicMock()
        client.opener.open.side_effect = urllib.error.HTTPError("https://oauth2.googleapis.com/token", 400, "error", {},
                                                               io.BytesIO(b'{"error":"invalid_grant"}'))
        with self.assertRaises(executor.Failure) as failure:
            client.token()
        self.assertEqual(failure.exception.code, "OAUTH_REVOKED")

    def test_incomplete_or_untrusted_session_is_not_restarted_elsewhere(self):
        client = self.client()
        client.request = MagicMock(return_value=(200, {"Location": "https://untrusted.example.invalid/upload"}, b""))
        with self.assertRaises(executor.Failure) as failure:
            client.resumable("dump-id", "file.dump", "application/octet-stream", io.BytesIO(b"data"), 4, "uuid", "123.1", "dump")
        self.assertEqual(failure.exception.code, "UPLOAD_INCOMPLETE")
        self.assertEqual(client.request.call_count, 1)


if __name__ == "__main__":
    unittest.main()
