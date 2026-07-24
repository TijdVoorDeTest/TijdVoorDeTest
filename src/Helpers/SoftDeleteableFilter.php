<?php

declare(strict_types=1);

namespace Tvdt\Helpers;

use Doctrine\ORM\EntityManagerInterface;

class SoftDeleteableFilter
{
    /** Runs $fn with Doctrine's global softdeleteable filter disabled, always re-enabling it afterward. */
    public static function withDisabled(EntityManagerInterface $em, callable $fn): mixed
    {
        $filters = $em->getFilters();
        $filters->disable('softdeleteable');

        try {
            return $fn();
        } finally {
            $filters->enable('softdeleteable');
        }
    }
}
