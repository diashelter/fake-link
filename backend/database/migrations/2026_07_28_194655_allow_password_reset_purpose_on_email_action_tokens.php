<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE email_action_tokens DROP CONSTRAINT email_action_tokens_purpose_check');
        DB::statement("ALTER TABLE email_action_tokens ADD CONSTRAINT email_action_tokens_purpose_check CHECK (purpose IN ('email_verification', 'password_reset'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE email_action_tokens DROP CONSTRAINT email_action_tokens_purpose_check');
        DB::statement("ALTER TABLE email_action_tokens ADD CONSTRAINT email_action_tokens_purpose_check CHECK (purpose IN ('email_verification'))");
    }
};
