<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\Season;
use Tvdt\Repository\SeasonRepository;

#[CoversClass(SeasonRepository::class)]
final class SeasonRepositoryTest extends DatabaseTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function userSeasonsProvider(): iterable
    {
        yield 'krtek admin' => ['krtek-admin@example.org', 'krtek'];
        yield 'user1' => ['user1@example.org', 'bbbbb'];
    }

    #[DataProvider('userSeasonsProvider')]
    public function testGetSeasonsForUser(string $email, string $expectedSeasonCode): void
    {
        $user = $this->getUserByEmail($email);

        $seasons = $this->seasonRepository->getSeasonsForUser($user);
        $this->assertCount(1, $seasons);
        $this->assertSame($expectedSeasonCode, $seasons[0]->seasonCode);
    }

    public function testUserWithMultipleSeasons(): void
    {
        $user = $this->getUserByEmail('user2@example.org');
        $seasons = $this->seasonRepository->getSeasonsForUser($user);

        $this->assertCount(2, $seasons);
        $this->assertSame('bbbbb', $seasons[0]->seasonCode);
        $this->assertSame('krtek', $seasons[1]->seasonCode);
    }

    public function testGetSeasonsForUserWithoutSeasonsReturnsEmpty(): void
    {
        $user = $this->getUserByEmail('test@example.org');

        $seasons = $this->seasonRepository->getSeasonsForUser($user);
        $this->assertEmpty($seasons);
    }

    public function testFindOneBySeasonCode(): void
    {
        $season = $this->seasonRepository->findOneBySeasonCode('krtek');
        $this->assertInstanceOf(Season::class, $season);
        $this->assertSame('krtek', $season->seasonCode);
    }

    public function testFindOneBySeasonCodeUnknownSeasonReturnsNull(): void
    {
        $season = $this->seasonRepository->findOneBySeasonCode('invalid');
        $this->assertNotInstanceOf(Season::class, $season);
    }

    /**
     * deleteSeason() must purge Gedmo\Loggable audit rows even when the season being deleted
     * isn't the caller's sole season (that path is also exercised indirectly via
     * UserRepository::deleteUser() for a sole-owner account, but a direct "delete this season"
     * action needs the same cleanup on a season with multiple owners).
     */
    public function testDeleteSeasonPurgesBankQuestionAuditLogOnAMultiOwnerSeason(): void
    {
        $season = $this->getSeasonByCode('bbbbb');
        $this->assertGreaterThan(1, $season->owners->count());
        $seasonId = $season->id->toString();

        $bankQuestion = new BankQuestion();
        $bankQuestion->question = 'Wie is de Krtek eigenlijk?';
        $bankQuestion->season = $season;

        $this->entityManager->persist($bankQuestion);
        $this->entityManager->flush();

        $bankQuestionId = $bankQuestion->id->toString();
        $connection = $this->entityManager->getConnection();

        $logCountBefore = (int) $connection->fetchOne(
            'select count(*) from ext_log_entries where object_class = ? and object_id = ?',
            [BankQuestion::class, $bankQuestionId],
        );
        $this->assertGreaterThan(0, $logCountBefore);

        $this->seasonRepository->deleteSeason($season);
        $this->entityManager->clear();

        // Season is Gedmo\SoftDeleteable, so findOneBySeasonCode() (filtered) would return null
        // even for a merely soft-deleted row — only a raw row count proves it's truly gone.
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from season where id = ?', [$seasonId]));

        $logCountAfter = (int) $connection->fetchOne(
            'select count(*) from ext_log_entries where object_class = ? and object_id = ?',
            [BankQuestion::class, $bankQuestionId],
        );
        $this->assertSame(0, $logCountAfter);
    }
}
