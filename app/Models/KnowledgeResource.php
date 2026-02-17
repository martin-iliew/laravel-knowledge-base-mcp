<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeResource extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'knowledge_resources';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extracted_at' => 'datetime',
        ];
    }

    /**
     * Parent knowledge item this resource belongs to.
     *
     * @return BelongsTo
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    /**
     * Evidence chunks produced from this resource (polymorphic via source_type/source_id).
     *
     * @return HasMany
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'source_id')
            ->where('source_type', 'resource');
    }
}
