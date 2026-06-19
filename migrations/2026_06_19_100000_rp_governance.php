<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-roleplay governance — a face/canon "claim" per character and the set
 * of in-character boards (capturing only links posts made in an IC category).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rp_characters') && ! Schema::hasColumn('rp_characters', 'claim')) {
            Schema::table('rp_characters', function (Blueprint $t) {
                $t->string('claim')->nullable()->after('bio'); // face/canon claim
            });
        }

        // Categories flagged in-character. Empty table = every board is IC
        // (so the feature works before it is configured).
        if (! Schema::hasTable('rp_ic_categories')) {
            Schema::create('rp_ic_categories', function (Blueprint $t) {
                $t->unsignedBigInteger('category_id')->primary();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rp_ic_categories');
        if (Schema::hasTable('rp_characters') && Schema::hasColumn('rp_characters', 'claim')) {
            Schema::table('rp_characters', function (Blueprint $t) {
                $t->dropColumn('claim');
            });
        }
    }
};
