<?php

declare(strict_types=1);

namespace Tvdt\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Tvdt\Entity\Answer;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\Question;
use Tvdt\Entity\QuestionLabel;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;

/** @extends Voter<string, Season|Elimination|Quiz|Candidate|Answer|Question|BankQuestion|QuestionLabel> */
final class SeasonVoter extends Voter
{
    public const string EDIT = 'SEASON_EDIT';

    public const string ELIMINATION = 'SEASON_ELIMINATION';

    public const string DELETE = 'SEASON_DELETE';

    public const string RESTORE = 'SEASON_RESTORE';

    public const string MODIFY_QUIZ_CONTENT = 'QUIZ_MODIFY_CONTENT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE, self::RESTORE, self::ELIMINATION, self::MODIFY_QUIZ_CONTENT], true)
                && (
                    $subject instanceof Answer
                    || $subject instanceof BankQuestion
                    || $subject instanceof Candidate
                    || $subject instanceof Elimination
                    || $subject instanceof Season
                    || $subject instanceof Question
                    || $subject instanceof QuestionLabel
                    || $subject instanceof Quiz
                );
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $season = match (true) {
            $subject instanceof Answer => $subject->question->quiz->season,
            $subject instanceof Elimination,
            $subject instanceof Question => $subject->quiz->season,
            $subject instanceof BankQuestion,
            $subject instanceof Candidate,
            $subject instanceof QuestionLabel,
            $subject instanceof Quiz => $subject->season,
            $subject instanceof Season => $subject,
        };

        if (self::MODIFY_QUIZ_CONTENT === $attribute) {
            $quiz = match (true) {
                $subject instanceof Answer => $subject->question->quiz,
                $subject instanceof Question => $subject->quiz,
                $subject instanceof Quiz => $subject,
                default => null,
            };

            if (!$quiz instanceof Quiz || $quiz->isLocked) {
                return false;
            }

            return $user->isAdmin || $season->isOwner($user);
        }

        if ($user->isAdmin) {
            return true;
        }

        return match ($attribute) {
            self::EDIT, self::DELETE, self::RESTORE, self::ELIMINATION => $season->isOwner($user),
            default => false,
        };
    }
}
