<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class CoverageRow
{
    /** @param list<int> $counts per active candidate, how many answers of the question represent them (1 is correct) */
    public function __construct(
        public string $question,
        public array $counts,
    ) {}
}
