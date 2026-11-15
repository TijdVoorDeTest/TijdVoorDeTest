<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Molshoop;

use PHPUnit\Framework\Attributes\CoversClass;
use Safe\DateTimeImmutable;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Tvdt\Controller\Molshoop\QuizController;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Service\QuizStatisticsCache;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(QuizController::class)]
final class QuizStatisticsControllerTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs('krtek-admin@example.org');
    }

    public function testStatisticsTabShowsCompositionAndChartsForAStartedQuiz(): void
    {
        $quiz = $this->getQuizByName('Quiz 4');

        $this->client->request(Request::METHOD_GET, $this->url($quiz));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('canvas');
        self::assertSelectorExists('table.table-review');
    }

    public function testStatisticsTabShowsMatrixAndConcentrationForCandidateBoundQuestions(): void
    {
        $quiz = $this->getQuizByName('Quiz 4');
        $question = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $question);
        $answer = $question->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);
        $answer->addCandidate($this->getCandidate('Elise'));
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, $this->url($quiz));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table.table-matrix');
        self::assertSelectorExists('table.table-matrix td[title="Elise noemde Elise: 1x"]');
        self::assertSelectorExists('table.table-concentration');
    }

    public function testStatisticsTabOfAQuizNobodyStartedOnlyShowsComposition(): void
    {
        $quiz = $this->getQuizByName('Quiz 3');
        $this->assertFalse($quiz->hasStartedCandidates);

        $this->client->request(Request::METHOD_GET, $this->url($quiz));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('canvas');
        self::assertSelectorExists('table.table-composition');
    }

    public function testStatisticsTabIsDeniedForSomeoneWhoCannotEditTheSeason(): void
    {
        $quiz = $this->getQuizByName('Quiz 4');
        $this->loginAs('user1@example.org');

        $this->client->request(Request::METHOD_GET, $this->url($quiz));

        self::assertResponseStatusCodeSame(403);
    }

    public function testStatisticsQueryCountDoesNotGrowWithMoreCandidates(): void
    {
        $quiz = $this->getQuizByName('Quiz 4');
        $cache = self::getContainer()->get(QuizStatisticsCache::class);
        $this->client->request(Request::METHOD_GET, $this->url($quiz)); // warm up unrelated caches

        $cache->invalidate($quiz->id);
        $before = $this->queryCountOfRequest($quiz);

        $this->entityManager->clear(); // the requests above ran in their own kernel, so reload everything managed
        $quiz = $this->getQuizByName('Quiz 4');
        $firstQuestion = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $firstQuestion);
        $answer = $firstQuestion->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);

        for ($i = 0; $i < 15; ++$i) {
            $candidate = new Candidate(\sprintf('Extra %d', $i));
            $candidate->season = $quiz->season;
            $quizCandidate = new QuizCandidate($quiz, $candidate);
            $quizCandidate->started = new DateTimeImmutable();

            $this->entityManager->persist($candidate);
            $this->entityManager->persist($quizCandidate);
            $this->entityManager->persist(new GivenAnswer($candidate, $quiz, $answer));
        }

        $this->entityManager->flush(); // also invalidates the cached statistics
        $this->entityManager->clear();

        $after = $this->queryCountOfRequest($quiz);

        $this->assertLessThanOrEqual($before, $after, 'Adding candidates should not add queries (N+1 while computing statistics).');
    }

    private function queryCountOfRequest(Quiz $quiz): int
    {
        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, $this->url($quiz));
        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        $this->assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        $this->assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function url(Quiz $quiz): string
    {
        return \sprintf('/molshoop/season/krtek/quiz/%s/statistics', $quiz->id);
    }
}
