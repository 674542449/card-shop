<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueryOrderRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'query_password' => ['required', 'string'],
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
