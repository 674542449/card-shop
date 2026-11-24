<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueryOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return \App\Support\BuyerCredentialInput::body($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:200'],
            'query_password' => ['bail', 'required', 'string', 'max:50', new \App\Rules\QueryPasswordBytes],
            'order_no' => ['bail', 'nullable', 'string', 'max:30', new \App\Rules\BuyerText],
            'website' => setting('form_honeypot_enabled', '1') === '1'
                ? ['nullable', 'string', 'max:0']
                : ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => '请填写邮箱地址',
            'email.email' => '邮箱格式不正确',
            'email.max' => '邮箱地址长度不能超过 200 个字符',
            'query_password.required' => '请输入查询密码',
            'query_password.string' => '查询密码格式错误',
            'query_password.max' => '查询密码长度不能超过 50 个字符',
            'order_no.string' => '订单编号格式不正确',
            'order_no.max' => '订单编号长度不能超过 30 个字符',
            'website.max' => '请求异常，请刷新页面后重试',
        ];
    }
}
