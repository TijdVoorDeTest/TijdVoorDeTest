<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Molshoop;

use Safe\DateTimeImmutable;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\EliminationScreenView;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Enum\ScreenColour;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

/**
 * Quiz::$status (New/Concept/Ready/Active/Done/Finished/Revealed) is a plain computed property,
 * not cached — deliberately, per the same "cheap enough to not need Redis" decision made for the
 * original 5-state enum. It reads three independent to-many collections (questions,
 * candidate.givenAnswers, quiz.eliminations.screenViews) that are NOT eager-loaded by default, so
 * this asserts the controllers that render `quiz.status` in a loop batch-load all three (see
 * QuizRepository::eagerLoadStatusDataForSeason() and the extended
 * fetchWithQuestionsAndCandidates()) in one query each, instead of one query per quiz, candidate,
 * or elimination.
 *
 * Asserting a total query count (even differentially) is unreliable here: Quiz::$status
 * short-circuits at its first matching branch, so a total count can shift by ±1 across scenarios
 * for reasons unrelated to batching. Instead, this bulks up one quiz with several candidates and
 * eliminations and asserts the questions/given-answer/screen-view joins each appear exactly once
 * in the SQL log, which is the direct signature of "one batch query", regardless of how many rows
 * they return.
 */
final class QuizStatusQueryCountTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs('krtek-admin@example.org');
        $this->addFinishedCandidatesAndEliminationsToQuiz4();
    }

    public function testSeasonTestsTabBatchesGivenAnswerAndScreenViewQueries(): void
    {
        $this->assertQueriesAreBatched($this->executeAndGetSql('/molshoop/season/krtek'));
    }

    public function testQuizOverviewBatchesGivenAnswerAndScreenViewQueries(): void
    {
        $quizId = $this->getQuizByName('Quiz 4')->id;
        $url = \sprintf('/molshoop/season/krtek/quiz/%s/overview', $quizId);

        $this->assertQueriesAreBatched($this->executeAndGetSql($url));
    }

    /** @param list<string> $sql */
    private function assertQueriesAreBatched(array $sql): void
    {
        $this->assertQueryAppearsExactlyOnce($sql, 'LEFT JOIN question ', "questions across all of the season's quizzes");
        $this->assertQueryAppearsExactlyOnce($sql, 'LEFT JOIN given_answer', "given answers across all of Quiz 4's 6 candidates");
        $this->assertQueryAppearsExactlyOnce($sql, 'LEFT JOIN elimination_screen_view', "screen views across all of Quiz 4's 3 eliminations");
    }

    /** @param list<string> $sql */
    private function assertQueryAppearsExactlyOnce(array $sql, string $needle, string $what): void
    {
        $matches = array_values(array_filter($sql, static fn (string $query): bool => str_contains($query, $needle)));

        $this->assertCount(1, $matches, "Expected exactly one batch query joining {$what}, got:\n".implode("\n", $matches));
    }

    /** @return list<string> */
    private function executeAndGetSql(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $this->assertInstanceOf(DebugDataHolder::class, $debugDataHolder);

        return array_values(array_map(static fn (array $row): string => $row['sql'], $debugDataHolder->getData()['default'] ?? []));
    }

    /** Gives Quiz 4 six finished candidates and three eliminations-with-screen-views, so a per-candidate/per-elimination N+1 would be unmistakable. */
    private function addFinishedCandidatesAndEliminationsToQuiz4(): void
    {
        $quiz = $this->getQuizByName('Quiz 4');
        $season = $quiz->season;

        $question = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $question);
        $answer = $question->answers->first();
        $this->assertInstanceOf(Answer::class, $answer);

        for ($i = 0; $i < 5; ++$i) {
            $candidate = new Candidate(\sprintf('Bulk%d', $i));
            $candidate->season = $season;
            $this->entityManager->persist($candidate);

            $quizCandidate = new QuizCandidate($quiz, $candidate);
            $quizCandidate->started = new DateTimeImmutable();
            $this->entityManager->persist($quizCandidate);

            $this->entityManager->persist(new GivenAnswer($candidate, $quiz, $answer));
        }

        for ($i = 0; $i < 3; ++$i) {
            $elimination = new Elimination($quiz);
            $screenView = new EliminationScreenView($elimination, $this->getCandidate('Elise'), ScreenColour::Red);
            $elimination->screenViews->add($screenView);
            $this->entityManager->persist($elimination);
            $this->entityManager->persist($screenView);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
