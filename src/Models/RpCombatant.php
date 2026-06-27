<?php

namespace Convoro\Ext\Roleplay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One participant in an encounter — a joined character or a spawned foe. */
class RpCombatant extends Model
{
    protected $table = 'rp_combatants';

    protected $guarded = [];

    protected $casts = [
        'is_down' => 'boolean',
        'meta' => 'array',
        'max_hp' => 'integer',
        'hp' => 'integer',
        'initiative' => 'integer',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(RpCharacter::class, 'character_id');
    }
}
