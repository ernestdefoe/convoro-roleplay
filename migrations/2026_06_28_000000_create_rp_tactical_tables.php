<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-roleplay — tactical card game: player-built cards, per-character combat
 * sheets, and turn-based encounters run inside a role-play topic.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rp_cards')) {
            Schema::create('rp_cards', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('name');
                $t->string('icon')->nullable();
                $t->string('type')->default('ability');      // ability | item | spell | enemy
                $t->text('description')->nullable();
                $t->string('attack_expr', 40)->nullable();    // e.g. 1d20+3
                $t->string('damage_expr', 40)->nullable();    // e.g. 2d6+1
                $t->unsignedSmallInteger('defense')->nullable();
                $t->unsignedSmallInteger('hp')->nullable();
                $t->unsignedSmallInteger('cost')->default(0);
                $t->boolean('is_public')->default(false);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('rp_sheets')) {
            Schema::create('rp_sheets', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('character_id')->unique();
                $t->unsignedSmallInteger('max_hp')->default(20);
                $t->smallInteger('hp')->default(20);
                $t->json('attributes')->nullable();           // { might, agility, wits, heart }
                $t->json('equipped')->nullable();             // [ card_id, ... ]
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('rp_encounters')) {
            Schema::create('rp_encounters', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('topic_id')->index();
                $t->unsignedBigInteger('gm_user_id')->index();
                $t->string('name')->nullable();
                $t->string('status')->default('setup');       // setup | active | ended
                $t->unsignedSmallInteger('round')->default(1);
                $t->unsignedSmallInteger('turn_index')->default(0);
                $t->json('order')->nullable();                // [ combatant_id, ... ]
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('rp_combatants')) {
            Schema::create('rp_combatants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('encounter_id')->index();
                $t->unsignedBigInteger('character_id')->nullable();
                $t->unsignedBigInteger('card_id')->nullable();
                $t->string('name');
                $t->unsignedSmallInteger('max_hp')->default(10);
                $t->smallInteger('hp')->default(10);
                $t->smallInteger('initiative')->default(0);
                $t->string('team')->default('party');         // party | foe
                $t->boolean('is_down')->default(false);
                $t->json('meta')->nullable();                 // { defense, agility }
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rp_combatants');
        Schema::dropIfExists('rp_encounters');
        Schema::dropIfExists('rp_sheets');
        Schema::dropIfExists('rp_cards');
    }
};
