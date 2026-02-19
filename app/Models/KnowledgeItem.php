<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'chunked_at' => 'datetime',
        ];
    }

    /**
     * Chunks belonging to this knowledge item.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    /**
     * Code examples attached to this knowledge item.
     */
    public function codeExamples(): HasMany
    {
        return $this->hasMany(CodeExample::class);
    }

    /**
     * Resources (links/files) attached to this knowledge item.
     */
    public function resources(): HasMany
    {
        return $this->hasMany(KnowledgeResource::class);
    }

    /**
     * Owning author account that created this item.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Reviewer account that approved this item, when present.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scope items that are published and visible.
     * Treats null published_at as immediately visible.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(function (Builder $query) {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    /**
     * Scope items visible to the given user (owned + account-shared).
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $accessibleQuery) use ($user): void {
            $accessibleQuery
                ->where('created_by', $user->id)
                ->orWhereIn(
                    'created_by',
                    $user->knowledgeAccessReceived()->select('owner_user_id')
                );
        });
    }
}
