<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('community_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Campos extras
            $table->enum('role', ['admin', 'moderator', 'member', 'pending'])->default('member');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('approved_at')->nullable(); // Para comunidades privadas
            $table->string('invited_by')->nullable(); // Quem convidou

            $table->unique(['community_id', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_user');
    }
};
