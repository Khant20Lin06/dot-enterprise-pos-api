<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOutsourcedPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'part_name' => ['nullable', 'string', 'max:200'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'bill_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:Ordered,In Transit,Received & Fitted,Cancelled'],
            'payment_status' => ['nullable', 'string', 'in:Unpaid,Paid'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
