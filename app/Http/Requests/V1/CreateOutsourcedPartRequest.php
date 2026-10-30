<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateOutsourcedPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'part_name' => ['required', 'string', 'max:200'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'bill_price' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:Ordered,In Transit,Received & Fitted,Cancelled'],
            'payment_status' => ['nullable', 'string', 'in:Unpaid,Paid'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
