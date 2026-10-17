<?php

declare(strict_types=1);

namespace Tvdt\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/** Tells templates which environment this build is: tagged is production (null), untagged with a commit is acceptance. */
final readonly class BuildEnvironmentExtension
{
    public function __construct(
        #[Autowire(env: 'default::GIT_SHA')]
        private ?string $gitSha,
        #[Autowire(env: 'default::GIT_TAG')]
        private ?string $gitTag,
    ) {}

    /** Returns a translation key, or null on production. */
    #[AsTwigFunction('build_environment')]
    public function buildEnvironment(): ?string
    {
        if ($this->gitTag) {
            return null;
        }

        return $this->gitSha ? 'Acceptance' : 'Develop';
    }
}
