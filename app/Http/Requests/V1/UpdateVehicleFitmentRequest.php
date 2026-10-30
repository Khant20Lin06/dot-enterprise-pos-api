<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleFitmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'make' => 'sometimes|required|string|max:100',
            'model' => 'sometimes|required|string|max:100',
            'year_from' => 'nullable|integer|min:1950|max:2050',
            'year_to' => [
                'nullable',
                'integer',
                'min:1950',
                'max:2050',
                function ($attribute, $value, $fail) {
                    $yearFrom = $this->input('year_from');
                    if ($yearFrom && $value < $yearFrom) {
                        $fail('The year to field must be greater than or equal to year from.');
                    }
                },
            ],
            'engine_code' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
