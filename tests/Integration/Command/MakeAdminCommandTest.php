<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Tvdt\Command\MakeAdminCommand;
use Tvdt\Entity\User;
use Tvdt\Repository\UserRepository;

#[CoversClass(MakeAdminCommand::class)]
final class MakeAdminCommandTest extends KernelTestCase
{
    private UserRepository $userRepository;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $container = self::getContainer();

        $this->assertInstanceOf(KernelInterface::class, self::$kernel);

        $this->userRepository = $container->get(UserRepository::class);
        $application = new Application(self::$kernel);
        $command = $application->find('tvdt:make-admin');
        $this->commandTester = new CommandTester($command);
    }

    public function testMakeAdmin(): void
    {
        $this->commandTester->execute([
            'email' => 'test@example.org',
        ]);

        $user = $this->userRepository->findOneBy(['email' => 'test@example.org']);
        $this->assertInstanceOf(User::class, $user);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertContains('ROLE_ADMIN', $user->roles);
    }

    public function testInvalidEmailFails(): void
    {
        $this->commandTester->execute([
            'email' => 'nonexisting@example.org',
        ]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
    }
}
