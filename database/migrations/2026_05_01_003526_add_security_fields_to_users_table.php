<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Campos de verificação de email (já existe email_verified_at)
            $table->string('verification_token', 100)->nullable()->unique()->after('remember_token');

            // Campos de segurança
            $table->string('ip_registered', 45)->nullable()->after('email_verified_at');
            $table->string('user_agent_registered')->nullable()->after('ip_registered');
            $table->string('last_login_ip', 45)->nullable()->after('user_agent_registered');
            $table->timestamp('last_login_at')->nullable()->after('last_login_ip');

            // Controle de status e bloqueio
            $table->enum('account_status', ['pending', 'active', 'locked', 'banned', 'deleted'])
                ->default('pending')->after('bio'); // Colocar depois de 'bio'
            $table->integer('failed_login_attempts')->default(0)->after('account_status');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');

            // Índices para performance
            $table->index('account_status');
            $table->index('verification_token');
            $table->index('failed_login_attempts');
            $table->index('is_only');
            $table->index('birthday');
            $table->index('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'verification_token',
                'ip_registered',
                'user_agent_registered',
                'last_login_ip',
                'last_login_at',
                'account_status',
                'failed_login_attempts',
                'locked_until'
            ]);

            $table->dropIndex(['account_status']);
            $table->dropIndex(['verification_token']);
            $table->dropIndex(['failed_login_attempts']);
            $table->dropIndex(['is_only']);
            $table->dropIndex(['birthday']);
            $table->dropIndex(['country']);
        });
    }
};
