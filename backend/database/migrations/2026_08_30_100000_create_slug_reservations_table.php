<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slug_reservations', function (Blueprint $table): void {
            $table->string('slug', 48)->primary();
            $table->timestampTz('reserved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_reservations');
    }
};
