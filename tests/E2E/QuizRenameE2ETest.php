<?php

declare(strict_types=1);

namespace Tvdt\Tests\E2E;

use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;
use Symfony\Component\Panther\Client;

final class QuizRenameE2ETest extends AbstractE2ETestCase
{
    public function testHoverRevealsPencilAndRenameModalRenamesQuiz(): void
    {
        $client = $this->openQuiz1RenameModal();

        $nameInput = $client->getCrawler()->filter('#renameQuizModal input[name="name"]')->getElement(0);
        $this->assertInstanceOf(WebDriverElement::class, $nameInput);
        $nameInput->clear();
        $nameInput->sendKeys('Quiz 1 Renamed');
        $client->getCrawler()->filter('#renameQuizModal button[type="submit"]')->click();

        $client->waitForVisibility('.alert');
        self::assertSelectorTextContains('.alert', 'Test hernoemd');
        self::assertSelectorTextContains('h2', 'Quiz 1 Renamed');
    }

    /**
     * The dirty-tracking behind this (bo--modal's markDirty/resetDirty, see CLAUDE.md) is pure
     * client-side JS state with no server round-trip, so it's only observable by driving a real
     * browser — a WebTestCase crawler never executes it.
     */
    public function testTypingIntoTheModalBlocksEscapeDismissal(): void
    {
        $client = $this->openQuiz1RenameModal();

        $nameInput = $client->getCrawler()->filter('#renameQuizModal input[name="name"]')->getElement(0);
        $this->assertInstanceOf(WebDriverElement::class, $nameInput);
        $nameInput->sendKeys('Unsaved edit');
        $nameInput->sendKeys(WebDriverKeys::ESCAPE);

        self::assertSelectorExists('#renameQuizModal.show');
        $this->assertStringContainsString('Unsaved edit', $nameInput->getAttribute('value') ?? '');
    }

    private function openQuiz1RenameModal(): Client
    {
        $client = $this->loginAsAdmin();
        $client->request('GET', '/backoffice/season/krtek');

        $link = $client->getCrawler()->selectLink('Quiz 1')->link();
        $client->click($link);
        $client->waitForElementToContain('h2', 'Quiz 1');

        $heading = $client->getCrawler()->filter('h2.rename-trigger-host')->getElement(0);
        $this->assertInstanceOf(WebDriverElement::class, $heading);
        new WebDriverActions($client)->moveToElement($heading)->perform();

        $client->getCrawler()->filter('.rename-trigger')->click();
        $client->waitForVisibility('#renameQuizModal.show');

        return $client;
    }
}
