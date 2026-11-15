<?php

declare(strict_types=1);

namespace Tvdt\Tests\E2E;

use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Tvdt\DataFixtures\TestFixtures;

abstract class AbstractE2ETestCase extends PantherTestCase
{
    protected function loginAsAdmin(): Client
    {
        $client = $this->visitLoginPageLoggedOut();

        $form = $client->getCrawler()->filter('form[name="login_form"]')->form([
            '_username' => 'krtek-admin@example.org',
            '_password' => TestFixtures::PASSWORD,
        ]);
        $client->submit($form);
        $client->waitFor('form[action="/logout"]');

        return $client;
    }

    /**
     * The browser session persists across tests within one run, so a prior test may still be
     * logged in — log out first so /login always renders the form instead of redirecting away.
     */
    protected function visitLoginPageLoggedOut(): Client
    {
        $client = self::createPantherClient(['browser' => self::FIREFOX]);
        $client->request('GET', '/login');
        if ($client->getCrawler()->filter('form[action="/logout"]')->count() > 0) {
            $client->getCrawler()->filter('form[action="/logout"] button')->click();
        }

        $client->request('GET', '/login');

        return $client;
    }
}
