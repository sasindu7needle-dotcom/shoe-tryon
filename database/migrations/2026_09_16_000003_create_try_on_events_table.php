<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Events are anonymous: no IP address, user agent or camera data is stored.
     */
    public function up(): void
    {
        Schema::create('try_on_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->uuid('session_id');
            $table->string('variant', 64)->nullable();
            $table->string('device', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'type']);
            $table->index(['product_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('try_on_events');
    }
};
