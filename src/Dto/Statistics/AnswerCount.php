<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class AnswerCount
{
    public function __construct(
        public string $text,
        public int $count,
        public bool $isCorrect,
    ) {}
}
