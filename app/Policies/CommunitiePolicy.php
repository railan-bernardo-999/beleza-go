<?php

namespace App\Policies;

use App\Models\Communitie;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CommunitiePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Communitie $communitie): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Só usuário verificado pode criar
        if (!$user->hasVerifiedEmail()) {
            return false;
        }

        // Limite: máximo 3 comunidades por usuário
        if ($user->communities()->count() >= 3) {
            return false;
        }

        // Usuário banido não cria
        if ($user->status === 'banned' || $user->status === 'suspended') {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Communitie $communitie): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Communitie $communitie): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Communitie $communitie): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Communitie $communitie): bool
    {
        return false;
    }

}
