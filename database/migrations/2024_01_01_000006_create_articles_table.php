<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_category_id')->constrained('article_categories')->cascadeOnDelete();
            $table->string('title', 300);
            $table->string('slug', 300)->unique();
            $table->string('cover_image', 500)->nullable();
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->boolean('is_published')->default(false);
            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('seo_keywords', 200)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->index(['article_category_id', 'is_published', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
