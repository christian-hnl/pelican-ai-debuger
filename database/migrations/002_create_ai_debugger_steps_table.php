<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_debugger_steps', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('session_id');
            $table->foreign('session_id')->references('id')->on('ai_debugger_sessions')->cascadeOnDelete();

            $table->string('type');
            $table->string('title');
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_debugger_steps');
    }
};
