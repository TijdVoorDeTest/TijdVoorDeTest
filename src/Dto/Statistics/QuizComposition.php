<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class QuizComposition
{
    /**
     * @param array<int, int>   $optionCounts   number of answer options => number of questions
     * @param list<string>      $candidateNames
     * @param list<CoverageRow> $coverage
     */
    public function __construct(
        public int $questionCount,
        public int $disabledCount,
        public int $candidateBoundCount,
        public int $plainCount,
        public array $optionCounts,
        public array $candidateNames,
        public array $coverage,
    ) {}
}
