<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-roleplay — explicit per-topic role-play flag. A row here OVERRIDES the
 * category default (is_rp = 1 forces a topic to be role-play, 0 forces regular);
 * no row = follow the topic's category (role-play categories make their topics
 * role-play).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rp_topics')) {
            Schema::create('rp_topics', function (Blueprint $t) {
                $t->unsignedBigInteger('topic_id')->primary();
                $t->boolean('is_rp')->default(true);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rp_topics');
    }
};
