<?php

namespace App\Http\Requests\Incidents;

use App\Services\Incidents\IncidentVocabulary;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as Validation;
use Illuminate\Validation\Rule;

class SyncMobileIncidentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return ['event_json' => ['required', 'string', 'max:65536'],
            'event_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
    }

    public function event(): array
    {
        return json_decode($this->validated('event_json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (array_diff(array_keys($this->all()), ['event_json', 'event_sha256'])) {
                $validator->errors()->add('event_json', 'El sobre contiene campos no permitidos.');
            }
            $raw = $this->input('event_json');
            if (! is_string($raw)) return;
            if (strlen($raw) > 65536) { $validator->errors()->add('event_json', 'El evento supera el límite de bytes.'); return; }
            try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { $event = null; }
            if (! is_array($event) || array_is_list($event)) {
                $validator->errors()->add('event_json', 'El evento debe ser un objeto JSON.'); return;
            }
            $keys = ['v', 'event_id', 'exam_id', 'package_id', 'actor_id', 'mobile_device_id', 'installation_id',
                'student_id', 'type', 'description', 'captured_at', 'time_evidence'];
            if (array_diff(array_keys($event), $keys) || array_diff($keys, array_keys($event))) {
                $validator->errors()->add('event_json', 'El evento contiene campos ausentes o no permitidos.');
            }
            $id = ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/'];
            $uuid = ['required', 'string', 'uuid', 'lowercase'];
            $check = Validation::make($event, [
                'v' => ['required', 'integer', Rule::in([1])], 'event_id' => $uuid, 'package_id' => $uuid,
                'actor_id' => $id, 'exam_id' => $id, 'mobile_device_id' => $id, 'installation_id' => $uuid,
                'student_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/'],
                'type' => ['required', Rule::in(IncidentVocabulary::TYPES)],
                'description' => ['required', 'string', 'max:4000'],
                'captured_at' => ['required', 'date', 'regex:/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.000Z$/'],
                'time_evidence' => ['required', 'array:serverMs,boot,elapsed,capturedElapsed,source'],
                'time_evidence.serverMs' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
                'time_evidence.boot' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
                'time_evidence.elapsed' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
                'time_evidence.capturedElapsed' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
                'time_evidence.source' => ['required', Rule::in(['SERVER_ANCHORED_ESTIMATE'])],
            ]);
            if ($check->fails() || ($event['v'] ?? null) !== 1 || ! is_string($event['description'] ?? null) || trim($event['description']) === '') {
                $validator->errors()->add('event_json', 'El contrato del reporte offline no es válido.');
            }
            foreach (['serverMs', 'elapsed', 'capturedElapsed'] as $field) {
                if (! is_int($event['time_evidence'][$field] ?? null)) $validator->errors()->add('event_json', 'La evidencia temporal requiere enteros JSON.');
            }
            if (! is_string($this->input('event_sha256')) || ! hash_equals(hash('sha256', $raw), $this->input('event_sha256'))) {
                $validator->errors()->add('event_sha256', 'La huella del evento no coincide.');
            }
        });
    }
}
