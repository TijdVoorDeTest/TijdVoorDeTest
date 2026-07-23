<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\QuestionBankController;
use Tvdt\Entity\BankAnswer;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\Question;
use Tvdt\Entity\QuestionLabel;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(QuestionBankController::class)]
final class QuestionBankControllerTest extends AbstractControllerWebTestCase
{
    private function getBankQuestion(string $question): BankQuestion
    {
        $bankQuestion = $this->entityManager->getRepository(BankQuestion::class)->findOneBy(['question' => $question]);
        $this->assertInstanceOf(BankQuestion::class, $bankQuestion);

        return $bankQuestion;
    }

    public function testIndexListsBankQuestions(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Wie is de Krtek?');
        $this->assertSelectorTextContains('body', 'Waar sliep de Krtek?');
        $this->assertSelectorTextContains('body', 'Wat at de Krtek als ontbijt?');
    }

    public function testIndexFiltersByLabel(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $label = $this->entityManager->getRepository(QuestionLabel::class)->findOneBy(['name' => 'Locatie']);
        $this->assertInstanceOf(QuestionLabel::class, $label);

        $crawler = $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank?label='.$label->slug);

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('tbody')->text();
        $this->assertStringContainsString('Waar sliep de Krtek?', $body);
        $this->assertStringNotContainsString('Wie is de Krtek?', $body);
    }

    public function testNonOwnerIsDenied(): void
    {
        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateBankQuestion(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $crawler = $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank/new');
        $this->assertResponseIsSuccessful();

        $token = (string) $crawler->filter('input[name="bank_question_form[_token]"]')->attr('value');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/question-bank/new', [
            'bank_question_form' => [
                'question' => 'Wat is de lievelingskleur van de Krtek?',
                'reusable' => '1',
                'answers' => [
                    ['text' => 'Rood', 'isRightAnswer' => '1'],
                    ['text' => 'Blauw'],
                ],
                '_token' => $token,
            ],
        ]);

        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $bankQuestion = $this->getBankQuestion('Wat is de lievelingskleur van de Krtek?');
        $this->assertTrue($bankQuestion->reusable);
        $this->assertCount(2, $bankQuestion->answers);
        $this->assertSame('Rood', (string) $bankQuestion->answers->first());
    }

    public function testCreateAllowedWithoutCorrectAnswer(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $crawler = $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank/new');
        $token = (string) $crawler->filter('input[name="bank_question_form[_token]"]')->attr('value');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/question-bank/new', [
            'bank_question_form' => [
                'question' => 'Vraag zonder goed antwoord',
                'answers' => [
                    ['text' => 'Een'],
                    ['text' => 'Twee'],
                ],
                '_token' => $token,
            ],
        ]);

        $this->assertResponseRedirects();
        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(BankQuestion::class)->findOneBy(['question' => 'Vraag zonder goed antwoord']);
        $this->assertInstanceOf(BankQuestion::class, $saved);
        $this->assertFalse($saved->isCompleteForQuiz);
    }

    public function testEditBankQuestion(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $bankQuestion = $this->getBankQuestion('Wat at de Krtek als ontbijt?');

        $url = \sprintf('/backoffice/season/krtek/question-bank/%s/edit', $bankQuestion->id);
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        $this->assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="bank_question_form[_token]"]')->attr('value');

        $this->client->request(Request::METHOD_POST, $url, [
            'bank_question_form' => [
                'question' => 'Wat dronk de Krtek als ontbijt?',
                'answers' => [
                    ['text' => 'Koffie', 'isRightAnswer' => '1'],
                    ['text' => 'Thee'],
                ],
                '_token' => $token,
            ],
        ]);

        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $bankQuestion = $this->getBankQuestion('Wat dronk de Krtek als ontbijt?');
        $this->assertFalse($bankQuestion->reusable);
        $this->assertCount(2, $bankQuestion->answers);
    }

    public function testDeleteUsedBankQuestionLeavesQuizIntact(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $bankQuestion = $this->getBankQuestion('Waar sliep de Krtek?');
        $quiz2QuestionCount = $this->getQuizByName('Quiz 2')->questions->count();

        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');
        $token = $this->getCsrfTokenFromCurrentPage(\sprintf('%s/delete', $bankQuestion->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/question-bank/%s/delete', $bankQuestion->id), [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $this->assertNotInstanceOf(BankQuestion::class, $this->entityManager->getRepository(BankQuestion::class)->findOneBy(['question' => 'Waar sliep de Krtek?']));
        $this->assertCount($quiz2QuestionCount, $this->getQuizByName('Quiz 2')->questions);
    }

    public function testAssignCopiesQuestionIntoQuiz(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $bankQuestion = $this->getBankQuestion('Wat at de Krtek als ontbijt?');
        $quiz = $this->getQuizByName('Quiz 2');
        $questionCount = $quiz->questions->count();

        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');
        $token = $this->getCsrfTokenFromCurrentPage(\sprintf('%s/assign', $bankQuestion->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/question-bank/%s/assign', $bankQuestion->id), [
            '_token' => $token,
            'quiz' => (string) $quiz->id,
        ]);

        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $quiz = $this->getQuizByName('Quiz 2');
        $this->assertCount($questionCount + 1, $quiz->questions);

        $copiedQuestion = null;
        $maxOrdering = 0;
        foreach ($quiz->questions as $question) {
            $maxOrdering = max($maxOrdering, $question->ordering);
            if ('Wat at de Krtek als ontbijt?' === $question->question) {
                $copiedQuestion = $question;
            }
        }

        $this->assertInstanceOf(Question::class, $copiedQuestion);
        $this->assertSame($maxOrdering, $copiedQuestion->ordering);
        $this->assertCount(3, $copiedQuestion->answers);

        $bankQuestion = $this->getBankQuestion('Wat at de Krtek als ontbijt?');
        $this->assertTrue($bankQuestion->isUsed);
        $this->assertFalse($bankQuestion->canBeAssigned);
    }

    public function testAssignUsedNonReusableQuestionIsRefused(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $bankQuestion = $this->getBankQuestion('Waar sliep de Krtek?');
        $quiz = $this->getQuizByName('Quiz 2');
        $questionCount = $quiz->questions->count();

        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');

        // The assign form is not rendered for used questions, so post with another form's token
        $token = $this->getCsrfTokenFromCurrentPage('/assign');
        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/question-bank/%s/assign', $bankQuestion->id), [
            '_token' => $token,
            'quiz' => (string) $quiz->id,
        ]);

        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $this->assertCount($questionCount, $this->getQuizByName('Quiz 2')->questions);
    }

    public function testAssignSameReusableQuestionTwiceToSameQuizIsRefused(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $bankQuestion = $this->getBankQuestion('Wat is de bijnaam van de Krtek?');
        $quiz = $this->getQuizByName('Quiz 2');
        $questionCount = $quiz->questions->count();

        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');
        $token = $this->getCsrfTokenFromCurrentPage(\sprintf('%s/assign', $bankQuestion->id));

        $url = \sprintf('/backoffice/season/krtek/question-bank/%s/assign', $bankQuestion->id);
        $this->client->request(Request::METHOD_POST, $url, ['_token' => $token, 'quiz' => (string) $quiz->id]);
        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->client->request(Request::METHOD_POST, $url, ['_token' => $token, 'quiz' => (string) $quiz->id]);
        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $this->assertCount($questionCount + 1, $this->getQuizByName('Quiz 2')->questions);
    }

    public function testAssignIntoFinalizedQuizIsDenied(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $bankQuestion = $this->getBankQuestion('Wie is de Krtek?');
        $finalizedQuiz = $this->getQuizByName('Quiz 1');
        $this->assertTrue($finalizedQuiz->isFinalized);

        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');
        $token = $this->getCsrfTokenFromCurrentPage(\sprintf('%s/assign', $bankQuestion->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/question-bank/%s/assign', $bankQuestion->id), [
            '_token' => $token,
            'quiz' => (string) $finalizedQuiz->id,
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateBankQuestionPreservesAnswerOrdering(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $crawler = $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank/new');
        $this->assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="bank_question_form[_token]"]')->attr('value');

        // Submit 3 answers with non-sequential ordering values.
        // The stored ordering field (not the submission index) must dictate retrieval order.
        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/question-bank/new', [
            'bank_question_form' => [
                'question' => 'Volgorderingstest nieuwe vraag',
                'answers' => [
                    0 => ['text' => 'Antwoord C', 'isRightAnswer' => '1', 'ordering' => '5'],
                    1 => ['text' => 'Antwoord A', 'ordering' => '1'],
                    2 => ['text' => 'Antwoord B', 'ordering' => '3'],
                ],
                '_token' => $token,
            ],
        ]);

        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $bankQuestion = $this->getBankQuestion('Volgorderingstest nieuwe vraag');
        $answers = $bankQuestion->answers->toArray();
        $this->assertCount(3, $answers);
        // @OrderBy(['ordering' => 'ASC']): ordering 1 → 3 → 5
        $this->assertSame('Antwoord A', $answers[0]->text);
        $this->assertSame('Antwoord B', $answers[1]->text);
        $this->assertSame('Antwoord C', $answers[2]->text);
    }

    public function testEditBankQuestionPreservesAnswerOrdering(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $bankQuestion = $this->getBankQuestion('Wat at de Krtek als ontbijt?');
        // Fixture answers in insertion order (all have ordering=0): Brood (correct), Yoghurt, Niks

        $url = \sprintf('/backoffice/season/krtek/question-bank/%s/edit', $bankQuestion->id);
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        $this->assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="bank_question_form[_token]"]')->attr('value');

        $answers = $bankQuestion->answers->toArray();
        $this->assertCount(3, $answers);
        $texts = array_map(static fn (BankAnswer $a): string => $a->text, $answers);

        // Assign ordering values: first answer gets 4, second gets 0, third gets 2.
        // Expected retrieval order after @OrderBy ASC: index 1 (0) → index 2 (2) → index 0 (4).
        $this->client->request(Request::METHOD_POST, $url, [
            'bank_question_form' => [
                'question' => $bankQuestion->question,
                'answers' => [
                    0 => ['text' => $texts[0], 'isRightAnswer' => '1', 'ordering' => '4'],
                    1 => ['text' => $texts[1], 'ordering' => '0'],
                    2 => ['text' => $texts[2], 'ordering' => '2'],
                ],
                '_token' => $token,
            ],
        ]);

        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $bankQuestion = $this->getBankQuestion('Wat at de Krtek als ontbijt?');
        $reloadedAnswers = $bankQuestion->answers->toArray();
        $this->assertCount(3, $reloadedAnswers);
        $this->assertSame($texts[1], $reloadedAnswers[0]->text); // ordering=0 → first
        $this->assertSame($texts[2], $reloadedAnswers[1]->text); // ordering=2 → second
        $this->assertSame($texts[0], $reloadedAnswers[2]->text); // ordering=4 → third
    }

    public function testAddAndDeleteLabel(): void
    {
        $this->loginAs('krtek-admin@example.org');
        $crawler = $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');
        $token = (string) $crawler->filter('form[action$="/question-bank/labels"] input[name="_token"]')->attr('value');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/question-bank/labels', [
            '_token' => $token,
            'name' => 'Opdracht',
        ]);
        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $label = $this->entityManager->getRepository(QuestionLabel::class)->findOneBy(['name' => 'Opdracht']);
        $this->assertInstanceOf(QuestionLabel::class, $label);

        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/question-bank');
        $deleteToken = $this->getCsrfTokenFromCurrentPage(\sprintf('labels/%s/delete', $label->slug));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/question-bank/labels/%s/delete', $label->slug), [
            '_token' => $deleteToken,
        ]);
        $this->assertResponseRedirects('/backoffice/season/krtek/question-bank');

        $this->entityManager->clear();
        $this->assertNotInstanceOf(QuestionLabel::class, $this->entityManager->getRepository(QuestionLabel::class)->findOneBy(['name' => 'Opdracht']));
    }
}
