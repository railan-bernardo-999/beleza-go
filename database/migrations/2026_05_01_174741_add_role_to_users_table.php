<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Campo para definir o papel/nível do usuário
            $table->enum('role', ['user', 'moderator', 'admin', 'super_admin'])
                  ->default('user')
                  ->after('account_status');

            // Índice para buscas por role
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
