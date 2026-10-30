<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plate_number' => ['required', 'string', 'max:50', 'unique:vehicles,plate_number'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'fleet_id' => ['nullable', 'integer', 'exists:fleets,id'],
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
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
