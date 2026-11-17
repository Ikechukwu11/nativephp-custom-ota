<?php

namespace Ikechukwu11\NativephpCustomOta\Events;

use Ikechukwu11\NativephpCustomOta\Events\Concerns\BroadcastsGlobally;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UpdateDownloaded implements ShouldBroadcastNow
{
    use BroadcastsGlobally;

    public function __construct(
        public readonly string $version,
        public readonly int $size,
    ) {}
}

