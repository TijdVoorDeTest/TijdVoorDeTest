<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Repository\GivenAnswerRepository;
use Tvdt\Repository\QuizRepository;

#[CoversClass(QuizRepository::class)]
final class QuizRepositoryTest extends DatabaseTestCase
{
    public function testClearQuiz(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        $this->quizRepository->clearQuiz($quiz);

        $this->entityManager->refresh($krtekSeason);

        $this->assertEmpty($quiz->candidateData);
        $this->assertEmpty($quiz->eliminations);

        /** @var GivenAnswerRepository $givenAnswerRepository */
        $givenAnswerRepository = self::getContainer()->get(GivenAnswerRepository::class);
        $this->assertEmpty($givenAnswerRepository->findBy(['quiz' => $quiz]));
    }

    public function testDeleteQuiz(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $quiz = $krtekSeason->quizzes->last();
        $this->assertInstanceOf(Quiz::class, $quiz);

        $this->quizRepository->deleteQuiz($quiz);

        $this->entityManager->refresh($krtekSeason);

        $this->assertCount(4, $krtekSeason->quizzes);
    }

    public function testTimeForCandidate(): void
    {
        $clock = new MockClock('2025-11-01 16:00:00');
        self::getContainer()->set(ClockInterface::class, $clock);
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Iris');

        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        // Start Quiz
        $qc = new QuizCandidate($quiz, $candidate);
        $qc->started = $clock->now();

        $this->entityManager->persist($qc);
        $this->entityManager->flush();

        for ($i = 0; $i < 15; ++$i) {
            $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
            $this->assertInstanceOf(Question::class, $question);

            $answer = $question->answers->first();
            $this->assertInstanceOf(Answer::class, $answer);

            $clock->sleep(10 + $i);
            $qa = new GivenAnswer($candidate, $quiz, $answer);
            $this->entityManager->persist($qa);
            $this->entityManager->flush();
        }

        $result = $this->quizRepository->getScores($quiz);

        $this->assertSame('Iris', $result[0]->name);
        $this->assertSame(5, $result[0]->correct);
        $this->assertEqualsWithDelta(5.0, $result[0]->score, \PHP_FLOAT_EPSILON);

        $this->assertSame(4, $result[0]->time->i);
        $this->assertSame(15, $result[0]->time->s);
    }

    public function testScoresAreCalculatedCorrectly(): void
    {
        $clock = new MockClock('2025-11-01 16:00:00');
        self::getContainer()->set(ClockInterface::class, $clock);
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate1 = $this->getCandidateBySeasonAndName($krtekSeason, 'Iris');
        $candidate2 = $this->getCandidateBySeasonAndName($krtekSeason, 'Philine');

        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        $qc1 = new QuizCandidate($quiz, $candidate1);
        $qc1->started = $clock->now();

        $qc2 = new QuizCandidate($quiz, $candidate2);
        $qc2->started = $clock->now();

        $this->entityManager->persist($qc1);
        $this->entityManager->persist($qc2);
        $this->entityManager->flush();

        for ($i = 0; $i < 15; ++$i) {
            $question = $this->questionRepository->findNextQuestionForCandidate($candidate1);
            $this->assertInstanceOf(Question::class, $question);

            $answer1 = $question->answers->first();
            $answer2 = $question->answers[intdiv(\count($question->answers), 2)];
            $this->assertInstanceOf(Answer::class, $answer1);
            $this->assertInstanceOf(Answer::class, $answer2);

            $clock->sleep(10);

            $qa = new GivenAnswer($candidate1, $quiz, $answer1);
            $this->entityManager->persist($qa);
            $qa = new GivenAnswer($candidate2, $quiz, $answer2);
            $this->entityManager->persist($qa);

            $this->entityManager->flush();
        }

        $scores = $this->quizRepository->getScores($quiz);
        $this->assertCount(2, $scores);
        $this->assertSame('Iris', $scores[0]->name);
        $this->assertSame('Philine', $scores[1]->name);
        $this->assertEqualsWithDelta(5.0, $scores[0]->score, \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(4.0, $scores[1]->score, \PHP_FLOAT_EPSILON);
    }

    public function testCorrectionsCalculatedCorrectly(): void
    {
        $clock = new MockClock('2025-11-01 16:00:00');
        self::getContainer()->set(ClockInterface::class, $clock);
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Iris');

        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        $qc = new QuizCandidate($quiz, $candidate);
        $qc->started = $clock->now();

        $this->entityManager->persist($qc);
        $this->entityManager->flush();

        for ($i = 0; $i < 15; ++$i) {
            $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
            $this->assertInstanceOf(Question::class, $question);

            $answer = $question->answers->first();
            $this->assertInstanceOf(Answer::class, $answer);

            $clock->sleep(10);
            $qa = new GivenAnswer($candidate, $quiz, $answer);
            $this->entityManager->persist($qa);
            $this->entityManager->flush();
        }

        $qc->corrections = 2;
        $this->entityManager->flush();

        $result = $this->quizRepository->getScores($quiz);

        $this->assertEqualsWithDelta(7.0, $result[0]->score, \PHP_FLOAT_EPSILON);
    }

    public function testFetchWithQuestionsAndCandidatesEagerLoadsAllBranches(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $quiz = $krtekSeason->quizzes->first();
        $this->assertInstanceOf(Quiz::class, $quiz);

        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Iris');
        $candidateId = $candidate->id->toString();
        $question = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $question);
        $answer = $question->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);
        $answer->addCandidate($candidate);

        $quizCandidate = new QuizCandidate($quiz, $candidate);
        $this->entityManager->persist($quizCandidate);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $fetched = $this->quizRepository->fetchWithQuestionsAndCandidates($quiz->id);

        $this->assertInstanceOf(PersistentCollection::class, $fetched->questions);
        $this->assertTrue($fetched->questions->isInitialized());

        $fetchedAnswer = $fetched->questions->first()->answers->first();
        $this->assertInstanceOf(PersistentCollection::class, $fetchedAnswer->candidates);
        $this->assertTrue($fetchedAnswer->candidates->isInitialized());
        $this->assertTrue($fetchedAnswer->candidates->exists(
            static fn (int $key, Candidate $c): bool => $c->id->toString() === $candidateId,
        ));

        $this->assertInstanceOf(PersistentCollection::class, $fetched->candidateData);
        $this->assertTrue($fetched->candidateData->isInitialized());
        $this->assertCount(1, $fetched->candidateData);
        $this->assertSame($candidateId, $fetched->candidateData->first()->candidate->id->toString());
    }

    public function testCandidatesWithSameScoreAreSortedCorrectlyByTime(): void
    {
        $clock = new MockClock('2025-11-01 16:00:00');
        self::getContainer()->set(ClockInterface::class, $clock);
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate1 = $this->getCandidateBySeasonAndName($krtekSeason, 'Iris');
        $candidate2 = $this->getCandidateBySeasonAndName($krtekSeason, 'Philine');

        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        $qc1 = new QuizCandidate($quiz, $candidate1);
        $qc1->started = $clock->now();

        $this->entityManager->persist($qc1);
        $clock->sleep(10);
        $qc2 = new QuizCandidate($quiz, $candidate2);
        $qc2->started = $clock->now();

        $this->entityManager->persist($qc2);
        $this->entityManager->flush();

        for ($i = 0; $i < 15; ++$i) {
            $question = $this->questionRepository->findNextQuestionForCandidate($candidate1);
            $this->assertInstanceOf(Question::class, $question);

            $answer1 = $question->answers->first();
            $answer2 = $question->answers->last();
            $this->assertInstanceOf(Answer::class, $answer1);
            $this->assertInstanceOf(Answer::class, $answer2);

            $clock->sleep(10);

            $qa = new GivenAnswer($candidate1, $quiz, $answer1);
            $this->entityManager->persist($qa);

            $qa = new GivenAnswer($candidate2, $quiz, $answer2);
            $this->entityManager->persist($qa);

            $this->entityManager->flush();
        }

        $result = $this->quizRepository->getScores($quiz);

        $this->assertEqualsWithDelta(5.0, $result[0]->score, \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(5.0, $result[1]->score, \PHP_FLOAT_EPSILON);
        $this->assertSame('Philine', $result[0]->name);
        $this->assertSame('Iris', $result[1]->name);
    }
}
