<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Safe\DateTime;
use Safe\DateTimeImmutable;
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

    public function testFindSoftDeletedForUserOnlyReturnsTheUsersDeletedSeasons(): void
    {
        $this->softDelete('krtek', new DateTime('-2 days'));
        $this->softDelete('bbbbb', new DateTime('-1 day'));

        $seasons = $this->seasonRepository->findSoftDeletedForUser($this->getUserByEmail('krtek-admin@example.org'));

        $this->assertSame(['krtek'], array_map(static fn (Season $s): string => $s->seasonCode, $seasons));
        // The filter must be back on afterwards, so deleted seasons stay hidden everywhere else.
        $this->assertCount(0, $this->seasonRepository->getSeasonsForUser($this->getUserByEmail('krtek-admin@example.org')));
    }

    public function testFindSoftDeletedForUserIgnoresActiveSeasons(): void
    {
        $this->assertSame([], $this->seasonRepository->findSoftDeletedForUser($this->getUserByEmail('user2@example.org')));
    }

    public function testFindAllSoftDeletedReturnsEveryDeletedSeasonNewestFirst(): void
    {
        $this->softDelete('krtek', new DateTime('-3 days'));
        $this->softDelete('bbbbb', new DateTime('-1 day'));

        $seasons = $this->seasonRepository->findAllSoftDeleted();

        $this->assertSame(['bbbbb', 'krtek'], array_map(static fn (Season $s): string => $s->seasonCode, $seasons));
    }

    public function testFindOneSoftDeletedBySeasonCodeOnlyFindsDeletedSeasons(): void
    {
        $this->assertNotInstanceOf(Season::class, $this->seasonRepository->findOneSoftDeletedBySeasonCode('krtek'));

        $this->softDelete('krtek', new DateTime());

        $this->assertInstanceOf(Season::class, $this->seasonRepository->findOneSoftDeletedBySeasonCode('krtek'));
        $this->assertNotInstanceOf(Season::class, $this->seasonRepository->findOneBySeasonCode('krtek'));
    }

    public function testFindSoftDeletedOlderThanOnlyReturnsSeasonsPastTheThreshold(): void
    {
        $this->softDelete('krtek', new DateTime('-40 days'));
        $this->softDelete('bbbbb', new DateTime('-5 days'));

        $seasons = $this->seasonRepository->findSoftDeletedOlderThan(new DateTimeImmutable('-31 days'));

        $this->assertSame(['krtek'], array_map(static fn (Season $s): string => $s->seasonCode, $seasons));
    }

    private function softDelete(string $seasonCode, \DateTime $deletedAt): void
    {
        $this->getSeasonByCode($seasonCode)->setDeletedAt($deletedAt);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testDeleteIfStillExpiredSkipsASeasonRestoredAfterItWasSelected(): void
    {
        $this->softDelete('krtek', new DateTime('-40 days'));
        $threshold = new DateTimeImmutable('-31 days');
        $selected = $this->seasonRepository->findSoftDeletedOlderThan($threshold)[0];

        // Someone restores it between the selection and the purge, behind the ORM's back.
        $this->entityManager->getConnection()->executeStatement("update season set deleted_at = null where season_code = 'krtek'");

        $this->assertFalse($this->seasonRepository->deleteIfStillExpired($selected, $threshold));
        $this->assertSame(1, $this->rowCount('krtek'));
    }

    public function testDeleteIfStillExpiredPurgesAnExpiredSeason(): void
    {
        $this->softDelete('krtek', new DateTime('-40 days'));
        $threshold = new DateTimeImmutable('-31 days');
        $selected = $this->seasonRepository->findSoftDeletedOlderThan($threshold)[0];

        $this->assertTrue($this->seasonRepository->deleteIfStillExpired($selected, $threshold));
        $this->assertSame(0, $this->rowCount('krtek'));
    }

    public function testRestoreReturnsFalseWhenTheSeasonWasPurgedMeanwhile(): void
    {
        // A childless season, so its row can be removed behind the ORM's back without FK violations.
        $season = new Season();
        $season->name = 'Short lived';
        $season->seasonCode = 'zzzzz';
        $season->setDeletedAt(new DateTime());

        $this->entityManager->persist($season);
        $this->entityManager->flush();

        $this->entityManager->getConnection()->executeStatement("delete from season where season_code = 'zzzzz'");

        $this->assertFalse($this->seasonRepository->restore($season));
    }

    private function rowCount(string $seasonCode): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('select count(*) from season where season_code = ?', [$seasonCode]);
    }
}
