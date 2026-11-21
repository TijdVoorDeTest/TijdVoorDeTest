<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class ReviewRow
{
    /** @param list<ReviewCell|null> $cells one per enabled question, null when unanswered */
    public function __construct(
        public string $candidate,
        public array $cells,
    ) {}
}
