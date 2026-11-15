<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

final readonly class ReviewCell
{
    public function __construct(
        public string $text,
        public bool $isCorrect,
    ) {}
}
