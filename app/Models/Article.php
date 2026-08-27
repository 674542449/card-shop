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

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
