<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CodeExample extends Model
{
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

    protected function casts(): array
    {
        return [
            'chunked_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'source_id')
            ->where('source_type', 'code');
    }
}
