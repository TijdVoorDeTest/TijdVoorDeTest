<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Repository\QuizCandidateRepository;

#[CoversClass(QuizCandidateRepository::class)]
final class QuizCandidateRepositoryTest extends DatabaseTestCase
{
    public function testCreateIfNotExists(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Myrthe');
        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        $result = $this->quizCandidateRepository->createIfNotExist($quiz, $candidate);
        $this->assertTrue($result);

        $quizCandidate = $this->quizCandidateRepository->findOneBy([
            'candidate' => $candidate,
            'quiz' => $quiz,
        ]);

        $this->assertInstanceOf(QuizCandidate::class, $quizCandidate);

        $result = $this->quizCandidateRepository->createIfNotExist($quiz, $candidate);
        $this->assertFalse($result);
    }

    public function testSetResultForCandidateUpdatesCandidateCorrectly(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Myrthe');
        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        $this->quizCandidateRepository->createIfNotExist($quiz, $candidate);

        $this->quizCandidateRepository->setResultForCandidate(
            $quiz, $candidate, 3.5, 30,
        );

        $quizCandidate = $this->quizCandidateRepository->findOneBy([
            'candidate' => $candidate,
            'quiz' => $quiz,
        ]);

        $this->assertInstanceOf(QuizCandidate::class, $quizCandidate);

        $this->assertEqualsWithDelta(3.5, $quizCandidate->corrections, 0.1);
        $this->assertSame(30, $quizCandidate->penaltySeconds);
    }

    public function testCannotGiveResultToCandidateWithoutResult(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Myrthe');
        $quiz = $krtekSeason->activeQuiz;
        $this->assertInstanceOf(Quiz::class, $quiz);

        $this->expectException(\InvalidArgumentException::class);

        $this->quizCandidateRepository->setResultForCandidate(
            $quiz, $candidate, 3.5, 30,
        );
    }
}
