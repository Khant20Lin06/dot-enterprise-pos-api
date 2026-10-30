<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class AttachVehicleFitmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year_from' => ['nullable', 'integer', 'min:1950', 'max:2050'],
            'year_to' => ['nullable', 'integer', 'min:1950', 'max:2050'],
            'engine_code' => ['nullable', 'string', 'max:100'],
            'chassis_code' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
