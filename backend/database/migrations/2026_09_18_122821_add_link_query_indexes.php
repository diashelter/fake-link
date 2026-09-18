<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement(
            'CREATE INDEX short_links_title_trgm_idx ON short_links USING GIN (lower(title) gin_trgm_ops)'
        );

        DB::statement(
            'CREATE INDEX short_links_owner_created_id_idx ON short_links (user_id, created_at DESC, id DESC)'
        );

        DB::statement(
            'CREATE INDEX short_links_owner_slug_prefix_idx ON short_links (user_id, slug varchar_pattern_ops)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS short_links_title_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS short_links_owner_created_id_idx');
        DB::statement('DROP INDEX IF EXISTS short_links_owner_slug_prefix_idx');
    }
};
