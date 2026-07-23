<?php

declare(strict_types=1);

namespace Tvdt\Tests\E2E;

use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

abstract class AbstractE2ETestCase extends PantherTestCase
{
    protected function loginAsAdmin(): Client
    {
        $client = self::createPantherClient();
        // The browser session persists across tests within one run, so a prior test may still
        // be logged in — log out first so /login always renders the form instead of redirecting.
        $client->request('GET', '/logout');

        $crawler = $client->request('GET', '/login');

        $form = $crawler->filter('form')->form([
            '_username' => 'krtek-admin@example.org',
            '_password' => 'test1234',
        ]);
        $client->submit($form);
        $client->waitFor('a[href="/logout"]');

        return $client;
    }
}
