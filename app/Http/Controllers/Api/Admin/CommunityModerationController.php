<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CommunityModerationController extends Controller
{
    use ApiResponse;

    /**
     * Listar comunidades por status (apenas moderadores)
     */
    public function index(Request $request)
    {
        // Verificar permissão
        if (!auth()->user()->can('moderate-communities')) {
            return $this->error('Você não tem permissão para acessar esta funcionalidade', 403);
        }

        $request->validate([
            'status' => 'nullable|in:pending,active,suspended,banned,archived',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $query = Community::with(['owner' => function($q) {
            $q->select('id', 'name', 'email');
        }]);

        // Filtrar por status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Buscar por nome ou slug
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        $communities = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->success([
            'communities' => $communities->items(),
            'current_page' => $communities->currentPage(),
            'last_page' => $communities->lastPage(),
            'per_page' => $communities->perPage(),
            'total' => $communities->total(),
            'status_filter' => $request->get('status', 'all')
        ], 'Lista de comunidades');
    }

    /**
     * Obter estatísticas de moderação
     */
    public function stats()
    {
        if (!auth()->user()->can('moderate-communities')) {
            return $this->error('Você não tem permissão para acessar esta funcionalidade', 403);
        }

        $stats = [
            'pending' => Community::where('status', 'pending')->count(),
            'active' => Community::where('status', 'active')->count(),
            'suspended' => Community::where('status', 'suspended')->count(),
            'banned' => Community::where('status', 'banned')->count(),
            'archived' => Community::where('status', 'archived')->count(),
            'total' => Community::count(),
            'pending_percentage' => 0,
        ];

        if ($stats['total'] > 0) {
            $stats['pending_percentage'] = round(($stats['pending'] / $stats['total']) * 100, 2);
        }

        return $this->success([
            'stats' => $stats,
            'last_updated' => now()->toIso8601String()
        ], 'Estatísticas de moderação');
    }

    /**
     * Aprovar comunidade (pending -> active)
     */
    public function approve(Request $request, Community $community)
    {
        // Verificar permissão
        if (!auth()->user()->can('moderate-communities')) {
            return $this->error('Você não tem permissão para aprovar comunidades', 403);
        }

        // Verificar se está pendente
        if ($community->status !== 'pending') {
            return $this->error(
                "Comunidade não pode ser aprovada. Status atual: {$community->status}",
                422
            );
        }

        try {
            $oldStatus = $community->status;

            $community->update([
                'status' => 'active',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'moderation_reason' => null
            ]);

            // Log de auditoria
            Log::info('Comunidade aprovada', [
                'community_id' => $community->id,
                'community_name' => $community->name,
                'approved_by' => auth()->id(),
                'approved_by_email' => auth()->user()->email,
                'approved_by_role' => auth()->user()->role,
                'owner_id' => $community->owner_id,
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'ip' => $request->ip()
            ]);

            return $this->success([
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'status' => $community->status,
                    'approved_at' => $community->approved_at,
                    'approved_by' => $community->approved_by
                ],
                'message' => 'Comunidade aprovada com sucesso e agora está ativa'
            ], 'Comunidade aprovada');

        } catch (\Exception $e) {
            Log::error('Erro ao aprovar comunidade', [
                'community_id' => $community->id,
                'error' => $e->getMessage(),
                'approved_by' => auth()->id()
            ]);

            return $this->error('Erro interno ao aprovar comunidade', 500);
        }
    }

    /**
     * Suspender comunidade (active -> suspended)
     */
    public function suspend(Request $request, Community $community)
    {
        if (!auth()->user()->can('moderate-communities')) {
            return $this->error('Você não tem permissão para suspender comunidades', 403);
        }

        $request->validate([
            'reason' => 'required|string|min:10|max:500'
        ]);

        if ($community->status !== 'active') {
            return $this->error(
                "Apenas comunidades ativas podem ser suspensas. Status atual: {$community->status}",
                422
            );
        }

        try {
            $oldStatus = $community->status;

            $community->update([
                'status' => 'suspended',
                'moderation_reason' => $request->reason,
                'approved_by' => auth()->id(),
                'approved_at' => now()
            ]);

            Log::warning('Comunidade suspensa', [
                'community_id' => $community->id,
                'community_name' => $community->name,
                'suspended_by' => auth()->id(),
                'suspended_by_email' => auth()->user()->email,
                'reason' => $request->reason,
                'old_status' => $oldStatus,
                'ip' => $request->ip()
            ]);

            return $this->success([
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'status' => $community->status,
                    'moderation_reason' => $community->moderation_reason
                ],
                'message' => 'Comunidade suspensa temporariamente'
            ], 'Comunidade suspensa');

        } catch (\Exception $e) {
            Log::error('Erro ao suspender comunidade', [
                'community_id' => $community->id,
                'error' => $e->getMessage()
            ]);

            return $this->error('Erro interno ao suspender comunidade', 500);
        }
    }

    /**
     * Banir comunidade (active/suspended -> banned)
     */
    public function ban(Request $request, Community $community)
    {
        if (!auth()->user()->can('moderate-communities')) {
            return $this->error('Você não tem permissão para banir comunidades', 403);
        }

        $request->validate([
            'reason' => 'required|string|min:10|max:500'
        ]);

        if (!in_array($community->status, ['active', 'suspended'])) {
            return $this->error(
                "Comunidade não pode ser banida. Status atual: {$community->status}",
                422
            );
        }

        try {
            $oldStatus = $community->status;

            $community->update([
                'status' => 'banned',
                'moderation_reason' => $request->reason,
                'approved_by' => auth()->id(),
                'approved_at' => now()
            ]);

            Log::critical('Comunidade banida', [
                'community_id' => $community->id,
                'community_name' => $community->name,
                'banned_by' => auth()->id(),
                'banned_by_email' => auth()->user()->email,
                'reason' => $request->reason,
                'old_status' => $oldStatus,
                'ip' => $request->ip()
            ]);

            return $this->success([
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'status' => $community->status,
                    'moderation_reason' => $community->moderation_reason
                ],
                'message' => 'Comunidade banida permanentemente'
            ], 'Comunidade banida');

        } catch (\Exception $e) {
            Log::error('Erro ao banir comunidade', [
                'community_id' => $community->id,
                'error' => $e->getMessage()
            ]);

            return $this->error('Erro interno ao banir comunidade', 500);
        }
    }

    /**
     * Reativar comunidade (suspended -> active)
     */
    public function reactivate(Request $request, Community $community)
    {
        if (!auth()->user()->can('moderate-communities')) {
            return $this->error('Você não tem permissão para reativar comunidades', 403);
        }

        if ($community->status !== 'suspended') {
            return $this->error(
                "Apenas comunidades suspensas podem ser reativadas. Status atual: {$community->status}",
                422
            );
        }

        try {
            $oldStatus = $community->status;

            $community->update([
                'status' => 'active',
                'moderation_reason' => null,
                'approved_by' => auth()->id(),
                'approved_at' => now()
            ]);

            Log::info('Comunidade reativada', [
                'community_id' => $community->id,
                'community_name' => $community->name,
                'reactivated_by' => auth()->id(),
                'reactivated_by_email' => auth()->user()->email,
                'old_status' => $oldStatus,
                'ip' => $request->ip()
            ]);

            return $this->success([
                'community' => [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'status' => $community->status
                ],
                'message' => 'Comunidade reativada com sucesso'
            ], 'Comunidade reativada');

        } catch (\Exception $e) {
            Log::error('Erro ao reativar comunidade', [
                'community_id' => $community->id,
                'error' => $e->getMessage()
            ]);

            return $this->error('Erro interno ao reativar comunidade', 500);
        }
    }

    /**
     * Arquive comunidade (active -> archived) - apenas dono ou moderador
     */
    public function archive(Community $community)
    {
        $user = auth()->user();

        // Apenas dono da comunidade OU moderador pode arquivar
        if ($community->owner_id !== $user->id && !$user->can('moderate-communities')) {
            return $this->error('Você não tem permissão para arquivar esta comunidade', 403);
        }

        if ($community->status !== 'active') {
            return $this->error(
                "Apenas comunidades ativas podem ser arquivadas. Status atual: {$community->status}",
                422
            );
        }

        $community->update([
            'status' => 'archived',
            'approved_by' => $user->id,
            'approved_at' => now()
        ]);

        Log::info('Comunidade arquivada', [
            'community_id' => $community->id,
            'community_name' => $community->name,
            'archived_by' => $user->id,
            'archived_by_role' => $user->role
        ]);

        return $this->success(null, 'Comunidade arquivada com sucesso');
    }

    /**
     * Obter detalhes de uma comunidade para moderação
     */
    public function show(Community $community)
    {
        if (!auth()->user()->can('moderate-communities')) {
            return $this->error('Você não tem permissão para acessar esta funcionalidade', 403);
        }

        $community->load(['owner' => function($q) {
            $q->select('id', 'name', 'email', 'username', 'created_at');
        }]);

        return $this->success([
            'community' => $community,
            'moderation_history' => [
                'approved_by' => $community->approved_by ? [
                    'id' => $community->approved_by,
                    'name' => $community->approvedBy ? $community->approvedBy->name : null
                ] : null,
                'approved_at' => $community->approved_at,
                'moderation_reason' => $community->moderation_reason
            ]
        ], 'Detalhes da comunidade');
    }
}
