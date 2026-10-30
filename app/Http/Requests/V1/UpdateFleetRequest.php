<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFleetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $fleetId = $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150', Rule::unique('fleets', 'name')->ignore($fleetId)],
            'type' => ['nullable', 'string', 'max:50'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
