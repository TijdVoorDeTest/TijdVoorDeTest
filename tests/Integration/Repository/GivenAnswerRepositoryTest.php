<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tvdt\Entity\Answer;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Repository\GivenAnswerRepository;

#[CoversClass(GivenAnswerRepository::class)]
final class GivenAnswerRepositoryTest extends DatabaseTestCase
{
    public function testDuplicateGivenAnswerForSameQuestionIsRejected(): void
    {
        $krtekSeason = $this->getSeasonByCode('krtek');
        $candidate = $this->getCandidateBySeasonAndName($krtekSeason, 'Tom');

        $question = $this->questionRepository->findNextQuestionForCandidate($candidate);
        $this->assertInstanceOf(Question::class, $question);

        $answers = $question->answers;
        $this->assertGreaterThanOrEqual(2, $answers->count());

        $firstAnswer = $answers->first();
        $secondAnswer = $answers->get(1);
        $this->assertInstanceOf(Answer::class, $firstAnswer);
        $this->assertInstanceOf(Answer::class, $secondAnswer);

        $this->entityManager->persist(new GivenAnswer($candidate, $question->quiz, $firstAnswer));
        $this->entityManager->flush();

        $this->entityManager->persist(new GivenAnswer($candidate, $question->quiz, $secondAnswer));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }
}
