<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitOfMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uomId = $this->route('id') ?? $this->route('unit_of_measure');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('units_of_measure', 'name')->ignore($uomId)],
            'description' => ['nullable', 'string', 'max:100'],
        ];
    }
}
