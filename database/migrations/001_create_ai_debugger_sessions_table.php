<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_debugger_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->json('options')->nullable();
            $table->text('instructions')->nullable();

            $table->string('backup_type')->nullable();
            $table->string('backup_reference')->nullable();
            $table->string('backup_status')->default('none');
            $table->unsignedBigInteger('backup_size')->nullable();

            $table->longText('summary')->nullable();
            $table->json('report')->nullable();
            $table->text('error')->nullable();

            $table->string('model')->nullable();
            $table->unsignedInteger('iterations')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_debugger_sessions');
    }
};
