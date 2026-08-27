<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'email' => ['required', 'email', 'max:200'],
            'query_password' => ['required', 'string', 'min:6', 'max:50'],
            'quantity' => ['required', 'integer', 'min:1'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['required', 'in:alipay,wechat,usdt_trc20,usdt_bep20,usdt_polygon'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => '请选择商品',
            'product_id.integer' => '商品ID格式错误',
            'product_id.exists' => '商品不存在',
            'email.required' => '请填写邮箱地址',
            'email.email' => '邮箱格式不正确',
            'email.max' => '邮箱地址不能超过200个字符',
            'query_password.required' => '请设置查询密码',
            'query_password.string' => '查询密码格式错误',
            'query_password.min' => '查询密码至少6个字符',
            'query_password.max' => '查询密码不能超过50个字符',
            'quantity.required' => '请输入购买数量',
            'quantity.integer' => '购买数量必须为整数',
            'quantity.min' => '购买数量至少为1',
            'coupon_code.max' => '优惠券代码不能超过50个字符',
            'payment_method.required' => '请选择支付方式',
            'payment_method.in' => '不支持的支付方式',
        ];
    }
}
