<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItem extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'content_markdown',
        'category',
        'tags',
        'status',
        'source',
        'published_at',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'content_hash',
        'chunked_at',
        'chunk_size',
        'chunk_overlap',
        'embedding_model',
        'embedding_dimensions',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'chunked_at' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function codeExamples(): HasMany
    {
        return $this->hasMany(CodeExample::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(KnowledgeResource::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published')
            ->where(function (Builder $q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }
}
