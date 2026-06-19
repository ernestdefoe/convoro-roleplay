<?php

namespace Convoro\Ext\Roleplay\Models;

use Illuminate\Database\Eloquent\Model;

class RpCharacter extends Model
{
    protected $table = 'rp_characters';

    protected $guarded = [];

    protected $casts = [
        'fields' => 'array',
        'last_active_at' => 'datetime',
    ];

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $parts = array_values(array_filter($parts));
        if (count($parts) === 0) {
            return '?';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
    }
}
