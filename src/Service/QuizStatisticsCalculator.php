<?php

declare(strict_types=1);

namespace Tvdt\Service;

use Tvdt\Dto\Statistics\AnswerCount;
use Tvdt\Dto\Statistics\CandidateConcentration;
use Tvdt\Dto\Statistics\CoverageRow;
use Tvdt\Dto\Statistics\QuestionStatistic;
use Tvdt\Dto\Statistics\QuizAnswerStatistics;
use Tvdt\Dto\Statistics\QuizComposition;
use Tvdt\Dto\Statistics\RelationshipMatrix;
use Tvdt\Dto\Statistics\ReviewCell;
use Tvdt\Dto\Statistics\ReviewRow;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;

/** Pure statistics over an already hydrated quiz, see {@see QuizStatisticsCache} for the cached entry point. */
final class QuizStatisticsCalculator
{
    /** Fewer answered candidate-bound questions leave nothing to spread across. */
    public const int MIN_QUESTIONS_FOR_CONCENTRATION = 2;

    public function composition(Quiz $quiz): QuizComposition
    {
        $optionCounts = [];
        $disabled = 0;
        $bound = 0;
        $coverage = [];
        $candidates = $this->activeCandidates($quiz);

        foreach ($quiz->questions as $question) {
            $disabled += $question->enabled ? 0 : 1;
            $optionCounts[$question->answers->count()] = ($optionCounts[$question->answers->count()] ?? 0) + 1;

            if (!$this->isCandidateBound($question)) {
                continue;
            }

            ++$bound;
            $coverage[] = new CoverageRow($question->question, array_map(
                static fn (Candidate $candidate): int => $question->answers->filter(
                    static fn (Answer $answer): bool => $answer->candidates->contains($candidate),
                )->count(),
                $candidates,
            ));
        }

        ksort($optionCounts);

        return new QuizComposition(
            $quiz->questions->count(),
            $disabled,
            $bound,
            $quiz->questions->count() - $bound,
            $optionCounts,
            array_map(static fn (Candidate $candidate): string => $candidate->name, $candidates),
            $coverage,
        );
    }

    public function answers(Quiz $quiz): QuizAnswerStatistics
    {
        $candidates = $this->activeCandidates($quiz);
        $questions = array_values(array_filter(
            $quiz->questions->toArray(),
            static fn (Question $question): bool => $question->enabled,
        ));

        /** @var list<array<int, GivenAnswer>> $given per candidate, keyed by spl_object_id of the question */
        $given = array_map(static function (Candidate $candidate) use ($quiz): array {
            $byQuestion = [];
            foreach ($candidate->givenAnswers as $givenAnswer) {
                if ($givenAnswer->quiz === $quiz) {
                    $byQuestion[spl_object_id($givenAnswer->question)] = $givenAnswer;
                }
            }

            return $byQuestion;
        }, $candidates);

        $boundQuestions = array_values(array_filter($questions, $this->isCandidateBound(...)));

        return new QuizAnswerStatistics(
            array_map(fn (Question $question): QuestionStatistic => $this->questionStatistic($question, $given), $questions),
            $this->review($candidates, $questions, $given),
            [] === $boundQuestions ? null : $this->matrix($candidates, $boundQuestions, $given),
            [] === $boundQuestions ? [] : $this->concentration($candidates, $boundQuestions, $given),
        );
    }

    /** @return list<Candidate> */
    private function activeCandidates(Quiz $quiz): array
    {
        return array_values(array_map(
            static fn (QuizCandidate $quizCandidate): Candidate => $quizCandidate->candidate,
            $quiz->candidateData->filter(static fn (QuizCandidate $quizCandidate): bool => $quizCandidate->active)->toArray(),
        ));
    }

    private function isCandidateBound(Question $question): bool
    {
        return $question->answers->exists(static fn (int $key, Answer $answer): bool => !$answer->candidates->isEmpty());
    }

    /** @param list<array<int, GivenAnswer>> $given */
    private function questionStatistic(Question $question, array $given): QuestionStatistic
    {
        $counts = [];
        $correct = 0;
        $incorrect = 0;

        foreach ($given as $byQuestion) {
            $givenAnswer = $byQuestion[spl_object_id($question)] ?? null;
            if (!$givenAnswer instanceof GivenAnswer) {
                continue;
            }

            $counts[spl_object_id($givenAnswer->answer)] = ($counts[spl_object_id($givenAnswer->answer)] ?? 0) + 1;
            $givenAnswer->answer->isRightAnswer ? ++$correct : ++$incorrect;
        }

        return new QuestionStatistic(
            $question->question,
            $correct,
            $incorrect,
            array_values(array_map(
                static fn (Answer $answer): AnswerCount => new AnswerCount($answer->text, $counts[spl_object_id($answer)] ?? 0, $answer->isRightAnswer),
                $question->answers->toArray(),
            )),
        );
    }

    /**
     * @param list<Candidate>               $candidates
     * @param list<Question>                $questions
     * @param list<array<int, GivenAnswer>> $given
     *
     * @return list<ReviewRow>
     */
    private function review(array $candidates, array $questions, array $given): array
    {
        $rows = [];
        foreach ($candidates as $i => $candidate) {
            $rows[] = new ReviewRow($candidate->name, array_map(
                static function (Question $question) use ($given, $i): ?ReviewCell {
                    $givenAnswer = $given[$i][spl_object_id($question)] ?? null;

                    return $givenAnswer instanceof GivenAnswer ? new ReviewCell($givenAnswer->answer->text, $givenAnswer->answer->isRightAnswer) : null;
                },
                $questions,
            ));
        }

        return $rows;
    }

    /**
     * Group answers count full weight for every member, so a row can sum to more than the number of questions.
     *
     * @param list<Candidate>               $candidates
     * @param list<Question>                $boundQuestions
     * @param list<array<int, GivenAnswer>> $given
     */
    private function matrix(array $candidates, array $boundQuestions, array $given): RelationshipMatrix
    {
        $counts = [];
        $mostNamed = [];

        foreach ($candidates as $i => $candidate) {
            $row = array_map(
                fn (Candidate $target): int => $this->hitsOn($target, $boundQuestions, $given[$i]),
                $candidates,
            );
            $counts[] = $row;

            $max = max($row);
            $mostNamed[] = $max > 0 ? $candidates[(int) array_search($max, $row, true)]->name : null;
        }

        return new RelationshipMatrix(
            array_map(static fn (Candidate $candidate): string => $candidate->name, $candidates),
            $counts,
            $mostNamed,
        );
    }

    /**
     * @param list<Candidate>               $candidates
     * @param list<Question>                $boundQuestions
     * @param list<array<int, GivenAnswer>> $given
     *
     * @return list<CandidateConcentration>
     */
    private function concentration(array $candidates, array $boundQuestions, array $given): array
    {
        $result = [];

        foreach ($candidates as $i => $candidate) {
            $answered = \count(array_filter(
                $boundQuestions,
                static fn (Question $question): bool => isset($given[$i][spl_object_id($question)]),
            ));

            $hits = array_values(array_filter(array_map(
                fn (Candidate $target): int => $this->hitsOn($target, $boundQuestions, $given[$i]),
                $candidates,
            )));
            $total = array_sum($hits);

            if ($answered < self::MIN_QUESTIONS_FOR_CONCENTRATION || [] === $hits) {
                $result[] = new CandidateConcentration($candidate->name, $answered, null, null, null);

                continue;
            }

            $distinct = \count($hits);
            $entropy = 1 === $distinct ? 0.0 : -array_sum(array_map(
                static fn (int $hit): float => ($hit / $total) * log($hit / $total),
                $hits,
            )) / log($distinct);

            $result[] = new CandidateConcentration($candidate->name, $answered, $distinct, max($hits) / $total, $entropy);
        }

        return $result;
    }

    /**
     * @param list<Question>          $boundQuestions
     * @param array<int, GivenAnswer> $givenByQuestion
     */
    private function hitsOn(Candidate $target, array $boundQuestions, array $givenByQuestion): int
    {
        $hits = 0;
        foreach ($boundQuestions as $question) {
            $givenAnswer = $givenByQuestion[spl_object_id($question)] ?? null;
            if ($givenAnswer instanceof GivenAnswer && $givenAnswer->answer->candidates->contains($target)) {
                ++$hits;
            }
        }

        return $hits;
    }
}
