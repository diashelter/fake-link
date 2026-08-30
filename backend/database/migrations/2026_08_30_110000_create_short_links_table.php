<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('slug', 48)->unique();
            $table->text('slug_source');
            $table->string('title', 160)->nullable();
            $table->boolean('is_enabled');
            $table->timestampTz('blocked_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->bigInteger('version');
            $table->timestampsTz();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('slug')
                ->references('slug')
                ->on('slug_reservations')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->index('user_id', 'short_links_user_id_index');
        });

        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE short_links ADD CONSTRAINT short_links_slug_source_check CHECK (slug_source IN ('automatic','custom'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
