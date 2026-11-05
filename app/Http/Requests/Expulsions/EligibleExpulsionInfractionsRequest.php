<?php

namespace App\Http\Requests\Expulsions;

class EligibleExpulsionInfractionsRequest extends ListExpulsionsRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['student_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);
    }
}
