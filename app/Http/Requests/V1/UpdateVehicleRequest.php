<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vehicleId = $this->route('id') ?? $this->route('vehicle');

        return [
            'plate_number' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('vehicles', 'plate_number')->ignore($vehicleId)],
            'customer_id' => ['sometimes', 'required', 'integer', 'exists:customers,id'],
            'fleet_id' => ['nullable', 'integer', 'exists:fleets,id'],
            'make' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:' . (date('Y') + 2)],
            'vin' => ['nullable', 'string', 'max:100'],
            'engine_no' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'transmission' => ['nullable', 'string', 'in:Automatic,Manual,CVT'],
            'fuel_type' => ['nullable', 'string', 'in:Petrol,Diesel,Hybrid,EV'],
            'current_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
