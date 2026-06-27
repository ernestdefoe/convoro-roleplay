<?php

namespace Convoro\Ext\Roleplay\Models;

use Illuminate\Database\Eloquent\Model;

/** A character's combat sheet: HP + four attributes + an equipped hand. */
class RpSheet extends Model
{
    protected $table = 'rp_sheets';

    protected $guarded = [];

    protected $casts = [
        'attributes' => 'array',
        'equipped' => 'array',
        'max_hp' => 'integer',
        'hp' => 'integer',
    ];
}
