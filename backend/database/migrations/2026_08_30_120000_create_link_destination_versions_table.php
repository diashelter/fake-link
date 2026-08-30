<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_destination_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('short_link_id');
            $table->text('destination_url');
            $table->string('key_id');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();

            $table->foreign('short_link_id')
                ->references('id')
                ->on('short_links')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement(
            'ALTER TABLE link_destination_versions ADD CONSTRAINT ldv_valid_to_check CHECK (valid_to IS NULL OR valid_to > valid_from)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX ldv_one_open_version ON link_destination_versions (short_link_id) WHERE valid_to IS NULL'
        );

        DB::statement(
            'CREATE INDEX ldv_history_idx ON link_destination_versions (short_link_id, valid_from DESC)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('link_destination_versions');
    }
};
