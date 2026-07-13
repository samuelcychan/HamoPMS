<?php

namespace Modules\Integration\Services;

use Illuminate\Contracts\Container\Container;
use Modules\Integration\Contracts\IntegrationAdapter;
use RuntimeException;

class IntegrationAdapterManager
{
    public function __construct(private readonly Container $container) {}

    public function for(string $provider): IntegrationAdapter
    {
        $adapterClass = config("integrations.providers.{$provider}.adapter");

        if (! is_string($adapterClass) || $adapterClass === '') {
            throw new RuntimeException("No integration adapter is configured for provider {$provider}.");
        }

        $adapter = $this->container->make($adapterClass);

        if (! $adapter instanceof IntegrationAdapter) {
            throw new RuntimeException("The configured {$provider} adapter does not implement IntegrationAdapter.");
        }

        return $adapter;
    }
}
