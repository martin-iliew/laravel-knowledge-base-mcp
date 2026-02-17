<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class KnowledgeChunk extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'knowledge_item_id',
        'source_type',
        'source_id',
        'chunk_index',
        'chunk_kind',
        'chunk_text',
        'meta',
        'chunk_hash',
        'token_count',
        'embedding',
        'embedding_model',
        'embedding_dimensions',
        'embedded_at',
        'embedding_attempts',
        'embedding_error',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'embedding' => 'array',
            'embedded_at' => 'datetime',
        ];
    }

    /**
     * Parent knowledge item this chunk belongs to.
     *
     * @return BelongsTo
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    /**
     * Polymorphic source of this chunk (e.g. KnowledgeItem, CodeExample, KnowledgeResource),
     * referenced via source_type + source_id.
     *
     * @return MorphTo
     */
    public function source(): MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id');
    }
}
