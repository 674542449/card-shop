<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleRequest extends FormRequest
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
        $articleId = $this->route('article');

        $rules = [
            'article_category_id' => ['required', 'exists:article_categories,id'],
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:200',
                Rule::unique('articles', 'slug')->ignore($articleId),
            ],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'is_published' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'seo_keywords' => ['nullable', 'string', 'max:200'],
        ];

        if (! $articleId || $this->hasFile('cover_image')) {
            $rules['cover_image'] = ['nullable', 'image', 'max:2048'];
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
            'article_category_id.required' => '请选择文章分类',
            'article_category_id.exists' => '所选文章分类不存在',
            'title.required' => '文章标题不能为空',
            'title.string' => '文章标题必须是字符串',
            'title.max' => '文章标题不能超过200个字符',
            'slug.string' => '文章别名必须是字符串',
            'slug.max' => '文章别名不能超过200个字符',
            'slug.unique' => '该文章别名已被使用',
            'summary.string' => '文章摘要必须是字符串',
            'summary.max' => '文章摘要不能超过500个字符',
            'content.required' => '文章内容不能为空',
            'content.string' => '文章内容必须是字符串',
            'is_published.boolean' => '发布状态格式不正确',
            'cover_image.image' => '封面图片必须是图片格式',
            'cover_image.max' => '封面图片大小不能超过2MB',
            'seo_title.string' => 'SEO标题必须是字符串',
            'seo_title.max' => 'SEO标题不能超过200个字符',
            'seo_description.string' => 'SEO描述必须是字符串',
            'seo_description.max' => 'SEO描述不能超过500个字符',
            'seo_keywords.string' => 'SEO关键词必须是字符串',
            'seo_keywords.max' => 'SEO关键词不能超过200个字符',
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
            'article_category_id' => '文章分类',
            'title' => '文章标题',
            'slug' => '文章别名',
            'summary' => '文章摘要',
            'content' => '文章内容',
            'is_published' => '发布状态',
            'cover_image' => '封面图片',
            'seo_title' => 'SEO标题',
            'seo_description' => 'SEO描述',
            'seo_keywords' => 'SEO关键词',
        ];
    }
}
