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
        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('label', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->string('asterisk_linkedid', 64)->nullable();
            $table->string('asterisk_uniqueid', 64)->nullable();
            $table->string('extension_number', 20)->nullable();
            $table->foreignId('extension_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->string('caller_number_raw', 40)->nullable();
            $table->string('caller_number_normalized', 20)->default('');
            $table->string('caller_name', 120)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->unique(['asterisk_linkedid', 'extension_number']);
            $table->index(['caller_number_normalized', 'started_at']);
            $table->index(['extension_id', 'started_at']);
        });

        Schema::create('call_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_id', 36)->unique();
            $table->foreignId('call_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->json('payload');
            $table->boolean('applied');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_events');

        Schema::table('calls', function (Blueprint $table) {
            $table->dropUnique(['asterisk_linkedid', 'extension_number']);
            $table->dropIndex(['caller_number_normalized', 'started_at']);
            $table->dropIndex(['extension_id', 'started_at']);
            $table->dropConstrainedForeignId('extension_id');
            $table->dropColumn([
                'asterisk_linkedid',
                'asterisk_uniqueid',
                'extension_number',
                'caller_number_raw',
                'caller_number_normalized',
                'caller_name',
                'duration_seconds',
            ]);
        });

        Schema::dropIfExists('extensions');
    }
};
