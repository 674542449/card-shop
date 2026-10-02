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
            'query_password.required' => '请输入查询密码',
            'query_password.string' => '查询密码格式错误',
        ];
    }
}
