<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Tvdt\Dto\Statistics\QuestionStatistic;
use Tvdt\Dto\Statistics\QuizAnswerStatistics;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\EventListener\QuizStatisticsInvalidator;
use Tvdt\Service\QuizStatisticsCache;
use Tvdt\Tests\Integration\Repository\DatabaseTestCase;

#[CoversClass(QuizStatisticsCache::class)]
#[CoversClass(QuizStatisticsInvalidator::class)]
final class QuizStatisticsCacheTest extends DatabaseTestCase
{
    private QuizStatisticsCache $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = self::getContainer()->get(QuizStatisticsCache::class);
    }

    public function testAnswersAreCachedUntilAGivenAnswerIsPersisted(): void
    {
        $quiz = $this->quiz4();
        $first = $this->subject->answers($quiz);
        $this->assertSame(1, $this->answered($first));

        // A raw delete bypasses the ORM lifecycle events, so only a cache can still return the old numbers.
        $this->entityManager->getConnection()->executeStatement('delete from given_answer where quiz_id = :id', ['id' => $quiz->id->toRfc4122()]);
        $this->entityManager->clear();
        $this->assertEquals($first, $this->subject->answers($this->quiz4()));

        $quiz = $this->quiz4();
        $tom = $this->getCandidateBySeasonAndName($quiz->season, 'Tom');
        $this->entityManager->persist(new QuizCandidate($quiz, $tom));
        $this->entityManager->flush();

        $this->assertSame(0, $this->answered($this->subject->answers($quiz)));
    }

    public function testSoftDeletingAGivenAnswerInvalidatesTheAnswers(): void
    {
        $quiz = $this->quiz4();
        $this->assertSame(1, $this->answered($this->subject->answers($quiz)));

        $givenAnswer = $this->entityManager->getRepository(GivenAnswer::class)->findOneBy(['quiz' => $quiz]);
        $this->assertInstanceOf(GivenAnswer::class, $givenAnswer);
        $this->entityManager->remove($givenAnswer);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->assertSame(0, $this->answered($this->subject->answers($this->quiz4())));
    }

    public function testCompositionIsOnlyCachedOnceTheQuizIsLocked(): void
    {
        $locked = $this->quiz4();
        $this->assertTrue($locked->isLocked);
        $first = $this->subject->composition($locked);
        $this->disableAllQuestions($locked);
        $this->assertEquals($first, $this->subject->composition($locked));

        $this->subject->invalidate($locked->id);
        $this->assertSame($first->questionCount, $this->subject->composition($locked)->disabledCount);

        $unlocked = $this->getQuizByName('Quiz 3');
        $this->assertFalse($unlocked->isLocked);
        $questionCount = $unlocked->questions->count();
        $this->assertSame(0, $this->subject->composition($unlocked)->disabledCount);
        $this->disableAllQuestions($unlocked);
        $this->assertSame($questionCount, $this->subject->composition($unlocked)->disabledCount);
    }

    private function quiz4(): Quiz
    {
        return $this->getQuizByName('Quiz 4');
    }

    private function getQuizByName(string $name): Quiz
    {
        $quiz = $this->quizRepository->findOneBy(['name' => $name, 'season' => $this->getSeasonByCode('krtek')]);
        $this->assertInstanceOf(Quiz::class, $quiz);

        return $quiz;
    }

    private function answered(QuizAnswerStatistics $statistics): int
    {
        return array_sum(array_map(static fn (QuestionStatistic $question): int => $question->correct + $question->incorrect, $statistics->questions));
    }

    private function disableAllQuestions(Quiz $quiz): void
    {
        $this->entityManager->getConnection()->executeStatement('update question set enabled = false where quiz_id = :id', ['id' => $quiz->id->toRfc4122()]);
        $this->entityManager->clear();
    }
}
