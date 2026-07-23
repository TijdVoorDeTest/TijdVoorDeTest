<?php

declare(strict_types=1);

namespace Tvdt\Tests\E2E;

use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

abstract class AbstractE2ETestCase extends PantherTestCase
{
    protected function loginAsAdmin(): Client
    {
        $client = $this->visitLoginPageLoggedOut();

        $form = $client->getCrawler()->filter('form')->form([
            '_username' => 'krtek-admin@example.org',
            '_password' => 'test1234',
        ]);
        $client->submit($form);
        $client->waitFor('a[href="/logout"]');

        return $client;
    }

    /**
     * The browser session persists across tests within one run, so a prior test may still be
     * logged in — log out first so /login always renders the form instead of redirecting away.
     */
    protected function visitLoginPageLoggedOut(): Client
    {
        $client = self::createPantherClient();
        $client->request('GET', '/logout');
        $client->request('GET', '/login');

        return $client;
    }
}
