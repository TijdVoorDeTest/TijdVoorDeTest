<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\EliminationController;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\EliminationScreenView;
use Tvdt\Enum\ScreenColour;
use Tvdt\Helpers\Base64;

#[CoversClass(EliminationController::class)]
final class EliminationControllerTest extends AbstractControllerWebTestCase
{
    private Elimination $elimination;

    protected function setUp(): void
    {
        parent::setUp();

        $quiz = $this->getQuizByName('Quiz 1');

        $this->elimination = new Elimination($quiz);
        $this->elimination->data = ['Tom' => ScreenColour::Green->value];

        $this->entityManager->persist($this->elimination);
        $this->entityManager->flush();

        $this->loginAs('krtek-admin@example.org');
    }

    public function testIndexIsDeniedForNonOwner(): void
    {
        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s', $this->elimination->id));

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexPageLoads(): void
    {
        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s', $this->elimination->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testIndexRedirectsToCandidateScreen(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s', $this->elimination->id));
        $form = $crawler->filter('form')->form([
            'elimination_enter_name[name]' => 'Tom',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects(\sprintf('/elimination/%s/%s', $this->elimination->id, Base64::base64UrlEncode('Tom')));
    }

    public function testCandidateScreenUnknownCandidateRedirectsWithFlash(): void
    {
        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s/%s', $this->elimination->id, Base64::base64UrlEncode('Nobody')));

        self::assertResponseRedirects(\sprintf('/elimination/%s', $this->elimination->id));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Kon kandidaat met naam Nobody niet vinden');
    }

    public function testCandidateScreenCandidateNotInEliminationDataRedirectsWithFlash(): void
    {
        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s/%s', $this->elimination->id, Base64::base64UrlEncode('Claudia')));

        self::assertResponseRedirects(\sprintf('/elimination/%s', $this->elimination->id));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Kon geen kandidaat vinden met de naam Claudia in de eliminatie');
    }

    public function testCandidateScreenRendersColour(): void
    {
        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s/%s', $this->elimination->id, Base64::base64UrlEncode('Tom')));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('#%s', ScreenColour::Green->value));
    }

    public function testCandidateScreenRecordsScreenView(): void
    {
        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s/%s', $this->elimination->id, Base64::base64UrlEncode('Tom')));

        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $eliminationId = $this->elimination->id;
        $screenViews = $this->entityManager->getRepository(EliminationScreenView::class)->findBy(['elimination' => $eliminationId]);

        $this->assertCount(1, $screenViews);
        $this->assertSame('Tom', $screenViews[0]->candidate->name);
        $this->assertSame(ScreenColour::Green, $screenViews[0]->colour);
    }

    public function testCandidateScreenRecordsScreenViewsInOrder(): void
    {
        $this->elimination->data = ['Tom' => ScreenColour::Green->value, 'Claudia' => ScreenColour::Red->value];
        $this->entityManager->flush();

        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s/%s', $this->elimination->id, Base64::base64UrlEncode('Claudia')));
        $this->client->request(Request::METHOD_GET, \sprintf('/elimination/%s/%s', $this->elimination->id, Base64::base64UrlEncode('Tom')));

        $this->entityManager->clear();
        $elimination = $this->entityManager->getRepository(Elimination::class)->find($this->elimination->id);
        $this->assertInstanceOf(Elimination::class, $elimination);
        $names = array_map(static fn (EliminationScreenView $screenView): string => $screenView->candidate->name, $elimination->screenViews->toArray());

        $this->assertSame(['Claudia', 'Tom'], $names);
    }
}
