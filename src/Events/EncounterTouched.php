<?php

namespace Convoro\Ext\Roleplay\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever an encounter changes so every viewer's combat tracker updates
 * live. Public channel — encounter state is visible to anyone who can see the
 * topic (the same data the API already returns). No-op if realtime is disabled.
 */
class EncounterTouched implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $topicId, public array $encounter)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('rp.encounter.' . $this->topicId)];
    }

    public function broadcastAs(): string
    {
        return 'EncounterTouched';
    }

    public function broadcastWith(): array
    {
        return ['encounter' => $this->encounter, 'topicId' => $this->topicId];
    }
}
