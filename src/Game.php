<?php

namespace Convoro\Ext\Roleplay;

use Convoro\Ext\Roleplay\Models\RpCard;
use Convoro\Ext\Roleplay\Models\RpCombatant;
use Convoro\Ext\Roleplay\Models\RpEncounter;

/**
 * The tactical combat engine. Pure functions over the encounter/combatant models:
 * dice resolution (same rules as the inline [[XdY+N]] roller), initiative, and
 * card resolution (attack vs defense, crit-on-natural-max → double damage).
 */
class Game
{
    /** Parse + roll "2d6+1" → ['expr','dice'=>[...],'mod','total'] | null. */
    public static function roll(?string $expr): ?array
    {
        $expr = strtolower(preg_replace('/\s+/', '', (string) $expr));
        if ($expr === '' || ! preg_match('/^(\d*)d(\d+)([+-]\d+)?$/', $expr, $p)) {
            return null;
        }
        $count = $p[1] === '' ? 1 : (int) $p[1];
        $sides = (int) $p[2];
        $mod = ($p[3] ?? '') !== '' ? (int) $p[3] : 0;
        if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
            return null;
        }
        $dice = [];
        for ($i = 0; $i < $count; $i++) {
            $dice[] = random_int(1, $sides);
        }

        return ['expr' => $expr, 'dice' => $dice, 'mod' => $mod, 'total' => array_sum($dice) + $mod];
    }

    /**
     * Resolve a card played by $actor against $target. Rolls the attack (crit on a
     * natural max die, d4+), compares to the target's defense, then rolls + applies
     * damage (doubled on a crit). Persists the target's new HP. Returns the result.
     */
    public static function play(RpCombatant $actor, RpCard $card, ?RpCombatant $target): array
    {
        $res = [
            'attack' => null, 'hit' => true, 'crit' => false,
            'damage' => null, 'amount' => 0,
            'targetHp' => $target ? (int) $target->hp : null,
            'targetMaxHp' => $target ? (int) $target->max_hp : null,
            'down' => false,
        ];

        if ($atk = self::roll($card->attack_expr)) {
            $res['attack'] = $atk;
            $sides = (int) (explode('d', $atk['expr'])[1] ?? 0);
            $res['crit'] = $sides >= 4 && in_array($sides, $atk['dice'], true);
            if ($target) {
                $defense = (int) (($target->meta['defense'] ?? 0));
                $res['hit'] = $defense === 0 || $atk['total'] >= $defense;
            }
        }

        if ($res['hit'] && $card->damage_expr && ($dmg = self::roll($card->damage_expr))) {
            $amount = $dmg['total'] * ($res['crit'] ? 2 : 1);
            $res['damage'] = $dmg;
            $res['amount'] = $amount;
            if ($target) {
                $target->hp = max(0, (int) $target->hp - $amount);
                $target->is_down = $target->hp <= 0;
                $target->save();
                $res['targetHp'] = (int) $target->hp;
                $res['down'] = (bool) $target->is_down;
            }
        }

        return $res;
    }

    /** Roll initiative (1d20 + agility) for every combatant and begin round 1. */
    public static function start(RpEncounter $enc): void
    {
        $combatants = $enc->combatants()->get();
        foreach ($combatants as $c) {
            $init = self::roll('1d20');
            $c->initiative = ($init['total'] ?? 0) + (int) (($c->meta['agility'] ?? 0));
            $c->save();
        }
        $enc->order = $combatants->sortByDesc('initiative')->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
        $enc->status = 'active';
        $enc->round = 1;
        $enc->turn_index = 0;
        $enc->save();
    }

    /** Advance to the next combatant in initiative order, skipping the downed. */
    public static function nextTurn(RpEncounter $enc): void
    {
        $order = $enc->order ?: [];
        if (! $order) {
            return;
        }
        $down = RpCombatant::whereIn('id', $order)->where('is_down', true)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $ti = (int) $enc->turn_index;
        $round = (int) $enc->round;
        for ($i = 0; $i < count($order); $i++) {
            $ti++;
            if ($ti >= count($order)) {
                $ti = 0;
                $round++;
            }
            if (! in_array((int) $order[$ti], $down, true)) {
                break;
            }
        }
        $enc->turn_index = $ti;
        $enc->round = $round;
        $enc->save();
    }

    /** The id of the combatant whose turn it is, or null. */
    public static function activeId(RpEncounter $enc): ?int
    {
        $order = $enc->order ?: [];
        $ti = (int) $enc->turn_index;

        return isset($order[$ti]) ? (int) $order[$ti] : null;
    }
}
