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

    public function testAddOwnerByEmailGrantsOwnership(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/add-owner');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/add-owner', [
            '_token' => $token,
            'email' => 'test@example.org',
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/settings');
        $this->entityManager->clear();

        $newOwner = $this->getUserByEmail('test@example.org');
        $season = $this->getSeasonByCode('krtek');
        $this->assertTrue($season->isOwner($newOwner));
    }

    public function testAddOwnerWithUnknownEmailShowsWarningAndDoesNotChangeOwners(): void
    {
        $ownerCountBefore = $this->getSeasonByCode('krtek')->owners->count();

        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/add-owner');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/add-owner', [
            '_token' => $token,
            'email' => 'nobody@example.org',
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/settings');
        $this->entityManager->clear();

        $this->assertCount($ownerCountBefore, $this->getSeasonByCode('krtek')->owners);
    }

    public function testAddOwnerIsDeniedForNonOwner(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/add-owner');

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/add-owner', [
            '_token' => $token,
            'email' => 'test@example.org',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRemoveOwnerRemovesOwnership(): void
    {
        $user2 = $this->getUserByEmail('user2@example.org');
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', \sprintf('/owner/%s/remove', $user2->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/settings/owner/%s/remove', $user2->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/settings');
        $this->entityManager->clear();

        $user2 = $this->getUserByEmail('user2@example.org');
        $this->assertFalse($this->getSeasonByCode('krtek')->isOwner($user2));
    }

    public function testRemoveOwnerOfNonMemberDoesNothing(): void
    {
        $nonMember = $this->getUserByEmail('test@example.org');
        $this->assertFalse($this->getSeasonByCode('krtek')->isOwner($nonMember));
        $ownerCountBefore = $this->getSeasonByCode('krtek')->owners->count();

        // The CSRF token for 'remove_season_owner' isn't tied to a specific owner id, so it's
        // safe to fetch it from an existing owner's remove form and reuse it against $nonMember.
        $user2 = $this->getUserByEmail('user2@example.org');
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', \sprintf('/owner/%s/remove', $user2->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/settings/owner/%s/remove', $nonMember->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/settings');
        $this->entityManager->clear();

        $this->assertCount($ownerCountBefore, $this->getSeasonByCode('krtek')->owners);
    }

    public function testRemoveOwnerIsBlockedWhenItWouldLeaveNoOwners(): void
    {
        $this->loginAs('sole-owner@example.org');
        $soleOwner = $this->getUserByEmail('sole-owner@example.org');

        $token = $this->getCsrfTokenFromPage('/backoffice/season/doomd/settings', \sprintf('/owner/%s/remove', $soleOwner->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/doomd/settings/owner/%s/remove', $soleOwner->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/season/doomd/settings');
        $this->entityManager->clear();

        $soleOwner = $this->getUserByEmail('sole-owner@example.org');
        $this->assertTrue($this->getSeasonByCode('doomd')->isOwner($soleOwner));
    }

    public function testLeaveSeasonRemovesOwnershipAndRedirectsToIndex(): void
    {
        $krtekAdmin = $this->getUserByEmail('krtek-admin@example.org');

        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', \sprintf('/owner/%s/remove', $krtekAdmin->id));

        $this->client->request(Request::METHOD_POST, \sprintf('/backoffice/season/krtek/settings/owner/%s/remove', $krtekAdmin->id), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/backoffice/');
        $this->entityManager->clear();

        $krtekAdmin = $this->getUserByEmail('krtek-admin@example.org');
        $this->assertFalse($this->getSeasonByCode('krtek')->isOwner($krtekAdmin));
    }

    public function testDeleteSeasonWithCorrectConfirmationSoftDeletesSeason(): void
    {
        $seasonId = $this->getSeasonByCode('krtek')->id;
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/settings/delete');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/delete', [
            '_token' => $token,
            'confirmation' => 'verwijderen',
        ]);

        self::assertResponseRedirects('/backoffice/');
        $this->entityManager->clear();

        // Filtered lookups must no longer see it...
        $this->assertNotInstanceOf(Season::class, $this->entityManager->getRepository(Season::class)->find($seasonId));

        // ...but the row itself must still exist with deletedAt set — a soft delete, not a hard one.
        $connection = $this->entityManager->getConnection();
        $this->assertSame(
            1,
            (int) $connection->fetchOne('select count(*) from season where id = ? and deleted_at is not null', [$seasonId->toString()]),
        );
    }

    public function testDeleteSeasonAcceptsTheEnglishConfirmationWord(): void
    {
        $seasonId = $this->getSeasonByCode('krtek')->id;
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/settings/delete');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/delete', [
            '_token' => $token,
            'confirmation' => 'delete',
        ]);

        self::assertResponseRedirects('/backoffice/');
        $this->entityManager->clear();

        $this->assertNotInstanceOf(Season::class, $this->entityManager->getRepository(Season::class)->find($seasonId));
    }

    public function testDeleteSeasonWithWrongConfirmationKeepsSeason(): void
    {
        $seasonId = $this->getSeasonByCode('krtek')->id;
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/settings/delete');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/delete', [
            '_token' => $token,
            'confirmation' => 'wrong',
        ]);

        self::assertResponseRedirects('/backoffice/season/krtek/settings');
        $this->entityManager->clear();

        $this->assertInstanceOf(Season::class, $this->entityManager->getRepository(Season::class)->find($seasonId));
    }

    public function testDeleteSeasonIsDeniedForNonOwner(): void
    {
        $token = $this->getCsrfTokenFromPage('/backoffice/season/krtek/settings', '/settings/delete');

        $this->loginAs('test@example.org');

        $this->client->request(Request::METHOD_POST, '/backoffice/season/krtek/settings/delete', [
            '_token' => $token,
            'confirmation' => 'verwijderen',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
