<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'reason' => ['required', 'string', 'max:255'],
            'refund_method' => ['required', 'string', 'in:Cash,Credit Note,KBZPay,WavePay,Card Reversal,Credit Adjusted,Bank Transfer,cash,credit_note,credit_adjusted'],
            'refund_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'integer', 'exists:sales_order_items,id'],
            'items.*.qty' => ['nullable', 'integer', 'min:1'],
            'items.*.packaging_qty' => ['nullable', 'integer', 'min:1'],
            'items.*.is_packaging' => ['nullable', 'boolean'],
            'items.*.item_condition' => ['nullable', 'string', 'in:Sellable,Damaged,Defective,Scrap,sellable,damaged,defective,scrap'],
        ];
    }
}
