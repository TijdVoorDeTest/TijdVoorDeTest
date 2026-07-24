<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\SeasonController;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\EliminationScreenView;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Enum\ScreenColour;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(SeasonController::class)]
final class SeasonControllerTest extends AbstractControllerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs('krtek-admin@example.org');
    }

    public function testRegenerateSeasonCodeChangesTheCode(): void
    {
        $oldCode = 'krtek';
        $token = $this->getCsrfTokenFromPage(\sprintf('/backoffice/season/%s/settings', $oldCode), '/regenerate-code');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/%s/settings/regenerate-code', $oldCode), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        $this->entityManager->clear();

        $this->assertNotInstanceOf(Season::class, $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => $oldCode]));

        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertMatchesRegularExpression('#^/backoffice/season/[a-z]{5}/settings$#', $location);
    }

    public function testRegenerateSeasonCodeIsDeniedForNonOwner(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/regenerate-code');

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/regenerate-code', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRenameCandidate(): void
    {
        $candidate = $this->getCandidate('Tom');
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/candidates', \sprintf('/candidate/%s/rename', $candidate->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/candidate/%s/rename', $candidate->id), [
            '_token' => $token,
            'name' => 'Tommy',
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/candidates');
        $this->entityManager->clear();

        $renamed = $this->entityManager->getRepository(Candidate::class)->find($candidate->id);
        $this->assertInstanceOf(Candidate::class, $renamed);
        $this->assertSame('Tommy', $renamed->name);
    }

    public function testRenameCandidateToExistingNameShowsError(): void
    {
        $candidate = $this->getCandidate('Tom');
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/candidates', \sprintf('/candidate/%s/rename', $candidate->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/candidate/%s/rename', $candidate->id), [
            '_token' => $token,
            'name' => 'Claudia',
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/candidates');
        $this->entityManager->clear();

        $unchanged = $this->entityManager->getRepository(Candidate::class)->find($candidate->id);
        $this->assertInstanceOf(Candidate::class, $unchanged);
        $this->assertSame('Tom', $unchanged->name);
    }

    public function testDeleteCandidate(): void
    {
        $candidate = $this->getCandidate('Tom');
        $candidateId = $candidate->id;
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/candidates', \sprintf('/candidate/%s/delete', $candidate->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/candidate/%s/delete', $candidate->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/candidates');
        $this->entityManager->clear();

        $this->assertNotInstanceOf(Candidate::class, $this->entityManager->getRepository(Candidate::class)->find($candidateId));
    }

    public function testDeleteCandidateCascadesEliminationScreenViews(): void
    {
        $candidate = $this->getCandidate('Tom');
        $candidateId = $candidate->id;
        $quiz = $this->entityManager->getRepository(Quiz::class)->findOneBy(['name' => 'Quiz 1']);
        $this->assertInstanceOf(Quiz::class, $quiz);

        $elimination = new Elimination($quiz);
        $elimination->data = ['Tom' => ScreenColour::Green->value];

        $screenView = new EliminationScreenView($elimination, $candidate, ScreenColour::Green);

        $this->entityManager->persist($elimination);
        $this->entityManager->persist($screenView);
        $this->entityManager->flush();

        $screenViewId = $screenView->id;

        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/candidates', \sprintf('/candidate/%s/delete', $candidate->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/candidate/%s/delete', $candidate->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/candidates');
        $this->entityManager->clear();

        $this->assertNotInstanceOf(Candidate::class, $this->entityManager->getRepository(Candidate::class)->find($candidateId));
        $this->assertNotInstanceOf(EliminationScreenView::class, $this->entityManager->getRepository(EliminationScreenView::class)->find($screenViewId));
    }

    public function testAddCandidates(): void
    {
        $this->client->request(Request::METHOD_GET, '/backoffice/season/krtek/add-candidate');
        $form = $this->client->getCrawler()->filter('form')->form([
            'add_candidates_form[candidates]' => "Nora\nPiet",
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/backoffice/season/krtek/candidates');
        $this->entityManager->clear();

        $season = $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => 'krtek']);
        $this->assertInstanceOf(Season::class, $season);
        $names = array_map(static fn (Candidate $candidate): string => $candidate->name, $season->candidates->toArray());
        $this->assertContains('Nora', $names);
        $this->assertContains('Piet', $names);
    }

    public function testAddCandidatesViaTurboFrameReturnsEmptyFrame(): void
    {
        $this->client->xmlHttpRequest(Request::METHOD_GET, '/backoffice/season/krtek/add-candidate', server: ['HTTP_TURBO-FRAME' => 'add-candidates-modal-frame']);
        $form = $this->client->getCrawler()->filter('form')->form([
            'add_candidates_form[candidates]' => 'Sanne',
        ]);
        $this->client->submit($form, [], ['HTTP_TURBO-FRAME' => 'add-candidates-modal-frame']);

        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('<turbo-frame id="add-candidates-modal-frame"></turbo-frame>', (string) $this->client->getResponse()->getContent());
        $this->entityManager->clear();

        $season = $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => 'krtek']);
        $this->assertInstanceOf(Season::class, $season);
        $names = array_map(static fn (Candidate $candidate): string => $candidate->name, $season->candidates->toArray());
        $this->assertContains('Sanne', $names);
    }

    public function testRenameCandidateIsDeniedForNonOwner(): void
    {
        $candidate = $this->getCandidate('Tom');
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/candidates', \sprintf('/candidate/%s/rename', $candidate->id));

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/candidate/%s/rename', $candidate->id), [
            '_token' => $token,
            'name' => 'Tommy',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
