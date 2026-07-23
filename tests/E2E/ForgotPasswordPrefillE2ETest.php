<?php

declare(strict_types=1);

namespace Tvdt\Tests\E2E;

/**
 * The forgot-password Stimulus controller mutates the "Forgot your password?" link's href
 * client-side as the user types their email (see forgot_password_controller.ts). There is no
 * server round-trip involved, so a WebTestCase crawler — which only ever sees the initial
 * server-rendered HTML — can never observe this; only a real browser executing the JS can.
 */
final class ForgotPasswordPrefillE2ETest extends AbstractE2ETestCase
{
    public function testTypingEmailPrefillsForgotPasswordLink(): void
    {
        $client = $this->visitLoginPageLoggedOut();

        $client->getCrawler()->filter('#username')->sendKeys('krtek-admin@example.org');

        $href = $client->getCrawler()->filter('a[data-forgot-password-target="link"]')->getElement(0)?->getAttribute('href');

        $this->assertNotNull($href);
        $this->assertStringContainsString('email=krtek-admin%40example.org', $href);
    }
}
