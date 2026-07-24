<?php

declare(strict_types=1);

namespace Tvdt\Tests\E2E;

use Facebook\WebDriver\WebDriverElement;

/**
 * The question-edit modal loads its form via a turbo-frame (`data-src` + Turbo, not static
 * markup) and, on successful submit, closes via a `turbo:submit-end` JS callback that triggers
 * a full-page Turbo visit — a fundamentally different mechanism than the plain
 * `data-bs-toggle="modal"` covered by QuizRenameE2ETest. The answer-row "auto-expand" behavior
 * (typing into the last answer appends a brand-new DOM node client-side, via
 * bo--form-collection's autoExpand()) is also invisible to WebTestCase, which only ever sees
 * the initial server-rendered form.
 */
final class QuestionEditModalE2ETest extends AbstractE2ETestCase
{
    public function testEditingLastAnswerAutoExpandsANewRowAndSavesThroughTheTurboFrame(): void
    {
        $client = $this->loginAsAdmin();
        $client->request('GET', '/backoffice/season/krtek');

        // Quiz 3 is still a Concept (not finalized), so its questions are editable — Quiz 1 is
        // finalized/locked and would only offer a read-only "View" modal, no form.
        $link = $client->getCrawler()->selectLink('Quiz 3')->link();
        $client->click($link);
        $client->waitForElementToContain('h2', 'Quiz 3');

        $questionCard = $client->getCrawler()->filterXPath(
            "//div[@data-question-id][contains(., 'Is de Krtek een man of een vrouw?')]",
        );
        $questionCard->filter('button')->click();

        $client->waitFor('#question_form_question');

        $rows = $client->getCrawler()->filter('[data-bo--form-collection-target="collection"] [data-collection-item]');
        $this->assertCount(2, $rows);

        $lastAnswer = $rows->last()->filter('input[type="text"]')->getElement(0);
        $this->assertInstanceOf(WebDriverElement::class, $lastAnswer);
        $lastAnswer->sendKeys('!');

        $client->waitFor('[data-bo--form-collection-target="collection"] [data-collection-item]:nth-child(3)');
        $rowsAfterExpand = $client->getCrawler()->filter('[data-bo--form-collection-target="collection"] [data-collection-item]');
        $this->assertCount(3, $rowsAfterExpand);

        $client->getCrawler()->filter('#question-modal-frame .modal-footer button[type="submit"]')->click();
        $client->waitForInvisibility('.modal.show');

        self::assertSelectorTextContains('h2', 'Quiz 3');
        self::assertSelectorTextContains('body', 'Is de Krtek een man of een vrouw?');
    }
}
