<?php

namespace Ikechukwu11\NativephpCustomOta\Events;

use Ikechukwu11\NativephpCustomOta\Events\Concerns\BroadcastsGlobally;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UpdatePromptAnswered implements ShouldBroadcastNow
{
    use BroadcastsGlobally;

    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $version = null,
    ) {}
}

