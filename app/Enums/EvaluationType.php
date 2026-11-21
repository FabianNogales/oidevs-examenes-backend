<?php

namespace App\Enums;

enum EvaluationType: string
{
    case PARTIAL = 'partial';
    case FINAL = 'final';
    case MAKEUP = 'makeup';
    case RECOVERY = 'recovery';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::values());
    }

    public static function validationMessage(): string
    {
        return 'El tipo debe ser partial, final, makeup o recovery.';
    }

    public function supportsPartialNumber(): bool
    {
        return $this === self::PARTIAL;
    }
}
