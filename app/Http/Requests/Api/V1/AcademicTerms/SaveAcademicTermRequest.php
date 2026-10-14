<?php

namespace App\Http\Requests\Api\V1\AcademicTerms;

use App\Services\AcademicTerms\AcademicTermIntegrity;
use Illuminate\Foundation\Http\FormRequest;

class SaveAcademicTermRequest extends FormRequest
{
    private array $payload = [];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    protected function passedValidation(): void
    {
        $this->payload = app(AcademicTermIntegrity::class)->validatePayload($this->all(), $this->isMethod('POST'));
    }

    public function validated($key = null, $default = null)
    {
        return data_get($this->payload, $key, $default);
    }
}
