<?php

namespace App\Providers;

use App\Models\Community;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Definir permissão 'create' para Community
        Gate::define('create', function (User $user, $class) {
            // Verificar se o class é Community
            if ($class === Community::class) {
                // Lógica: quem pode criar comunidade?
                return $user->email_verified_at !== null
                    && $user->account_status === 'active'
                    && $user->is_only !== true;
            }

            return false;
        });

        // Ou específico para Community
        Gate::define('create-community', function (User $user) {
            return $user->email_verified_at !== null
                && $user->account_status === 'active';
        });

        // Para atualizar comunidade
        Gate::define('update', function (User $user, Community $community) {
            return $user->id === $community->owner_id
                || $community->isAdmin($user->id);
        });

        // Para deletar comunidade
        Gate::define('delete', function (User $user, Community $community) {
            return $user->id === $community->owner_id;
        });

        // Permissão para moderar comunidades
        Gate::define('moderate-communities', function (User $user) {
            return in_array($user->role, ['moderator', 'admin', 'super_admin']);
        });

        // Permissão para banir comunidades (nível mais alto)
        Gate::define('ban-communities', function (User $user) {
            return in_array($user->role, ['admin', 'super_admin']);
        });
    }
}
