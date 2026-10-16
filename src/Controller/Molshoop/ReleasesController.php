<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Safe\DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tvdt\Controller\AbstractController;
use Tvdt\Service\GitHubReleasesService;

final class ReleasesController extends AbstractController
{
    public function __construct(
        private readonly GitHubReleasesService $gitHubReleasesService,
        #[Autowire(env: 'default::GIT_SHA')]
        private readonly ?string $gitSha,
        #[Autowire(env: 'default::GIT_COMMIT_TIME')]
        private readonly ?string $gitCommitTime,
        #[Autowire(env: 'default::GIT_TAG')]
        private readonly ?string $gitTag,
    ) {}

    #[Route('/molshoop/releases', name: 'tvdt_molshoop_releases', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('molshoop/releases/_frame.html.twig', [
            'releases' => $this->gitHubReleasesService->getReleases(),
            'gitTag' => $this->gitTag,
            'gitSha' => $this->gitSha,
            'gitCommitTime' => $this->gitCommitTime ? new DateTimeImmutable($this->gitCommitTime) : null,
        ]);
    }
}
