<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-roleplay dice — an immutable log of every roll a post made. Rolls are
 * evaluated once, server-side, at post creation; this table is the provable
 * record (expression, total, and the individual dice).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rp_rolls')) {
            Schema::create('rp_rolls', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('post_id')->index();
                $t->unsignedSmallInteger('idx')->default(0); // nth roll within the post
                $t->string('expr', 40);
                $t->integer('total');
                $t->json('detail')->nullable();              // the individual dice
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rp_rolls');
    }
};
