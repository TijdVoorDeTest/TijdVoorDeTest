<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\QuizQuestionController;
use Tvdt\Entity\Question;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(QuizQuestionController::class)]
final class QuizQuestionControllerTest extends AbstractControllerWebTestCase
{
    public function testEditPreservesAnswerOrdering(): void
    {
        $this->loginAs('krtek-admin@example.org');

        $quiz = $this->getQuizByName('Quiz 2');
        $question = null;
        foreach ($quiz->questions as $q) {
            if ('Is de Krtek een man of een vrouw?' === $q->question) {
                $question = $q;
                break;
            }
        }

        $this->assertInstanceOf(Question::class, $question);

        $answers = $question->answers->toArray();
        $this->assertCount(2, $answers);
        $firstText = $answers[0]->text;
        $secondText = $answers[1]->text;

        $url = \sprintf(
            '/backoffice/season/krtek/quiz/%s/question/%s/edit',
            $quiz->id,
            $question->id,
        );

        $crawler = $this->client->request(Request::METHOD_GET, $url);
        $this->assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="question_form[_token]"]')->attr('value');

        // Submit with ordering values that invert which answer appears first on reload.
        // The answer currently at index 0 ($firstText) gets ordering=7,
        // the one at index 1 ($secondText) gets ordering=3.
        // @OrderBy(['ordering' => 'ASC']) on Question::$answers will return
        // $secondText (3) before $firstText (7) after flush+clear.
        $this->client->request(Request::METHOD_POST, $url, [
            'question_form' => [
                'question' => $question->question,
                'answers' => [
                    0 => ['text' => $firstText, 'ordering' => '7'],
                    1 => ['text' => $secondText, 'ordering' => '3'],
                ],
                '_token' => $token,
            ],
        ]);

        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $quiz = $this->getQuizByName('Quiz 2');
        $reloadedQuestion = null;
        foreach ($quiz->questions as $q) {
            if ('Is de Krtek een man of een vrouw?' === $q->question) {
                $reloadedQuestion = $q;
                break;
            }
        }

        $this->assertInstanceOf(Question::class, $reloadedQuestion);

        $reloadedAnswers = $reloadedQuestion->answers->toArray();
        $this->assertSame(3, $reloadedAnswers[0]->ordering);
        $this->assertSame($secondText, $reloadedAnswers[0]->text);
        $this->assertSame(7, $reloadedAnswers[1]->ordering);
        $this->assertSame($firstText, $reloadedAnswers[1]->text);
    }

    public function testReorderQuestionsWithinQuiz(): void
    {
        $this->loginAs('krtek-admin@example.org');

        $quiz = $this->getQuizByName('Quiz 2');
        $originalQuestions = $quiz->questions->toArray();
        $this->assertGreaterThanOrEqual(3, \count($originalQuestions));

        $originalFirstId = (string) $originalQuestions[0]->id;
        $originalLastId = (string) $originalQuestions[\count($originalQuestions) - 1]->id;

        $overviewUrl = \sprintf('/backoffice/season/krtek/quiz/%s/overview', $quiz->id);
        $crawler = $this->client->request(Request::METHOD_GET, $overviewUrl);
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('[data-bo--question-list-csrf-value]')->attr('data-bo--question-list-csrf-value');
        $this->assertNotEmpty($csrfToken);

        $reversedIds = array_reverse(array_map(static fn (Question $q): string => (string) $q->id, $originalQuestions));

        $reorderUrl = \sprintf('/backoffice/season/krtek/quiz/%s/questions/reorder', $quiz->id);
        $this->client->request(Request::METHOD_POST, $reorderUrl, [
            '_token' => $csrfToken,
            'ordering' => $reversedIds,
        ]);

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $quiz = $this->getQuizByName('Quiz 2');
        $reorderedQuestions = $quiz->questions->toArray();

        $this->assertSame($originalLastId, (string) $reorderedQuestions[0]->id);
        $this->assertSame($originalFirstId, (string) $reorderedQuestions[\count($reorderedQuestions) - 1]->id);
    }

    public function testEditIsDeniedForNonOwner(): void
    {
        $this->loginAs('test@example.org');

        $quiz = $this->getQuizByName('Quiz 2');
        $question = $quiz->questions->first();
        $this->assertInstanceOf(Question::class, $question);

        $this->client->request(Request::METHOD_GET, \sprintf(
            '/backoffice/season/krtek/quiz/%s/question/%s/edit',
            $quiz->id,
            $question->id,
        ));

        self::assertResponseStatusCodeSame(403);
    }

    public function testReorderIsDeniedForNonOwner(): void
    {
        $quiz = $this->getQuizByName('Quiz 2');

        // Scrape a valid CSRF token as the owner before switching to the non-owner account,
        // since the token is bound to the session, not the logged-in user.
        $this->loginAs('krtek-admin@example.org');
        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/season/krtek/quiz/%s/overview', $quiz->id));
        self::assertResponseIsSuccessful();
        $csrfToken = $crawler->filter('[data-bo--question-list-csrf-value]')->attr('data-bo--question-list-csrf-value');
        $this->assertNotEmpty($csrfToken);

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/quiz/%s/questions/reorder', $quiz->id), [
            '_token' => $csrfToken,
            'ordering' => array_map(static fn (Question $q): string => (string) $q->id, $quiz->questions->toArray()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
