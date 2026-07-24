<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Safe\DateTimeImmutable;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\EliminationScreenView;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Entity\Season;
use Tvdt\Enum\QuizStatus;
use Tvdt\Enum\ScreenColour;

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

    public function testStatusIsReadyWhenStartedButNotAllQuestionsAnswered(): void
    {
        $quiz = $this->quiz();
        $question = new Question();
        $quiz->addQuestion($question);
        $quiz->finalizedAt = new DateTimeImmutable();
        $this->startCandidate($quiz, new Candidate('Tom'));

        $this->assertSame(QuizStatus::Ready, $quiz->status);
    }

    public function testStatusIsFinishedWhenAllActiveCandidatesFinishedAndNotActive(): void
    {
        $quiz = $this->quiz();
        $question = new Question();
        $quiz->addQuestion($question);
        $quiz->finalizedAt = new DateTimeImmutable();

        $candidate = $this->startCandidate($quiz, new Candidate('Tom'));
        $this->answerQuestion($candidate, $quiz, $question);

        $this->assertSame(QuizStatus::Finished, $quiz->status);
    }

    public function testAllCandidatesFinishedIgnoresInactiveCandidates(): void
    {
        $quiz = $this->quiz();
        $question = new Question();
        $quiz->addQuestion($question);
        $quiz->finalizedAt = new DateTimeImmutable();

        $finishedCandidate = $this->startCandidate($quiz, new Candidate('Tom'));
        $this->answerQuestion($finishedCandidate, $quiz, $question);

        $inactiveQuizCandidate = new QuizCandidate($quiz, new Candidate('Mol'));
        $inactiveQuizCandidate->active = false;

        $quiz->candidateData->add($inactiveQuizCandidate);

        $this->assertSame(QuizStatus::Finished, $quiz->status);
    }

    public function testAllCandidatesFinishedIgnoresDisabledQuestions(): void
    {
        $quiz = $this->quiz();
        $enabledQuestion = new Question();
        $disabledQuestion = new Question();
        $disabledQuestion->enabled = false;

        $quiz->addQuestion($enabledQuestion);
        $quiz->addQuestion($disabledQuestion);
        $quiz->finalizedAt = new DateTimeImmutable();

        $candidate = $this->startCandidate($quiz, new Candidate('Tom'));
        $this->answerQuestion($candidate, $quiz, $enabledQuestion);

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

    public function testStatusIsDoneWhenActiveAndAllActiveCandidatesFinished(): void
    {
        $quiz = $this->quiz();
        $question = new Question();
        $quiz->addQuestion($question);
        $quiz->finalizedAt = new DateTimeImmutable();
        $quiz->season->activeQuiz = $quiz;

        $candidate = $this->startCandidate($quiz, new Candidate('Tom'));
        $this->answerQuestion($candidate, $quiz, $question);

        $this->assertSame(QuizStatus::Done, $quiz->status);
    }

    public function testStatusIsRevealedWhenEliminationHasScreenViewShown(): void
    {
        $quiz = $this->quiz();
        $quiz->addQuestion(new Question());
        $quiz->finalizedAt = new DateTimeImmutable();
        $this->startCandidate($quiz, new Candidate('Tom'));

        $elimination = new Elimination($quiz);
        $elimination->screenViews->add(new EliminationScreenView($elimination, new Candidate('Tom'), ScreenColour::Green));

        $quiz->addElimination($elimination);

        $this->assertSame(QuizStatus::Revealed, $quiz->status);
    }

    private function quiz(): Quiz
    {
        $quiz = new Quiz();
        $quiz->season = new Season();

        return $quiz;
    }

    private function startCandidate(Quiz $quiz, Candidate $candidate): Candidate
    {
        $quizCandidate = new QuizCandidate($quiz, $candidate);
        $quizCandidate->started = new DateTimeImmutable();

        $quiz->candidateData->add($quizCandidate);

        return $candidate;
    }

    private function answerQuestion(Candidate $candidate, Quiz $quiz, Question $question): void
    {
        $answer = new Answer('answer');
        $question->addAnswer($answer);
        $candidate->givenAnswers->add(new GivenAnswer($candidate, $quiz, $answer));
    }
}
