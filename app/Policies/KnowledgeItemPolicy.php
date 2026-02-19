<?php

namespace App\Policies;

use App\Models\KnowledgeItem;
use App\Models\User;

class KnowledgeItemPolicy
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
    public function view(User $user, KnowledgeItem $knowledgeItem): bool
    {
        return $this->hasReadAccess($user, $knowledgeItem);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->exists;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, KnowledgeItem $knowledgeItem): bool
    {
        return $this->hasEditAccess($user, $knowledgeItem);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KnowledgeItem $knowledgeItem): bool
    {
        return (int) $knowledgeItem->created_by === (int) $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, KnowledgeItem $knowledgeItem): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, KnowledgeItem $knowledgeItem): bool
    {
        return false;
    }

    /**
     * Determine whether the user can manage sharing for the model.
     */
    public function manageAccess(User $user, KnowledgeItem $knowledgeItem): bool
    {
        return (int) $knowledgeItem->created_by === (int) $user->id;
    }

    /**
     * Determine whether user has read access (owner, viewer, or editor).
     */
    private function hasReadAccess(User $user, KnowledgeItem $knowledgeItem): bool
    {
        if ((int) $knowledgeItem->created_by === (int) $user->id) {
            return true;
        }

        return $user->hasGlobalKnowledgeReadAccess();
    }

    /**
     * Determine whether user has edit access (owner or editor).
     */
    private function hasEditAccess(User $user, KnowledgeItem $knowledgeItem): bool
    {
        if ((int) $knowledgeItem->created_by === (int) $user->id) {
            return true;
        }

        return $user->hasGlobalKnowledgeEditAccess();
    }
}
