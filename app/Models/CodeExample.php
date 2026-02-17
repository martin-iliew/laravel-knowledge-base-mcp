<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CodeExample extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'knowledge_item_id',
        'sort_order',
        'title',
        'language',
        'filename',
        'description',
        'code',
        'code_hash',
        'chunked_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunked_at' => 'datetime',
        ];
    }

    /**
     * Parent knowledge item this code example belongs to.
     *
     * @return BelongsTo
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    /**
     * Evidence chunks produced from this code example (polymorphic via source_type/source_id).
     *
     * @return HasMany
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'source_id')
            ->where('source_type', 'code');
    }
}
