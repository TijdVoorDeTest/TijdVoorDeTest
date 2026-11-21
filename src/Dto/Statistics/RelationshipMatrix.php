<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class RelationshipMatrix
{
    /**
     * @param list<string>      $names     active candidates, used for both rows (answering) and columns (named)
     * @param list<list<int>>   $counts    counts[answering][named]
     * @param list<string|null> $mostNamed per row, null when the candidate named nobody
     */
    public function __construct(
        public array $names,
        public array $counts,
        public array $mostNamed,
    ) {}
}
