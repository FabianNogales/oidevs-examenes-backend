"""Isolated unit tests. Never invoke a Docker container, database or remote API."""

import contextlib
import hashlib
import hmac
import io
import json
import os
import socket
import subprocess
from pathlib import Path
import tempfile
import unittest
import urllib.error
from unittest.mock import MagicMock, patch

import executor
import drive_storage


class ExecutorTest(unittest.TestCase):
    def test_rejected_callback_logs_only_safe_http_category_and_known_fields(self):
        environment = {"BACKUP_CALLBACK_URL": "https://example.invalid" + executor.CALLBACK_PATH,
                       "BACKUP_CALLBACK_SECRET": "sensitive-secret-marker-" * 3}
        cases = [(401, "authentication_rejected", "EXECUTOR_UNAUTHENTICATED"),
                 (403, "authorization_rejected", None), (409, "state_conflict", "INVALID_BACKUP_TRANSITION"),
                 (422, "payload_invalid", None), (308, "redirect_rejected", None)]
        for http_status, category, code in cases:
            with self.subTest(http_status=http_status), patch.dict(os.environ, environment, clear=True), \
                    patch("executor.urllib.request.build_opener") as opener, \
                    patch("executor.time.sleep") as sleep, contextlib.redirect_stdout(io.StringIO()) as output:
                body = json.dumps({"code": code, "message": environment["BACKUP_CALLBACK_SECRET"],
                                   "errors": {"external_run_id": ["sensitive-validation-marker"],
                                              "sensitive-field-marker": ["sensitive-token-marker"]}}).encode()
                opener.return_value.open.side_effect = urllib.error.HTTPError(
                    "https://sensitive-host-marker.invalid", http_status, "sensitive-reason-marker", {},
                    io.BytesIO(body))
                with self.assertRaises(executor.Failure) as failure:
                    executor.Callback("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "123.1").send("RUNNING")
                self.assertEqual(failure.exception.code, "CALLBACK_REJECTED")
                diagnostic = json.loads(output.getvalue())
                self.assertEqual(diagnostic["phase"], "RUNNING")
                self.assertEqual(diagnostic["http_status"], http_status)
                self.assertEqual(diagnostic["category"], category)
                self.assertEqual(diagnostic["response_code"], code)
                self.assertEqual(diagnostic["validation_fields"], ["external_run_id"])
                self.assertEqual(diagnostic["execution_state"], "claim_pending")
                self.assertNotIn("sensitive", output.getvalue())
                self.assertNotIn("signature", output.getvalue().lower())
                sleep.assert_not_called()

    def test_unknown_response_codes_and_html_are_never_echoed(self):
        environment = {"BACKUP_CALLBACK_URL": "https://example.invalid" + executor.CALLBACK_PATH,
                       "BACKUP_CALLBACK_SECRET": "t" * 48}
        for body in [b'{"code":"sensitive-provider-marker"}', b"<html>sensitive-marker</html>",
                     b'{"message":"' + b"sensitive" * 800 + b'"}']:
            with patch.dict(os.environ, environment, clear=True), \
                    patch("executor.urllib.request.build_opener") as opener, \
                    contextlib.redirect_stdout(io.StringIO()) as output:
                opener.return_value.open.side_effect = urllib.error.HTTPError(
                    environment["BACKUP_CALLBACK_URL"], 403, "", {}, io.BytesIO(body))
                with self.assertRaises(executor.Failure):
                    executor.Callback("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "123.1").send("RUNNING")
                self.assertIsNone(json.loads(output.getvalue())["response_code"])
                self.assertNotIn("sensitive", output.getvalue())

    def test_transport_errors_are_classified_without_exception_text_and_retried_boundedly(self):
        environment = {"BACKUP_CALLBACK_URL": "https://example.invalid" + executor.CALLBACK_PATH,
                       "BACKUP_CALLBACK_SECRET": "t" * 48}
        with patch.dict(os.environ, environment, clear=True), \
                patch("executor.urllib.request.build_opener") as opener, patch("executor.time.sleep"), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            opener.return_value.open.side_effect = urllib.error.URLError(
                socket.gaierror("sensitive-dns-marker"))
            with self.assertRaises(executor.Failure) as failure:
                executor.Callback("aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "123.1").send("RUNNING")
            self.assertEqual(failure.exception.code, "CALLBACK_UNREACHABLE")
            diagnostics = [json.loads(line) for line in output.getvalue().splitlines()]
            self.assertEqual(len(diagnostics), 4)
            self.assertTrue(all(item["transport_error"] == "dns" for item in diagnostics))
            self.assertTrue(all(item["http_status"] is None for item in diagnostics))
            self.assertNotIn("sensitive", output.getvalue())

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
                patch("executor.command", return_value="170007|17.7|f") as command, \
                contextlib.redirect_stdout(io.StringIO()) as output:
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
            self.assertEqual(json.loads(output.getvalue())["category"], "SERVER_TLS_NOT_CONFIRMED")

    def test_postgres_password_rejection_is_diagnosed_without_exposing_stderr(self):
        environment = {"PGHOST": "db.example.invalid", "PGPORT": "5432", "PGDATABASE": "eida",
                       "PGUSER": "backup", "PGPASSWORD": "sensitive-password-marker", "PGSSLMODE": "require"}
        result = subprocess.CompletedProcess(
            [], 2, b"", b'FATAL: password authentication failed for user "sensitive-user-marker"')
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, environment, clear=True), \
                patch.object(executor.os, "getuid", return_value=1000, create=True), \
                patch.object(executor.os, "getgid", return_value=1000, create=True), \
                patch("executor.subprocess.run", return_value=result) as run, \
                contextlib.redirect_stdout(io.StringIO()) as output:
            with self.assertRaises(executor.Failure) as failure:
                executor.archive(Path(temporary), "fixture-id")
            self.assertEqual(failure.exception.code, "TLS_REQUIRED")
            self.assertEqual(json.loads(output.getvalue())["category"], "AUTHENTICATION_REJECTED")
            self.assertNotIn("sensitive", output.getvalue())
            run.assert_called_once()

    def postgres_environment(self):
        return {"PGHOST": "db.example.invalid", "PGPORT": "5432", "PGDATABASE": "fixture_database_marker",
                "PGUSER": "sensitive-user-marker", "PGPASSWORD": "sensitive-password-marker",
                "PGSSLMODE": "require"}

    def test_postgres_failure_categories_never_echo_provider_output(self):
        cases = [
            (125, b"sensitive daemon failure", "DOCKER_EXECUTION_FAILED"),
            (126, b"sensitive invocation failure", "DOCKER_EXECUTION_FAILED"),
            (127, b"sensitive command missing", "DOCKER_EXECUTION_FAILED"),
            (2, b'could not translate host name "sensitive-host": Name or service not known',
             "DNS_RESOLUTION_FAILED"),
            (2, b"sensitive: Network is unreachable", "NETWORK_UNREACHABLE"),
            (2, b"sensitive: Connection refused", "HOST_OR_PORT_UNREACHABLE"),
            (2, b"sensitive: timeout expired", "CONNECTION_TIMED_OUT"),
            (2, b'FATAL: database "sensitive-db" does not exist', "DATABASE_NOT_FOUND"),
            (2, b"FATAL: 28P01: sensitive", "AUTHENTICATION_REJECTED"),
            (2, b"FATAL: 3D000: sensitive", "DATABASE_NOT_FOUND"),
            (2, b"SSL error: certificate verify failed sensitive", "TLS_NEGOTIATION_FAILED"),
            (2, b"server does not support SSL, but SSL was required sensitive", "TLS_NEGOTIATION_FAILED"),
            (2, b'root certificate file "/sensitive/root.crt" does not exist', "TLS_CERTIFICATE_UNAVAILABLE"),
            (2, b"no pg_hba.conf entry for sensitive", "CONNECTION_POLICY_REJECTED"),
            (2, b"invalid port number: sensitive", "CONNECTION_CONFIGURATION_INVALID"),
            (2, b"sensitive unrecognized connection error", "CONNECTION_FAILED"),
            (3, b"ERROR: permission denied for view pg_stat_ssl sensitive", "QUERY_FAILED"),
            (3, b"sensitive unrecognized query error", "QUERY_FAILED"),
        ]
        for returncode, stderr, expected in cases:
            with self.subTest(category=expected, returncode=returncode), tempfile.TemporaryDirectory() as temporary, \
                    patch.dict(os.environ, self.postgres_environment(), clear=True), \
                    patch.object(executor.os, "getuid", return_value=1000, create=True), \
                    patch.object(executor.os, "getgid", return_value=1000, create=True), \
                    patch("executor.subprocess.run", return_value=subprocess.CompletedProcess(
                        [], returncode, b"", stderr)) as run, \
                    contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaises(executor.Failure) as failure:
                    executor.archive(Path(temporary), "fixture-id")
                self.assertEqual(failure.exception.code, "TLS_REQUIRED")
                self.assertEqual(json.loads(output.getvalue()), {
                    "event": "BACKUP_POSTGRES_DIAGNOSTIC", "stage": "discovery", "category": expected})
                self.assertNotIn("sensitive", output.getvalue())
                self.assertNotIn(stderr.decode(), output.getvalue())
                run.assert_called_once()

    def test_postgres_docker_launch_errors_and_timeout_are_safe(self):
        cases = [(FileNotFoundError("sensitive binary path"), "DOCKER_EXECUTION_FAILED"),
                 (subprocess.TimeoutExpired(["sensitive argv"], 120, stderr=b"sensitive stderr"),
                  "COMMAND_TIMED_OUT")]
        for error, expected in cases:
            with self.subTest(category=expected), tempfile.TemporaryDirectory() as temporary, \
                    patch.dict(os.environ, self.postgres_environment(), clear=True), \
                    patch.object(executor.os, "getuid", return_value=1000, create=True), \
                    patch.object(executor.os, "getgid", return_value=1000, create=True), \
                    patch("executor.subprocess.run", side_effect=error), \
                    contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaises(executor.Failure) as failure:
                    executor.archive(Path(temporary), "fixture-id")
                self.assertEqual(failure.exception.code, "TLS_REQUIRED")
                self.assertEqual(json.loads(output.getvalue())["category"], expected)
                self.assertNotIn("sensitive", output.getvalue())

    def test_invalid_tls_modes_fail_before_any_docker_command(self):
        for mode in ("disable", "prefer", "allow", "sensitive-invalid-mode"):
            environment = {**self.postgres_environment(), "PGSSLMODE": mode}
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as temporary, \
                    patch.dict(os.environ, environment, clear=True), patch("executor.subprocess.run") as run, \
                    contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaises(executor.Failure) as failure:
                    executor.archive(Path(temporary), "fixture-id")
                self.assertEqual(failure.exception.code, "TLS_REQUIRED")
                self.assertEqual(json.loads(output.getvalue()), {
                    "event": "BACKUP_POSTGRES_DIAGNOSTIC",
                    "stage": "configuration", "category": "TLS_MODE_INVALID"})
                self.assertNotIn(mode, output.getvalue())
                run.assert_not_called()

    def test_invalid_discovery_response_does_not_bypass_tls_confirmation(self):
        for discovered in ("", "sensitive-output", "invalid|17.7|t", "170007|17.7|unknown"):
            with self.subTest(discovered=discovered), tempfile.TemporaryDirectory() as temporary, \
                    patch.dict(os.environ, self.postgres_environment(), clear=True), \
                    patch.object(executor.os, "getuid", return_value=1000, create=True), \
                    patch.object(executor.os, "getgid", return_value=1000, create=True), \
                    patch("executor.subprocess.run", return_value=subprocess.CompletedProcess(
                        [], 0, discovered.encode(), b"")), \
                    contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaises(executor.Failure) as failure:
                    executor.archive(Path(temporary), "fixture-id")
                self.assertEqual(failure.exception.code, "TLS_REQUIRED")
                self.assertEqual(json.loads(output.getvalue())["category"], "QUERY_RESULT_INVALID")
                self.assertNotIn("sensitive", output.getvalue())

    def test_confirmed_tls_preserves_full_archive_commands_and_environment_only_credentials(self):
        for mode in ("require", "verify-full"):
            environment = {**self.postgres_environment(), "PGSSLMODE": mode}
            if mode == "verify-full":
                environment["BACKUP_PG_CA_PEM"] = "synthetic-ca-marker"
            results = [subprocess.CompletedProcess([], 0, stdout, b"") for stdout in
                       (b"170007|17.7|t\n", b"pg_dump (PostgreSQL) 17.7\n", b"", b"fixture table of contents")]
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as temporary, \
                    patch.dict(os.environ, environment, clear=True), \
                    patch.object(executor.os, "getuid", return_value=1000, create=True), \
                    patch.object(executor.os, "getgid", return_value=1000, create=True), \
                    patch("executor.subprocess.run", side_effect=results) as run, \
                    contextlib.redirect_stdout(io.StringIO()) as output:
                archive, version = executor.archive(Path(temporary), "fixture-id")
                self.assertEqual(archive.name, "eida-fixture-id.dump")
                self.assertEqual(version, "17.7")
                self.assertEqual(run.call_count, 4)
                self.assertEqual(output.getvalue(), "")
                commands = [call.args[0] for call in run.call_args_list]
                self.assertIn("psql", commands[0])
                self.assertIn("pg_stat_ssl", commands[0][-1])
                self.assertIn("LC_ALL=C", commands[0])
                self.assertIn("--format=custom", commands[2])
                self.assertIn("pg_restore", commands[3])
                for arguments in commands:
                    self.assertIn("PGPASSWORD", arguments)
                    self.assertNotIn(environment["PGPASSWORD"], " ".join(arguments))
                    self.assertFalse(any(argument.startswith("--exclude") for argument in arguments))
                if mode == "verify-full":
                    self.assertIn("PGSSLROOTCERT=/backup/root.crt", commands[0])

    def test_postgres_diagnostic_keeps_the_allowed_failed_callback_contract(self):
        environment = {**self.postgres_environment(),
                       "BACKUP_ID": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "BACKUP_RUN_ID": "123.1"}
        with patch.dict(os.environ, environment, clear=True), \
                patch.object(executor.os, "getuid", return_value=1000, create=True), \
                patch.object(executor.os, "getgid", return_value=1000, create=True), \
                patch("executor.Callback") as callback, patch("executor.upload") as upload, \
                patch("executor.signal.signal"), patch("executor.subprocess.run",
                    return_value=subprocess.CompletedProcess(
                        [], 2, b"", b"FATAL: password authentication failed for sensitive-user")), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(executor.main(), 1)
            callback.return_value.send.assert_any_call("RUNNING")
            callback.return_value.send.assert_any_call("FAILED", error_code="TLS_REQUIRED")
            self.assertEqual(callback.return_value.send.call_count, 2)
            upload.assert_not_called()
            lines = output.getvalue().splitlines()
            self.assertEqual(json.loads(lines[0])["category"], "AUTHENTICATION_REJECTED")
            self.assertEqual(lines[-1], "TLS_REQUIRED")
            self.assertNotIn("sensitive", output.getvalue())

    def test_other_command_failures_do_not_acquire_postgres_diagnostics(self):
        with patch("executor.subprocess.run", return_value=subprocess.CompletedProcess(
                [], 2, b"", b"sensitive password authentication failed")), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            with self.assertRaises(executor.Failure) as failure:
                executor.command(["fixture-program"], "DUMP_FAILED")
            self.assertEqual(failure.exception.code, "DUMP_FAILED")
            self.assertEqual(output.getvalue(), "")

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
