<?php

namespace Modules\Integration\Contracts;

use Modules\Integration\Models\IntegrationEvent;

interface IntegrationAdapter
{
    public function process(IntegrationEvent $event): void;
}
