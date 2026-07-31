<?php

declare(strict_types=1);

use DAMA\DoctrineTestBundle\DAMADoctrineTestBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Sensiolabs\TypeScriptBundle\SensiolabsTypeScriptBundle;
use Sentry\SentryBundle\SentryBundle;
use Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MakerBundle\MakerBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\Translator\UxTranslatorBundle;
use Symfony\UX\Turbo\TurboBundle;
use SymfonyCasts\Bundle\ResetPassword\SymfonyCastsResetPasswordBundle;
use SymfonyCasts\Bundle\VerifyEmail\SymfonyCastsVerifyEmailBundle;
use Symfonycasts\SassBundle\SymfonycastsSassBundle;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    MakerBundle::class => ['dev' => true],
    TwigBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    WebProfilerBundle::class => ['dev' => true, 'test' => true],
    TwigExtraBundle::class => ['all' => true],
    DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
    SymfonyCastsVerifyEmailBundle::class => ['all' => true],
    SentryBundle::class => ['dev' => true, 'prod' => true],
    SymfonycastsSassBundle::class => ['all' => true],
    StimulusBundle::class => ['all' => true],
    TurboBundle::class => ['all' => true],
    DAMADoctrineTestBundle::class => ['test' => true],
    StofDoctrineExtensionsBundle::class => ['all' => true],
    SymfonyCastsResetPasswordBundle::class => ['all' => true],
    SensiolabsTypeScriptBundle::class => ['all' => true],
    UxTranslatorBundle::class => ['all' => true],
];
