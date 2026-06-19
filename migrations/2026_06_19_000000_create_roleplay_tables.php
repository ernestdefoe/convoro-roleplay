<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-roleplay — self-owned tables (created + dropped by this extension).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rp_characters')) {
            Schema::create('rp_characters', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('name');
                $t->string('slug')->unique();
                $t->string('avatar_path')->nullable();
                $t->unsignedTinyInteger('color')->nullable(); // av-g1..6; derived when null
                $t->text('bio')->nullable();
                $t->json('fields')->nullable();               // admin-defined profile fields
                $t->string('status')->default('approved');     // pending | approved | archived
                $t->unsignedBigInteger('faction_id')->nullable()->index();
                $t->unsignedInteger('post_count')->default(0);
                $t->timestamp('last_active_at')->nullable();
                $t->timestamps();
            });
        }

        // One character per post — the identity a post was authored as.
        if (! Schema::hasTable('rp_post_character')) {
            Schema::create('rp_post_character', function (Blueprint $t) {
                $t->unsignedBigInteger('post_id')->primary();
                $t->unsignedBigInteger('character_id')->index();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rp_post_character');
        Schema::dropIfExists('rp_characters');
    }
};
