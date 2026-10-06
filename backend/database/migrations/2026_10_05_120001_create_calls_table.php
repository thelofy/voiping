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
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 40);
            $table->string('extension', 20)->nullable();
            $table->string('direction', 16);
            $table->string('status', 20);
            $table->string('unique_id', 64)->nullable()->unique();
            $table->dateTime('started_at');
            $table->dateTime('answered_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->timestamps();

            $table->index(['phone', 'started_at']);
            $table->index(['extension', 'started_at']);
            $table->index(['direction', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index(['user_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
