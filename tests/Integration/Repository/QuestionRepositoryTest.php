<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Repository\QuestionRepository;

#[CoversClass(QuestionRepository::class)]
final class QuestionRepositoryTest extends DatabaseTestCase
{
    public function testFindNextQuestionReturnsRightQuestion(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Tom');

        $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
        $this->assertInstanceOf(Question::class, $question);
        $this->assertSame('Is de Krtek een man of een vrouw?', $question->question, 'Wrong first question');

        $this->answerQuestion($question, $candidate);

        $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
        $this->assertInstanceOf(Question::class, $question);
        $this->assertSame('Hoeveel broers heeft de Krtek?', $question->question, 'Wrong second question');

        $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
        $this->assertInstanceOf(Question::class, $question);
        $this->assertSame('Hoeveel broers heeft de Krtek?', $question->question, 'Getting question a second time fails');

        $quiz = $krtekSeason->quizzes->last();
        $this->assertInstanceOf(Quiz::class, $quiz);
        $krtekSeason->activeQuiz = $quiz;
        $this->entityManager->flush();

        $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
        $this->assertInstanceOf(Question::class, $question);
        $this->assertSame('Is de Krtek een man of een vrouw?', $question->question, 'Wrong question after switching season.');
    }

    public function testFindNextQuestionGivesNullWhenAllQuestionsAnswered(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Tom');

        for ($i = 0; $i < 15; ++$i) {
            $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
            $this->assertInstanceOf(Question::class, $question);
            $this->answerQuestion($question, $candidate);
        }

        $question = $this->questionRepository->findNextQuestionForCandidate($candidate);

        $this->assertNotInstanceOf(Question::class, $question);
    }

    public function testFindNextQuestionWithNoActiveQuizReturnsNull(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Tom');

        $krtekSeason->activeQuiz = null;
        $this->entityManager->flush();

        $question = $this->questionRepository->findNextQuestionForCandidate($candidate);

        $this->assertNotInstanceOf(Question::class, $question);
    }

    public function testFetchWithAnswerCandidatesEagerLoadsCandidates(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $quiz = $krtekSeason->quizzes->first();
        $this->assertInstanceOf(Quiz::class, $quiz);
        $question = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $question);
        $answer = $question->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Iris');
        $candidateId = $candidate->id->toString();
        $answer->addCandidate($candidate);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $fetched = $this->questionRepository->fetchWithAnswerCandidates($question->id);

        $this->assertInstanceOf(PersistentCollection::class, $fetched->answers);
        $this->assertTrue($fetched->answers->isInitialized());
        $fetchedAnswer = $fetched->answers->first();
        $this->assertInstanceOf(Answer::class, $fetchedAnswer);
        $this->assertInstanceOf(PersistentCollection::class, $fetchedAnswer->candidates);
        $this->assertTrue($fetchedAnswer->candidates->isInitialized());
        $this->assertTrue($fetchedAnswer->candidates->exists(
            static fn (int $key, Candidate $c): bool => $c->id->toString() === $candidateId,
        ));
    }

    private function answerQuestion(Question $question, Candidate $candidate): void
    {
        $answer = $question->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);
        $this->entityManager->persist(new GivenAnswer(
            $candidate,
            $question->quiz,
            $answer,
        ));
        $this->entityManager->flush();
    }
}
