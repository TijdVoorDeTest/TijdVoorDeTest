<?php

declare(strict_types=1);

namespace Tvdt\Tests\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Safe\DateTimeImmutable;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Entity\Season;
use Tvdt\Enum\QuizStatus;

#[CoversClass(Quiz::class)]
final class QuizTest extends TestCase
{
    public function testStatusIsNewWhenQuizHasNoQuestions(): void
    {
        $quiz = $this->quiz();

        $this->assertSame(QuizStatus::New, $quiz->status);
    }

    public function testStatusIsConceptWhenQuizHasQuestionsButIsNotFinalized(): void
    {
        $quiz = $this->quiz();
        $quiz->addQuestion(new Question());

        $this->assertSame(QuizStatus::Concept, $quiz->status);
    }

    public function testStatusIsReadyWhenFinalizedWithoutStartedCandidates(): void
    {
        $quiz = $this->quiz();
        $quiz->addQuestion(new Question());
        $quiz->finalizedAt = new DateTimeImmutable();

        $this->assertSame(QuizStatus::Ready, $quiz->status);
    }

    public function testStatusIsFinishedWhenFinalizedWithStartedCandidatesAndNotActive(): void
    {
        $quiz = $this->quiz();
        $quiz->addQuestion(new Question());
        $quiz->finalizedAt = new DateTimeImmutable();
        $this->startCandidate($quiz);

        $this->assertSame(QuizStatus::Finished, $quiz->status);
    }

    public function testStatusIsActiveWhenSetAsSeasonActiveQuiz(): void
    {
        $quiz = $this->quiz();
        $quiz->addQuestion(new Question());
        $quiz->finalizedAt = new DateTimeImmutable();
        $quiz->season->activeQuiz = $quiz;

        $this->assertSame(QuizStatus::Active, $quiz->status);
    }

    public function testStatusIsActiveEvenWhenLegacyQuizIsNotFinalized(): void
    {
        $quiz = $this->quiz();
        $quiz->addQuestion(new Question());
        $quiz->season->activeQuiz = $quiz;

        $this->assertSame(QuizStatus::Active, $quiz->status);
    }

    private function quiz(): Quiz
    {
        $quiz = new Quiz();
        $quiz->season = new Season();

        return $quiz;
    }

    private function startCandidate(Quiz $quiz): void
    {
        $quizCandidate = new QuizCandidate($quiz, new Candidate('Tom'));
        $quizCandidate->started = new DateTimeImmutable();

        $quiz->candidateData->add($quizCandidate);
    }
}
