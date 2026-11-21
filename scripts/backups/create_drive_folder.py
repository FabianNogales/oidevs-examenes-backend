"""Manual bootstrap, NOT invoked by the workflow or Laravel. Requires explicit operator authorization."""

import argparse
import json

from backup_errors import Failure
from drive_storage import API, DriveClient


def main():
    parser = argparse.ArgumentParser(description="Create a private application-managed Drive backup folder.")
    parser.add_argument("--create-private-folder", action="store_true", required=True)
    parser.parse_args()
    try:
        client = DriveClient(require_folder=False)
        folder_id = client.generated_ids(1)[0]
        metadata = {"id": folder_id, "name": "EIDA database backups", "mimeType": "application/vnd.google-apps.folder",
                    "appProperties": {"eida_backup_root": "1"}}
        # Fixed ID, no blind POST retry; a lost response cannot create a second folder.
        client.request("POST", API, json.dumps(metadata).encode(), {"Content-Type": "application/json"})
        folder = client.metadata(folder_id)
        client.assert_private(folder)
        if folder.get("appProperties", {}).get("eida_backup_root") != "1":
            raise Failure("DRIVE_PERMISSION_DENIED")
        print("PRIVATE_FOLDER_ID=" + folder_id)  # Identifier only; never print OAuth credentials.
        return 0
    except Exception as error:
        print(error.code if isinstance(error, Failure) else "CONFIGURATION_INVALID")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
