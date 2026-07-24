<?php

declare(strict_types=1);

namespace Tvdt\Tests\Integration\Controller\Backoffice;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Tvdt\Controller\Backoffice\BackofficeController;
use Tvdt\Tests\Integration\Controller\AbstractControllerWebTestCase;

#[CoversClass(BackofficeController::class)]
final class BackofficeControllerTest extends AbstractControllerWebTestCase
{
    public function testExportQuizFilenameIsSanitized(): void
    {
        $user = $this->getUserByEmail('user2@example.org');
        $user->isVerified = true;

        $this->entityManager->flush();
        $this->client->loginUser($user);

        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/quiz/%s/export', $quiz->id));

        self::assertResponseIsSuccessful();
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('filename=Quiz-1.xlsx', $disposition);
        $this->assertStringNotContainsString('Quiz 1.xlsx', $disposition);
    }

    public function testExportQuizRequiresVerifiedEmail(): void
    {
        $user = $this->getUserByEmail('user2@example.org');
        $this->assertFalse($user->isVerified);
        $this->client->loginUser($user);

        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/quiz/%s/export', $quiz->id));

        self::assertResponseRedirects(\sprintf('/backoffice/season/%s', $quiz->season->seasonCode));
    }

    public function testExportQuizIsDeniedForNonOwner(): void
    {
        $this->loginAs('test@example.org');

        $quiz = $this->getQuizByName('Quiz 1');

        $this->client->request(Request::METHOD_GET, \sprintf('/backoffice/quiz/%s/export', $quiz->id));

        self::assertResponseStatusCodeSame(403);
    }
}
