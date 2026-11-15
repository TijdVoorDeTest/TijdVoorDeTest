<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class CandidateConcentration
{
    /** The spread metrics are null until the candidate answered enough candidate-bound questions. */
    public function __construct(
        public string $candidate,
        public int $questions,
        public ?int $distinctTargets,
        public ?float $topShare,
        public ?float $entropy,
    ) {}
}
