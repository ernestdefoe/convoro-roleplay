<?php

namespace Convoro\Ext\Roleplay\Models;

use Illuminate\Database\Eloquent\Model;

/** A player-built card: an ability, item, spell or enemy with dice formulas. */
class RpCard extends Model
{
    protected $table = 'rp_cards';

    protected $guarded = [];

    protected $casts = [
        'is_public' => 'boolean',
        'defense' => 'integer',
        'hp' => 'integer',
        'cost' => 'integer',
    ];
}
