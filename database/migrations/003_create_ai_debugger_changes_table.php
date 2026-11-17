<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_debugger_changes', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('session_id');
            $table->foreign('session_id')->references('id')->on('ai_debugger_sessions')->cascadeOnDelete();

            $table->string('action');
            $table->string('path', 1024);
            $table->string('target', 1024)->nullable();
            $table->text('reason')->nullable();
            $table->longText('previous_content')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('reverted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_debugger_changes');
    }
};
