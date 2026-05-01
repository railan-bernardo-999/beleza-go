<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            // Quem aprovou/reprovou a comunidade
            $table->foreignId('approved_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();

            // Data da aprovação/reprovação
            $table->timestamp('approved_at')
                ->nullable()
                ->after('approved_by');

            // Motivo da reprovação, suspensão ou banimento
            $table->text('moderation_reason')
                ->nullable()
                ->after('approved_at');

            // Índices para buscas
            $table->index('status');
            $table->index('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at', 'moderation_reason']);
        });
    }
};
