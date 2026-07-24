<?php

declare(strict_types=1);

namespace Tvdt\Tests\E2E;

use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;

/**
 * The elimination red/green screen (templates/quiz/elimination/candidate.html.twig) is a plain
 * <img> with no href or form — the only way to advance is a JS click/keydown handler
 * (elimination_controller.ts) that does `window.location.href = ...` directly. There is nothing
 * for a WebTestCase crawler to click or submit here; only a real browser can exercise it.
 */
final class EliminationScreenE2ETest extends AbstractE2ETestCase
{
    public function testClickingTheScreenNavigatesBackToTheNameEntryForm(): void
    {
        $client = $this->loginAsAdmin();
        $client->request('GET', '/backoffice/season/krtek');

        $link = $client->getCrawler()->selectLink('Quiz 4')->link();
        $client->click($link);
        $client->waitForElementToContain('h2', 'Quiz 4');

        $resultsLink = $client->getCrawler()->selectLink('Resultaat & Eliminatie')->link();
        $client->click($resultsLink);
        $client->waitForElementToContain('h4', 'Score');

        $prepareButton = $client->getCrawler()->selectButton('Bereid aangepaste eliminatie voor');
        $client->submit($prepareButton->form());
        $client->waitForElementToContain('h2', 'Bereid eliminatie voor');

        $startButton = $client->getCrawler()->selectButton('Opslaan en eliminatie starten');
        $client->submit($startButton->form());
        $client->waitFor('input[name$="[name]"]');

        $nameInput = $client->getCrawler()->filter('input[name$="[name]"]')->getElement(0);
        $this->assertInstanceOf(WebDriverElement::class, $nameInput);
        $nameInput->sendKeys('Elise');
        $nameInput->sendKeys(WebDriverKeys::ENTER);

        $client->waitFor('.elimination-screen');

        $screen = $client->getCrawler()->filter('.elimination-screen');
        $this->assertContains($screen->attr('id'), ['green', 'red']);

        $screen->click();
        $client->waitFor('input[name$="[name]"]');

        self::assertSelectorNotExists('.elimination-screen');
    }
}
