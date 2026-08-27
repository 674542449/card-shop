<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleCategoryRequest extends FormRequest
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
        $categoryId = $this->route('article_category');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('article_categories', 'slug')->ignore($categoryId),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => '分类名称不能为空',
            'name.string' => '分类名称必须是字符串',
            'name.max' => '分类名称不能超过100个字符',
            'slug.string' => '分类别名必须是字符串',
            'slug.max' => '分类别名不能超过100个字符',
            'slug.unique' => '该分类别名已被使用',
            'sort_order.integer' => '排序值必须是整数',
            'sort_order.min' => '排序值不能小于0',
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
            'name' => '分类名称',
            'slug' => '分类别名',
            'sort_order' => '排序',
        ];
    }
}
