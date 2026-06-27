<?php

namespace Convoro\Ext\Roleplay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A turn-based combat encounter run inside a role-play topic. */
class RpEncounter extends Model
{
    protected $table = 'rp_encounters';

    protected $guarded = [];

    protected $casts = [
        'order' => 'array',
        'round' => 'integer',
        'turn_index' => 'integer',
    ];

    public function combatants(): HasMany
    {
        return $this->hasMany(RpCombatant::class, 'encounter_id');
    }
}
