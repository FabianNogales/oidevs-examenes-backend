<?php

namespace App\Services\Portal;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\Admin\PermissionAuthorization;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortalEditing
{
    public const PERMISSIONS = [
        'news' => 'portal.news.update', 'events' => 'portal.events.update',
        'sections' => 'portal.information.sections.update', 'faqs' => 'portal.information.faqs.update',
        'services' => 'portal.services.update',
    ];

    private const FIELDS = [
        'news' => ['title', 'summary', 'content', 'revision'],
        'events' => ['title', 'description', 'category_id', 'starts_at', 'ends_at', 'all_day', 'location', 'revision'],
        'sections' => ['title', 'description', 'links', 'sort_order', 'revision'],
        'faqs' => ['question', 'answer', 'sort_order', 'revision'],
        'services' => ['name', 'description', 'icon', 'url', 'display_order', 'revision'],
    ];

    public function __construct(private readonly PermissionAuthorization $access) {}

    public static function section(Request $request): string
    {
        foreach (['news', 'events', 'services'] as $section) {
            if ($request->routeIs('portal.admin.'.$section.'.*')) {
                return $section;
            }
        }

        return $request->routeIs('portal.admin.information.faqs.*') ? 'faqs' : 'sections';
    }

    public static function forbiddenFields(string $section, array $data): array
    {
        return array_diff(array_keys($data), self::FIELDS[$section]);
    }

    /** Called in the existing transaction. Creation and access/visibility fields remain reserved. */
    public function authorizeSave(string $section, array $data, Request $request, ?int $id): User
    {
        $actor = $this->access->authorizeWrite($request, self::PERMISSIONS[$section]);
        if (! $actor->hasRole(RoleName::ADMINISTRADOR)) {
            abort_if($id === null, 403, 'Crear contenido requiere acceso administrativo.');
            $errors = [];
            foreach (self::forbiddenFields($section, $data) as $field) {
                $errors[$field] = 'Este campo no está autorizado para la edición de esta sección.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $actor;
    }
}
