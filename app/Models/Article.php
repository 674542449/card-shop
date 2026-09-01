<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    protected $table = 'articles';

    protected $fillable = [
        'article_category_id',
        'title',
        'slug',
        'cover_image',
        'summary',
        'content',
        'is_published',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'views' => 'integer',
        ];
    }

    public function articleCategory(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * 最新在前。
     *
     * ->orderByDesc('id') 是必需的 tiebreaker，不是可选的美化：created_at 是秒级
     * 精度，批量导入、脚本迁移、或者两个人在同一秒发布，都会产生并列，而 PostgreSQL
     * 对并列行的返回顺序不作保证。分页时的后果是同一条既可能在两页里重复出现，也
     * 可能一页都不出现——实测 63 篇同秒文章翻 7 页：5 篇重复、5 篇彻底消失。
     * Product / Category / ArticleCategory 的 ordered() 早就这么写了，唯独真正被
     * paginate() 用到的这两个 recent() 漏了。
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
