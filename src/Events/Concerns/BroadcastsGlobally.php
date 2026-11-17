<?php

namespace Ikechukwu11\NativephpCustomOta\Events\Concerns;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

trait BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('nativephp'),
        ];
    }
}

