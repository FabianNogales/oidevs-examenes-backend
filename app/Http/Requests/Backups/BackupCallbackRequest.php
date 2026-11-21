<?php

namespace App\Http\Requests\Backups;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BackupCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // HMAC middleware is the executor authority, never a browser session.
    }

    public function rules(): array
    {
        return [
            'backup_id' => ['required', 'uuid'],
            'external_run_id' => ['required', 'string', 'regex:/\A[0-9]{1,30}\.[0-9]{1,10}\z/'],
            'status' => ['required', Rule::in(['RUNNING', 'UPLOADING', 'AVAILABLE', 'FAILED'])],
            'storage_provider' => ['required_if:status,AVAILABLE', Rule::in(['google_drive'])],
            'file_id' => ['required_if:status,AVAILABLE', 'string', 'regex:/\A[A-Za-z0-9_-]{1,200}\z/'],
            'manifest_id' => ['required_if:status,AVAILABLE', 'string', 'regex:/\A[A-Za-z0-9_-]{1,200}\z/'],
            'parent_id' => ['required_if:status,AVAILABLE', 'string', 'regex:/\A[A-Za-z0-9_-]{1,200}\z/'],
            'bytes' => ['required_if:status,AVAILABLE', 'integer', 'min:1'],
            'sha256' => ['required_if:status,AVAILABLE', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'storage_version' => ['required_if:status,AVAILABLE', 'string', 'regex:/\A[0-9]{1,100}\z/'],
            'md5_checksum' => ['nullable', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'integrity_method' => ['required_if:status,AVAILABLE', Rule::in(['DRIVE_SHA256_AND_READBACK', 'READBACK_SHA256'])],
            'postgres_version' => ['required_if:status,AVAILABLE', 'string', 'max:100', 'regex:/\A[0-9][0-9A-Za-z .()+_-]*\z/'],
            'error_code' => ['required_if:status,FAILED', Rule::in([
                'EXECUTOR_FAILED', 'EXECUTOR_CANCELLED', 'TLS_REQUIRED', 'PG_VERSION_UNSUPPORTED',
                'DUMP_FAILED', 'INVALID_ARCHIVE', 'UPLOAD_FAILED', 'UPLOAD_INCOMPLETE', 'DUPLICATE_FILE',
                'OAUTH_REVOKED', 'DRIVE_PERMISSION_DENIED', 'DRIVE_QUOTA_EXCEEDED', 'DRIVE_RATE_LIMITED', 'FILE_MISSING',
                'INTEGRITY_MISMATCH', 'STORAGE_UNAVAILABLE', 'CONFIGURATION_INVALID',
            ])],
        ];
    }
}
