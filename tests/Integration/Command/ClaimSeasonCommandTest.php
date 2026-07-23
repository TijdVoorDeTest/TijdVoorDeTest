<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Tvdt\Command\ClaimSeasonCommand;
use Tvdt\Entity\Season;
use Tvdt\Repository\SeasonRepository;

#[CoversClass(ClaimSeasonCommand::class)]
final class ClaimSeasonCommandTest extends KernelTestCase
{
    private SeasonRepository $seasonRepository;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $container = self::getContainer();

        $this->assertInstanceOf(KernelInterface::class, self::$kernel);

        $this->seasonRepository = $container->get(SeasonRepository::class);
        $application = new Application(self::$kernel);
        $command = $application->find('tvdt:claim-season');
        $this->commandTester = new CommandTester($command);
    }

    public function testSeasonClaim(): void
    {
        $this->commandTester->execute([
            'season-code' => 'krtek',
            'email' => 'test@example.org',
        ]);

        $season = $this->seasonRepository->findOneBySeasonCode('krtek');

        $this->assertInstanceOf(Season::class, $season);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertCount(3, $season->owners);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidArgumentsProvider(): iterable
    {
        yield 'unknown email' => ['krtek', 'nonexisting@example.org'];
        yield 'unknown season' => ['dhadk', 'test@example.org'];
    }

    #[DataProvider('invalidArgumentsProvider')]
    public function testInvalidArgumentFails(string $seasonCode, string $email): void
    {
        $this->commandTester->execute([
            'season-code' => $seasonCode,
            'email' => $email,
        ]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
    }
}
