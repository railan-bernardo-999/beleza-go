<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'username',
    'email',
    'password',
    'phone',
    'avatar_base_url',
    'birthday',
    'country',
    'state',
    'city',
    'bio',
    'is_only',
    'email_verified_at',
    'verification_token',
    'ip_registered',
    'user_agent_registered',
    'last_login_ip',
    'last_login_at',
    'account_status',
    'failed_login_attempts',
    'locked_until',
    'role'
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // 'password' => 'hashed',
        ];
    }

    // Comunidades que o usuário participa
    public function communities(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'community_user')
            ->withPivot(['role', 'joined_at', 'approved_at', 'invited_by'])
            ->withTimestamps();
    }

    // Comunidades que o usuário é admin
    public function adminCommunities(): BelongsToMany
    {
        return $this->communities()->wherePivot('role', 'admin');
    }

    // Comunidades que o usuário é dono
    public function ownedCommunities()
    {
        return $this->hasMany(Community::class, 'owner_id');
    }
}
