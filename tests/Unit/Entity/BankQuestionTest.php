<?php

declare(strict_types=1);

namespace Tvdt\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tvdt\Entity\BankAnswer;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\BankQuestionUsage;
use Tvdt\Entity\Quiz;

#[CoversClass(BankQuestion::class)]
final class BankQuestionTest extends TestCase
{
    public function testIsCompleteForQuizIsFalseWithoutTwoAnswers(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->addAnswer(new BankAnswer('Only answer', true));

        $this->assertFalse($bankQuestion->isCompleteForQuiz);
    }

    public function testIsCompleteForQuizIsFalseWithoutCorrectAnswer(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->addAnswer(new BankAnswer('Wrong 1'));
        $bankQuestion->addAnswer(new BankAnswer('Wrong 2'));

        $this->assertFalse($bankQuestion->isCompleteForQuiz);
    }

    public function testIsCompleteForQuizIsFalseWithMultipleCorrectAnswers(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->addAnswer(new BankAnswer('Right 1', true));
        $bankQuestion->addAnswer(new BankAnswer('Right 2', true));

        $this->assertFalse($bankQuestion->isCompleteForQuiz);
    }

    public function testIsCompleteForQuizIsTrueWithTwoAnswersAndOneCorrect(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->addAnswer(new BankAnswer('Right', true));
        $bankQuestion->addAnswer(new BankAnswer('Wrong'));

        $this->assertTrue($bankQuestion->isCompleteForQuiz);
    }

    public function testCanBeAssignedIsTrueWhenUnused(): void
    {
        $bankQuestion = new BankQuestion();

        $this->assertTrue($bankQuestion->canBeAssigned);
    }

    public function testCanBeAssignedIsTrueWhenReusableEvenIfUsed(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->reusable = true;
        $bankQuestion->addUsage(new BankQuestionUsage($bankQuestion, new Quiz()));

        $this->assertTrue($bankQuestion->canBeAssigned);
    }

    public function testCanBeAssignedIsFalseWhenSingleUseAndUsed(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->addUsage(new BankQuestionUsage($bankQuestion, new Quiz()));

        $this->assertFalse($bankQuestion->canBeAssigned);
    }

    public function testIsUsedInQuizIsTrueForQuizWithUsage(): void
    {
        $bankQuestion = new BankQuestion();
        $quiz = new Quiz();
        $bankQuestion->addUsage(new BankQuestionUsage($bankQuestion, $quiz));

        $this->assertTrue($bankQuestion->isUsedInQuiz($quiz));
    }

    public function testIsUsedInQuizIsFalseForDifferentQuiz(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->addUsage(new BankQuestionUsage($bankQuestion, new Quiz()));

        $this->assertFalse($bankQuestion->isUsedInQuiz(new Quiz()));
    }

    public function testToStringReturnsQuestionText(): void
    {
        $bankQuestion = new BankQuestion();
        $bankQuestion->question = 'Wie is de Krtek?';

        $this->assertSame('Wie is de Krtek?', (string) $bankQuestion);
    }
}
