<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
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
        $productId = $this->route('product');

        return [
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:200',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'price' => ['required', 'numeric', 'min:0.01'],
            'min_quantity' => ['integer', 'min:1'],
            'max_quantity' => ['integer', 'min:1', 'gte:min_quantity'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'seo_keywords' => ['nullable', 'string', 'max:200'],

            'wholesale_prices' => ['array'],
            'wholesale_prices.*.min_quantity' => ['required_with:wholesale_prices', 'integer', 'min:2'],
            'wholesale_prices.*.price' => ['required_with:wholesale_prices', 'numeric', 'min:0.01'],
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
            'category_id.required' => '请选择商品分类',
            'category_id.exists' => '所选分类不存在',
            'name.required' => '商品名称不能为空',
            'name.string' => '商品名称必须是字符串',
            'name.max' => '商品名称不能超过200个字符',
            'slug.string' => '商品别名必须是字符串',
            'slug.max' => '商品别名不能超过200个字符',
            'slug.unique' => '该商品别名已被使用',
            'price.required' => '商品价格不能为空',
            'price.numeric' => '商品价格必须是数字',
            'price.min' => '商品价格不能低于0.01',
            'min_quantity.integer' => '最小购买数量必须是整数',
            'min_quantity.min' => '最小购买数量不能小于1',
            'max_quantity.integer' => '最大购买数量必须是整数',
            'max_quantity.min' => '最大购买数量不能小于1',
            'max_quantity.gte' => '最大购买数量不能小于最小购买数量',
            'description.string' => '商品描述必须是字符串',
            'is_active.boolean' => '上架状态格式不正确',
            'sort_order.integer' => '排序值必须是整数',
            'sort_order.min' => '排序值不能小于0',
            'seo_title.string' => 'SEO标题必须是字符串',
            'seo_title.max' => 'SEO标题不能超过200个字符',
            'seo_description.string' => 'SEO描述必须是字符串',
            'seo_description.max' => 'SEO描述不能超过500个字符',
            'seo_keywords.string' => 'SEO关键词必须是字符串',
            'seo_keywords.max' => 'SEO关键词不能超过200个字符',

            'wholesale_prices.array' => '批发价格格式不正确',
            'wholesale_prices.*.min_quantity.required_with' => '批发最小数量不能为空',
            'wholesale_prices.*.min_quantity.integer' => '批发最小数量必须是整数',
            'wholesale_prices.*.min_quantity.min' => '批发最小数量不能小于2',
            'wholesale_prices.*.price.required_with' => '批发价格不能为空',
            'wholesale_prices.*.price.numeric' => '批发价格必须是数字',
            'wholesale_prices.*.price.min' => '批发价格不能低于0.01',
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
            'category_id' => '商品分类',
            'name' => '商品名称',
            'slug' => '商品别名',
            'price' => '商品价格',
            'min_quantity' => '最小购买数量',
            'max_quantity' => '最大购买数量',
            'description' => '商品描述',
            'is_active' => '上架状态',
            'sort_order' => '排序',
            'seo_title' => 'SEO标题',
            'seo_description' => 'SEO描述',
            'seo_keywords' => 'SEO关键词',
            'wholesale_prices' => '批发价格',
            'wholesale_prices.*.min_quantity' => '批发最小数量',
            'wholesale_prices.*.price' => '批发价格',
        ];
    }
}
