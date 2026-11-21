<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class QuestionStatistic
{
    /** @param list<AnswerCount> $answers */
    public function __construct(
        public string $question,
        public int $correct,
        public int $incorrect,
        public array $answers,
    ) {}
}
