<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeResource extends Model
{
    protected $table = 'knowledge_resources';

    protected $fillable = [
        'knowledge_item_id',
        'sort_order',
        'type',
        'label',
        'url',
        'storage_path',
        'mime',
        'size',
        'extracted_text',
        'extracted_hash',
        'extracted_at',
        'extract_attempts',
        'extract_error',
    ];

    protected function casts(): array
    {
        return [
            'extracted_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'source_id')
            ->where('source_type', 'resource');
    }
}
