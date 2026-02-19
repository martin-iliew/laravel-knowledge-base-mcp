<?php

namespace App\Policies;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;
use App\Models\User;

class KnowledgeResourcePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, KnowledgeResource $knowledgeResource): bool
    {
        $knowledgeItem = $this->resolveKnowledgeItem($knowledgeResource);

        if (! $knowledgeItem) {
            return false;
        }

        return (new KnowledgeItemPolicy)->view($user, $knowledgeItem);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, KnowledgeItem $knowledgeItem): bool
    {
        return (new KnowledgeItemPolicy)->update($user, $knowledgeItem);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, KnowledgeResource $knowledgeResource): bool
    {
        $knowledgeItem = $this->resolveKnowledgeItem($knowledgeResource);

        if (! $knowledgeItem) {
            return false;
        }

        return (new KnowledgeItemPolicy)->update($user, $knowledgeItem);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KnowledgeResource $knowledgeResource): bool
    {
        $knowledgeItem = $this->resolveKnowledgeItem($knowledgeResource);

        if (! $knowledgeItem) {
            return false;
        }

        return (new KnowledgeItemPolicy)->update($user, $knowledgeItem);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, KnowledgeResource $knowledgeResource): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, KnowledgeResource $knowledgeResource): bool
    {
        return false;
    }

    /**
     * Determine whether the user owns the parent knowledge item.
     */
    private function resolveKnowledgeItem(KnowledgeResource $knowledgeResource): ?KnowledgeItem
    {
        return $knowledgeResource->relationLoaded('item')
            ? $knowledgeResource->item
            : $knowledgeResource->item()->first();
    }
}
