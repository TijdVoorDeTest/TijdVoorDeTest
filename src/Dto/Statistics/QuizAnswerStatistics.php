<?php

declare(strict_types=1);

namespace Tvdt\Dto\Statistics;

/** Everything that depends on given answers. Only enabled questions and active candidates are included, joker corrections are ignored. */
final readonly class QuizAnswerStatistics
{
    /**
     * @param list<QuestionStatistic>      $questions
     * @param list<ReviewRow>              $review
     * @param list<CandidateConcentration> $concentration empty unless the quiz has candidate-bound questions
     */
    public function __construct(
        public array $questions,
        public array $review,
        public ?RelationshipMatrix $matrix,
        public array $concentration,
    ) {}
}
