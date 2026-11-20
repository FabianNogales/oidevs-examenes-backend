<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueCatalogName implements ValidationRule
{
    public function __construct(private readonly string $table, private readonly ?int $except = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if ($this->except !== null && DB::table($this->table)->where('id', $this->except)->value('name') === $value) {
            return; // An unrelated edit must not require renaming historical duplicates.
        }
        $query = DB::table($this->table)->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [trim($value)]);
        if ($this->except !== null) {
            $query->where('id', '!=', $this->except);
        }
        if ($query->exists()) {
            $fail('El nombre ya está registrado, incluso con diferentes mayúsculas.');
        }
    }
}
