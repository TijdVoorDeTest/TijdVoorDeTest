<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Command;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Safe\DateTime;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tvdt\Command\PurgeDeletedSeasonsCommand;
use Tvdt\Entity\Season;

#[CoversClass(PurgeDeletedSeasonsCommand::class)]
final class PurgeDeletedSeasonsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->commandTester = new CommandTester(new Application($kernel)->find('tvdt:purge-deleted-seasons'));

        $this->softDelete('krtek', new DateTime(\sprintf('-%d days', Season::RETENTION_DAYS + 1)));
        $this->softDelete('bbbbb', new DateTime(\sprintf('-%d days', Season::RETENTION_DAYS - 1)));
    }

    public function testPurgesOnlySeasonsPastTheRetentionPeriod(): void
    {
        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertSame(0, $this->rowCount('krtek'));
        $this->assertSame(1, $this->rowCount('bbbbb'));

        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('krtek', $display);
        $this->assertStringContainsString('krtek-admin@example.org', $display);
        $this->assertStringNotContainsString('bbbbb', $display);
    }

    public function testDryRunListsButDoesNotDelete(): void
    {
        $this->commandTester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('krtek', $this->commandTester->getDisplay());
        $this->assertSame(1, $this->rowCount('krtek'));
    }

    public function testNothingToPurgeSucceeds(): void
    {
        $this->commandTester->execute([]);
        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertSame(1, $this->rowCount('bbbbb'));
    }

    private function softDelete(string $seasonCode, \DateTime $deletedAt): void
    {
        $season = $this->entityManager->getRepository(Season::class)->findOneBy(['seasonCode' => $seasonCode]);
        $this->assertInstanceOf(Season::class, $season);
        $season->setDeletedAt($deletedAt);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /** Raw count: the filtered ORM lookups cannot tell soft-deleted from really gone. */
    private function rowCount(string $seasonCode): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('select count(*) from season where season_code = ?', [$seasonCode]);
    }
}
