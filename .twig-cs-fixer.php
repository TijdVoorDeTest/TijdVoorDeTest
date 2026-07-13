<?php

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\Symfony;
use TwigCsFixer\Standard\TwigCsFixer;

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixer());
$ruleset->addStandard(new Symfony());

return new Config()->setRuleset($ruleset);
