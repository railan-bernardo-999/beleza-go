<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'name',
    'slug',
    'cover_image_url',
    'description',
    'invite_code',
    'owner_id',
    'is_private',
    'status',
    'approved_by',
    'approved_at',
    'moderation_reason'
    ])]
class Community extends Model
{
    protected $casts = [
        'is_private' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

     // ⭐ Relacionamento com membros (tabela pivô)
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_user')
                    ->withPivot(['role', 'joined_at', 'approved_at', 'invited_by'])
                    ->withTimestamps();
    }

    // Administradores da comunidade
    public function admins(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'admin');
    }

    // Membros pendentes (para comunidades privadas)
    public function pendingMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'pending');
    }

    // Dono da comunidade (relacionamento direto)
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // Verificar se usuário é membro
    public function isMember(int $userId): bool
    {
        return $this->members()->where('user_id', $userId)->exists();
    }

    // Verificar se usuário é admin
    public function isAdmin(int $userId): bool
    {
        return $this->members()
                    ->where('user_id', $userId)
                    ->wherePivot('role', 'admin')
                    ->exists();
    }
}
