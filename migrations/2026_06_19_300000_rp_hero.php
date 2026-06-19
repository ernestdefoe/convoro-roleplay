<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-roleplay — a wide hero/cover image per character (the avatar already
 * lives in rp_characters.avatar_path).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rp_characters') && ! Schema::hasColumn('rp_characters', 'hero_path')) {
            Schema::table('rp_characters', function (Blueprint $t) {
                $t->string('hero_path')->nullable()->after('avatar_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rp_characters') && Schema::hasColumn('rp_characters', 'hero_path')) {
            Schema::table('rp_characters', function (Blueprint $t) {
                $t->dropColumn('hero_path');
            });
        }
    }
};
