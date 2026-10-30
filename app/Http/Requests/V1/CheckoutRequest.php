<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cashier_id' => ['nullable', 'integer', 'exists:staff_users,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_option_id' => ['nullable', 'integer', 'exists:product_options,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.uom_conversion_id' => ['nullable', 'integer', 'exists:product_uom_conversions,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', 'in:Cash,KPay,WavePay,Card,Credit,Bank Transfer,cash,kpay,card,credit'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_note' => ['nullable', 'string', 'max:255'],
            'manager_pin' => ['nullable', 'string', 'digits_between:4,8'],
            'promotion_code' => ['nullable', 'string', 'max:50'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'enforce_session' => ['nullable', 'boolean'],
            'bypass_shift_session' => ['nullable', 'boolean'],
        ];
    }
}
