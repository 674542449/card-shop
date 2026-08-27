<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $couponId = $this->route('coupon');

        $rules = [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('coupons', 'code')->ignore($couponId),
            ],
            'type' => ['required', Rule::in(['fixed', 'percent'])],
            'value' => ['required', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'exists:products,id'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
        ];

        if ($this->input('type') === 'percent') {
            $rules['value'][] = 'max:100';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => '优惠券代码不能为空',
            'code.string' => '优惠券代码必须是字符串',
            'code.max' => '优惠券代码不能超过50个字符',
            'code.unique' => '该优惠券代码已存在',
            'type.required' => '优惠券类型不能为空',
            'type.in' => '优惠券类型必须是固定金额或百分比',
            'value.required' => '优惠券面值不能为空',
            'value.numeric' => '优惠券面值必须是数字',
            'value.min' => '优惠券面值不能低于0.01',
            'value.max' => '百分比优惠券面值不能超过100',
            'max_uses.integer' => '最大使用次数必须是整数',
            'max_uses.min' => '最大使用次数不能小于0',
            'min_amount.numeric' => '最低消费金额必须是数字',
            'min_amount.min' => '最低消费金额不能小于0',
            'product_id.exists' => '所选商品不存在',
            'starts_at.date' => '开始时间格式不正确',
            'expires_at.date' => '过期时间格式不正确',
            'expires_at.after' => '过期时间必须晚于开始时间',
            'is_active.boolean' => '启用状态格式不正确',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => '优惠券代码',
            'type' => '优惠券类型',
            'value' => '优惠券面值',
            'max_uses' => '最大使用次数',
            'min_amount' => '最低消费金额',
            'product_id' => '关联商品',
            'starts_at' => '开始时间',
            'expires_at' => '过期时间',
            'is_active' => '启用状态',
        ];
    }
}
