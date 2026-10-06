<?php

declare(strict_types=1);

use Tvdt\Kernel;

require_once __DIR__.'/../vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    $appEnv = empty($context['APP_ENV']) ? 'prod' : (string) $context['APP_ENV'];
    $appDebug = empty($context['APP_DEBUG']) ? 'prod' !== $appEnv : filter_var($context['APP_DEBUG'], \FILTER_VALIDATE_BOOL);

    return new Kernel($appEnv, $appDebug);
};
