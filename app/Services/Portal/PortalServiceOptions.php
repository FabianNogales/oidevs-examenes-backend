<?php

namespace App\Services\Portal;

use App\Rules\PortalInformationLink;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalServiceOptions
{
    public function options(): array
    {
        return [
            'icon_keys' => array_values(config('portal_services.icon_keys', [])),
            'destination_types' => ['INTERNAL', 'EXTERNAL'],
            'internal_destinations' => array_values(array_filter(config('portal_services.internal_destinations', []), fn ($item) => is_array($item) && isset($item['path'], $item['label'], $item['requires_auth'])
                && is_string($item['path']) && preg_match('~^/(?!/)[a-zA-Z0-9/_-]*$~D', $item['path'])
                && is_string($item['label']) && is_bool($item['requires_auth'])
            )),
        ];
    }

    public function validate(array $data): void
    {
        Validator::make($data, [
            'icon_key' => ['required', Rule::in($this->options()['icon_keys'])],
            'category_id' => ['required', 'integer', Rule::exists('portal_service_categories', 'id')->where('is_active', true)],
            'destination_type' => ['required', Rule::in(['INTERNAL', 'EXTERNAL'])],
            'destination' => ['required', 'string', 'max:2048'],
            'requires_auth' => ['required', 'boolean'],
        ])->validate();

        if ($data['destination_type'] === 'INTERNAL') {
            $option = collect($this->options()['internal_destinations'])->firstWhere('path', $data['destination']);
            if ($option === null) {
                throw ValidationException::withMessages(['destination' => ['El destino interno no está habilitado en service-options.']]);
            }
            if ($option['requires_auth'] && ! $data['requires_auth']) {
                throw ValidationException::withMessages(['requires_auth' => ['Este destino requiere iniciar sesión.']]);
            }
        } else {
            Validator::make($data, ['destination' => [new PortalInformationLink, function ($attribute, $value, $fail) {
                if (strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
                    $fail('El destino externo debe ser una URL HTTPS válida.');
                }
            }]])->validate();
        }
    }
}
