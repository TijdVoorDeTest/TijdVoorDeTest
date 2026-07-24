<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tvdt\Controller\AbstractController;
use Tvdt\Service\GitHubReleasesService;

final class ReleasesController extends AbstractController
{
    public function __construct(
        private readonly GitHubReleasesService $gitHubReleasesService,
    ) {}

    #[Route('/molshoop/releases', name: 'tvdt_molshoop_releases', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('molshoop/releases/_frame.html.twig', [
            'releases' => $this->gitHubReleasesService->getReleases(),
        ]);
    }
}
