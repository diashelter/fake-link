<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->char('key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->binary('response_snapshot')->nullable();
            $table->string('key_id')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');

            $table->primary(['user_id', 'key_hash']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->index('expires_at', 'idempotency_keys_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
