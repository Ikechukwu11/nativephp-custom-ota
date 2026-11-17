<?php

namespace Ikechukwu11\NativephpCustomOta\Testing;

use Ikechukwu11\NativephpCustomOta\Ota;

class OtaMacros
{
    /**
     * Bind a fake Ota instance into the container for testing.
     */
    public static function fake(array $responses = []): Ota
    {
        $fake = new class($responses) extends Ota
        {
            public function __construct(private readonly array $responses) {}

            protected function call(string $function, array $parameters = []): ?array
            {
                return $this->responses[$function] ?? match ($function) {
                    'Ota.GetStatus' => ['current_version' => '1.0.0', 'pending' => false, 'has_previous' => false],
                    'Ota.Check' => ['available' => false, 'reason' => 'Up to date'],
                    'Ota.Download' => ['success' => true],
                    'Ota.Apply' => ['success' => true, 'version' => $parameters['version'] ?? '1.0.1'],
                    'Ota.Rollback' => ['success' => true, 'version' => '1.0.0'],
                    default => null,
                };
            }
        };

        app()->instance(Ota::class, $fake);

        return $fake;
    }
}

