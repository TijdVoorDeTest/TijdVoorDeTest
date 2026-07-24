<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\Quiz;
use Tvdt\Enum\ScreenColour;

#[CoversClass(Elimination::class)]
final class EliminationTest extends TestCase
{
    public function testGetScreenColourReturnsNullForNullName(): void
    {
        $elimination = new Elimination(new Quiz());

        $this->assertNotInstanceOf(ScreenColour::class, $elimination->getScreenColour(null));
    }

    public function testGetScreenColourReturnsNullForUnknownName(): void
    {
        $elimination = new Elimination(new Quiz());
        $elimination->data = $this->colours(['Tom' => ScreenColour::Green]);

        $this->assertNotInstanceOf(ScreenColour::class, $elimination->getScreenColour('Claudia'));
    }

    public function testGetScreenColourReturnsColourForKnownName(): void
    {
        $elimination = new Elimination(new Quiz());
        $elimination->data = $this->colours(['Tom' => ScreenColour::Green, 'Claudia' => ScreenColour::Red]);

        $this->assertSame(ScreenColour::Red, $elimination->getScreenColour('Claudia'));
    }

    public function testGetScreenColourReturnsNullForInvalidStoredValue(): void
    {
        $elimination = new Elimination(new Quiz());
        $elimination->data = ['Tom' => 'not-a-colour'];

        $this->assertNotInstanceOf(ScreenColour::class, $elimination->getScreenColour('Tom'));
    }

    public function testUpdateFromInputBagUpdatesKnownColours(): void
    {
        $elimination = new Elimination(new Quiz());
        $elimination->data = $this->colours(['Tom' => ScreenColour::Green, 'Claudia' => ScreenColour::Red]);

        $elimination->updateFromInputBag($this->inputBag(['colour-tom' => ScreenColour::Red->value]));

        $this->assertSame(ScreenColour::Red->value, $elimination->data['Tom']);
        $this->assertSame(ScreenColour::Red->value, $elimination->data['Claudia']);
    }

    public function testUpdateFromInputBagIgnoresMissingInput(): void
    {
        $elimination = new Elimination(new Quiz());
        $elimination->data = $this->colours(['Tom' => ScreenColour::Green]);

        $elimination->updateFromInputBag($this->inputBag([]));

        $this->assertSame(ScreenColour::Green->value, $elimination->data['Tom']);
    }

    public function testUpdateFromInputBagIgnoresInvalidColour(): void
    {
        $elimination = new Elimination(new Quiz());
        $elimination->data = $this->colours(['Tom' => ScreenColour::Green]);

        $elimination->updateFromInputBag($this->inputBag(['colour-tom' => 'purple']));

        $this->assertSame(ScreenColour::Green->value, $elimination->data['Tom']);
    }

    public function testUpdateFromInputBagReturnsSelf(): void
    {
        $elimination = new Elimination(new Quiz());

        $this->assertSame($elimination, $elimination->updateFromInputBag($this->inputBag([])));
    }

    /**
     * @param array<string, ScreenColour> $colours
     *
     * @return array<string, string>
     */
    private function colours(array $colours): array
    {
        return array_map(static fn (ScreenColour $colour): string => $colour->value, $colours);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return InputBag<bool|float|int|string>
     */
    private function inputBag(array $parameters): InputBag
    {
        /** @var InputBag<bool|float|int|string> $inputBag */
        $inputBag = new InputBag($parameters);

        return $inputBag;
    }
}
