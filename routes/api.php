<?php

use App\Http\Controllers\Api\Admin\CommunityModerationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommunityController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Rotas públicas
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
    Route::get('/ping', function () {
        return response()->json(['message' => 'API online', 'timestamp' => now()]);
    });

    // Rotas protegidas
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);

        // Rotas de comunidades
        Route::prefix('/communities')->group(function () {
            // Criar comunidade
            Route::post('/create', [CommunityController::class, 'store']);
            // Entrar via código de convite
            Route::post('/join/{inviteCode}', [CommunityController::class, 'joinByInvite']);
            // Regenerar código de convite
            Route::put('/{communityId}/regenerate-invite', [CommunityController::class, 'regenerateInviteCode']);

            // Rotas de moderação (apenas moderadores/admin/super_admin)
            Route::prefix('/moderation')->group(function () {
                // Estatísticas
                Route::get('/stats', [CommunityModerationController::class, 'stats']);

                // Listar comunidades por status
                Route::get('/communities', [CommunityModerationController::class, 'index']);

                // Detalhes de uma comunidade específica
                Route::get('/communities/{community}', [CommunityModerationController::class, 'show']);

                // Ações de moderação
                Route::post('/{community}/approve', [CommunityModerationController::class, 'approve']);
                Route::post('/{community}/suspend', [CommunityModerationController::class, 'suspend']);
                Route::post('/{community}/ban', [CommunityModerationController::class, 'ban']);
                Route::post('/{community}/reactivate', [CommunityModerationController::class, 'reactivate']);
                Route::post('/{community}/archive', [CommunityModerationController::class, 'archive']);
            });
        });
    });
});
