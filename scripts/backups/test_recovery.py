import hashlib
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import MagicMock, patch

import verify_backup
from backup_errors import Failure


class OfflineRecoveryTest(unittest.TestCase):
    def test_recovery_checks_local_drive_downloads_without_backend_or_railway(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, {}, clear=True), \
                patch("verify_backup.subprocess.run", return_value=MagicMock(returncode=0)) as restore:
            dump = Path(temporary) / "eida.dump"
            manifest = Path(temporary) / "eida.dump.manifest.json"
            dump.write_bytes(b"downloaded from private Drive")
            digest = hashlib.sha256(dump.read_bytes()).hexdigest()
            manifest.write_text(json.dumps({"format": "custom", "bytes": dump.stat().st_size,
                                            "sha256": digest, "readback_sha256": digest}))
            self.assertTrue(verify_backup.verify(dump, manifest))
            self.assertEqual(restore.call_args.args[0], ["pg_restore", "--list", str(dump.resolve())])
            dump.write_bytes(b"corrupted")
            with self.assertRaises(Failure):
                verify_backup.verify(dump, manifest)
