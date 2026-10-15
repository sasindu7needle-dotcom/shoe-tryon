<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('model_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->json('draft');
            $table->json('source_paths');
            $table->json('image_tokens')->nullable();
            $table->json('options');
            $table->string('status')->default('draft')->index();
            $table->string('provider_task_id')->nullable()->unique();
            $table->unsignedTinyInteger('provider_progress')->nullable();
            $table->string('model_path')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('read_failures')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_generations');
    }
};
