<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Tvdt\Rector\NoSafeNamespaceTypeHintRector;

return RectorConfig::configure()
    ->withRules([NoSafeNamespaceTypeHintRector::class]);
