<?php

declare(strict_types=1);

namespace Tvdt\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Safe\DateTimeImmutable;
use Tvdt\Entity\Answer;
use Tvdt\Entity\BankAnswer;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\BankQuestionUsage;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\EliminationScreenView;
use Tvdt\Entity\GivenAnswer;
use Tvdt\Entity\Question;
use Tvdt\Entity\QuestionLabel;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Entity\Season;
use Tvdt\Entity\SeasonSettings;
use Tvdt\Enum\ScreenColour;

final class KrtekFixtures extends Fixture implements FixtureGroupInterface
{
    public const string KRTEK_SEASON = 'krtek-seaspm';

    public const string KRTEK_QUIZ_1 = 'krtek-quiz-1';

    public const string KRTEK_QUIZ_2 = 'krtek-quiz-2';

    public const string BANK_QUESTION_REUSABLE = 'bank-question-reusable';

    public const string BANK_QUESTION_USED = 'bank-question-used';

    public const string BANK_QUESTION_UNUSED = 'bank-question-unused';

    public static function getGroups(): array
    {
        return ['test', 'dev'];
    }

    public function load(ObjectManager $manager): void
    {
        $season = new Season();
        $manager->persist($season);

        $season->name = 'Krtek Weekend';
        $season->seasonCode = 'krtek';
        $season
            ->addCandidate(new Candidate('Claudia'))
            ->addCandidate(new Candidate('Eelco'))
            ->addCandidate(new Candidate('Elise'))
            ->addCandidate(new Candidate('Gert-Jan'))
            ->addCandidate(new Candidate('Iris'))
            ->addCandidate(new Candidate('Jari'))
            ->addCandidate(new Candidate('Lara'))
            ->addCandidate(new Candidate('Lotte'))
            ->addCandidate(new Candidate('Myrthe'))
            ->addCandidate(new Candidate('Philine'))
            ->addCandidate(new Candidate('Remy'))
            ->addCandidate(new Candidate('Robbert'))
            ->addCandidate(new Candidate('Tom'));
        $quiz1 = $this->createQuiz1($season);
        $season->addQuiz($quiz1);
        $season->activeQuiz = $quiz1;

        $quiz1->finalizedAt = new DateTimeImmutable();
        $quiz2 = $this->createQuiz2($season);
        $season->addQuiz($quiz2);

        $quiz3 = $this->createQuiz3($season);
        $season->addQuiz($quiz3);

        $quiz4 = $this->createQuiz4($season);
        $season->addQuiz($quiz4);

        $quiz5 = $this->createQuiz5($season);
        $season->addQuiz($quiz5);

        // Quiz 6-9 exist purely so every Quiz::$status value has a live example in this season:
        // Quiz 1 above is already Active. "Done" (Active + everyone finished) has no example here
        // since it would require the season's activeQuiz to have baseline candidates/answers,
        // which several QuizRepositoryTest/QuizControllerTest tests add their own scratch data to
        // and assert exact counts/ordering on.
        $quiz6 = $this->createQuiz6($season); // New: no questions yet.
        $season->addQuiz($quiz6);

        $quiz7 = $this->createQuiz7($season); // Ready: finalized, nobody started.
        $season->addQuiz($quiz7);

        $quiz8 = $this->createQuiz8($season); // Finished: finalized, not active, everyone done.
        $season->addQuiz($quiz8);

        $quiz9 = $this->createQuiz9($season); // Revealed: finalized, elimination screen shown.
        $season->addQuiz($quiz9);

        \assert($season->settings instanceof SeasonSettings);

        $season->settings->confirmAnswers = true;
        $season->settings->showNumbers = true;

        $this->createQuestionBank($season, $quiz2);
        $this->bindQuizQuestionsToBank($season);

        $this->finishQuiz($manager, $quiz8, [
            $this->getCandidateByName($season, 'Gert-Jan'),
            $this->getCandidateByName($season, 'Jari'),
        ]);
        $this->revealElimination($manager, $quiz9, $this->getCandidateByName($season, 'Lara'));

        $manager->flush();

        $this->addReference(self::KRTEK_SEASON, $season);
        $this->addReference(self::KRTEK_QUIZ_1, $quiz1);
        $this->addReference(self::KRTEK_QUIZ_2, $quiz2);
    }

    private function getCandidateByName(Season $season, string $name): Candidate
    {
        $candidate = $season->candidates->filter(static fn (Candidate $candidate): bool => $name === $candidate->name)->first();
        \assert($candidate instanceof Candidate);

        return $candidate;
    }

    /**
     * Marks each candidate as having started and fully answered every question of $quiz, so
     * Quiz::$status reaches Finished (or Done, if $quiz is also the season's active quiz).
     *
     * @param list<Candidate> $candidates
     */
    private function finishQuiz(ObjectManager $manager, Quiz $quiz, array $candidates): void
    {
        foreach ($candidates as $candidate) {
            $quizCandidate = new QuizCandidate($quiz, $candidate);
            $quizCandidate->started = new DateTimeImmutable();
            $manager->persist($quizCandidate);

            foreach ($quiz->questions as $question) {
                $answer = $question->answers->first();
                \assert($answer instanceof Answer);
                $manager->persist(new GivenAnswer($candidate, $quiz, $answer));
            }
        }
    }

    /** Prepares an elimination for $quiz with one screen already shown, so Quiz::$status reaches Revealed. */
    private function revealElimination(ObjectManager $manager, Quiz $quiz, Candidate $candidate): void
    {
        $elimination = new Elimination($quiz);
        $quiz->addElimination($elimination);

        $screenView = new EliminationScreenView($elimination, $candidate, ScreenColour::Green);
        $elimination->screenViews->add($screenView);

        $manager->persist($elimination);
        $manager->persist($screenView);
    }

    private function createQuestionBank(Season $season, Quiz $usedInQuiz): void
    {
        $location = new QuestionLabel('Locatie');
        $location->slug = 'locatie';

        $season->addQuestionLabel($location);
        $finale = new QuestionLabel('Finale');
        $finale->slug = 'finale';

        $season->addQuestionLabel($finale);

        $reusable = new BankQuestion();
        $reusable->question = 'Wat is de bijnaam van de Krtek?';
        $reusable->reusable = true;
        $reusable->addLabel($finale);
        $reusable->addAnswer(new BankAnswer('Claudia', true));
        $reusable->addAnswer(new BankAnswer('Eelco'));
        $reusable->addAnswer(new BankAnswer('Elise'));

        $season->addBankQuestion($reusable);

        $used = new BankQuestion();
        $used->question = 'Waar sliep de Krtek?';
        $used->addLabel($location);
        $used->addAnswer(new BankAnswer('Boven', true));
        $used->addAnswer(new BankAnswer('Beneden'));
        $used->addUsage(new BankQuestionUsage($used, $usedInQuiz));

        $season->addBankQuestion($used);

        $unused = new BankQuestion();
        $unused->question = 'Wat at de Krtek als ontbijt?';
        $unused->addLabel($location);
        $unused->addLabel($finale);
        $unused->addAnswer(new BankAnswer('Brood', true));
        $unused->addAnswer(new BankAnswer('Yoghurt'));
        $unused->addAnswer(new BankAnswer('Niks'));

        $season->addBankQuestion($unused);

        $this->addReference(self::BANK_QUESTION_REUSABLE, $reusable);
        $this->addReference(self::BANK_QUESTION_USED, $used);
        $this->addReference(self::BANK_QUESTION_UNUSED, $unused);
    }

    /**
     * Mirrors every quiz question into the question bank, so the bank reflects what was actually
     * asked. A question text reused across quizzes (e.g. the recurring "man of vrouw"/"wie is de
     * Krtek" questions) becomes a single reusable bank question with one usage per quiz it appears
     * in, instead of a separate bank question per occurrence.
     */
    private function bindQuizQuestionsToBank(Season $season): void
    {
        /** @var array<string, BankQuestion> $bankQuestionsByText */
        $bankQuestionsByText = [];

        foreach ($season->quizzes as $quiz) {
            foreach ($quiz->questions as $question) {
                $bankQuestion = $bankQuestionsByText[$question->question] ?? null;

                if (null === $bankQuestion) {
                    $bankQuestion = new BankQuestion();
                    $bankQuestion->question = $question->question;
                    foreach ($question->answers as $answer) {
                        $bankQuestion->addAnswer(new BankAnswer($answer->text, $answer->isRightAnswer));
                    }

                    $season->addBankQuestion($bankQuestion);
                    $bankQuestionsByText[$question->question] = $bankQuestion;
                } else {
                    $bankQuestion->reusable = true;
                }

                $usage = new BankQuestionUsage($bankQuestion, $quiz);
                $usage->question = $question;
                $bankQuestion->addUsage($usage);
            }
        }
    }

    private function createQuiz1(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 1';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Vrouw', true))
          ->addAnswer(new Answer('Man'));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoeveel broers heeft de Krtek?';
        $q->addAnswer(new Answer('Geen', true))
          ->addAnswer(new Answer('1'))
          ->addAnswer(new Answer('2'));
        $q->ordering = 2;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat is de lievelingsfeestdag van de Krtek?';
        $q->addAnswer(new Answer('Geen'))
          ->addAnswer(new Answer('Diens eigen verjaardag'))
          ->addAnswer(new Answer('Koningsdag'))
          ->addAnswer(new Answer('Kerst', true))
          ->addAnswer(new Answer('Oud en Nieuw'));
        $q->ordering = 3;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoe kwam de Krtek naar Kersteren vandaag?';
        $q->addAnswer(new Answer('Met het OV', true))
          ->addAnswer(new Answer('Met de auto'));
        $q->ordering = 4;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Met wie keek de Krtek video bij binnenkomst?';
        $q->addAnswer(new Answer('Claudia'))
          ->addAnswer(new Answer('Eelco'))
          ->addAnswer(new Answer('Elise'))
          ->addAnswer(new Answer('Gert-Jan'))
          ->addAnswer(new Answer('Iris'))
          ->addAnswer(new Answer('Jari'))
          ->addAnswer(new Answer('Lara'))
          ->addAnswer(new Answer('Lotte'))
          ->addAnswer(new Answer('Myrthe'))
          ->addAnswer(new Answer('Philine'))
          ->addAnswer(new Answer('Remy'))
          ->addAnswer(new Answer('Robbert'))
          ->addAnswer(new Answer('Tom', true));
        $q->ordering = 5;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Welk advies zou de Krtek zichzelf als kind geven?';
        $q->addAnswer(new Answer('Geef je vader een knuffel.'))
          ->addAnswer(new Answer('Trek je wat minder aan van anderen.'))
          ->addAnswer(new Answer('Luister meer naar je eigen gevoel in plaats van naar wat anderen vinden.'))
          ->addAnswer(new Answer('Stel niet alles tot het laatste moment uit.'))
          ->addAnswer(new Answer('Altijd doorgaan.'))
          ->addAnswer(new Answer('Probeer ook eens buiten de lijntjes te kleuren', true))
          ->addAnswer(new Answer('Ga als je groot bent op groepsreis! '))
          ->addAnswer(new Answer('Trek minder aan van de mening van anderen, het is oké om anders te zijn.'));
        $q->ordering = 6;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat voor soort schoenen droeg de Krtek bij het diner?';
        $q->addAnswer(new Answer('Sneakers'))
          ->addAnswer(new Answer('Wandel-/bergschoenen', true))
          ->addAnswer(new Answer('Lederen schoenen'))
          ->addAnswer(new Answer('Pantoffels'))
          ->addAnswer(new Answer('Hakken'))
          ->addAnswer(new Answer('Geen schoenen, alleen sokken'));
        $q->ordering = 7;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Met welk vervoersmiddel reist de Krtek het liefste?';
        $q->addAnswer(new Answer('Fiets', true))
          ->addAnswer(new Answer('Auto'))
          ->addAnswer(new Answer('Trein'));
        $q->ordering = 8;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek een eigen auto?';
        $q->addAnswer(new Answer('Ja'))
          ->addAnswer(new Answer('Nee', true));
        $q->ordering = 9;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Van wie is de quote die de Krtek gepakt heeft';
        $q->addAnswer(new Answer('Karen'))
          ->addAnswer(new Answer('Gilles de Coster'))
          ->addAnswer(new Answer('Kees Tol'))
          ->addAnswer(new Answer('Harry en John'))
          ->addAnswer(new Answer('Georgina Verbaan'))
          ->addAnswer(new Answer('Marc-Marie Huijbregts'))
          ->addAnswer(new Answer('Fresia Cousiño Arias, Rik van de Westelaken'))
          ->addAnswer(new Answer('Ellie Lust'))
          ->addAnswer(new Answer('Bouba'))
          ->addAnswer(new Answer('Jan Versteegh'))
          ->addAnswer(new Answer('Dick Jol'))
          ->addAnswer(new Answer('Karin de Groot'))
          ->addAnswer(new Answer('Pieter'))
          ->addAnswer(new Answer('Renée Fokker'))
          ->addAnswer(new Answer('Sam, Davy', true));
        $q->ordering = 10;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Zou de Krtek molboekjes, jokers, vrijstellingen of topito’s uit iemands rugzak stelen om te kunnen winnen?';
        $q->addAnswer(new Answer('Ja'))
          ->addAnswer(new Answer('Nee', true));
        $q->ordering = 11;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'In wat voor bed slaapt de Krtek dit weekend?';
        $q->addAnswer(new Answer('Éénpersoons, losstaand bed'))
          ->addAnswer(new Answer('Éénpersoonsbed, tegen een ander bed aan', true))
          ->addAnswer(new Answer('Tweepersoons bed'));
        $q->ordering = 12;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoeveel jaar heeft de Krtek gedaan over de middelbare school?';
        $q->addAnswer(new Answer('5'))
          ->addAnswer(new Answer('6', true))
          ->addAnswer(new Answer('7'))
          ->addAnswer(new Answer('8'));
        $q->ordering = 13;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Waar zat de Krtek aan tafel bij het diner?';
        $q->addAnswer(new Answer('Met de rug naar de accommodatie'))
          ->addAnswer(new Answer('Met de rug naar de buitenmuur', true));
        $q->ordering = 14;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wie is de Krtek?';
        $q->addAnswer(new Answer('Claudia', true))
          ->addAnswer(new Answer('Eelco'))
          ->addAnswer(new Answer('Elise'))
          ->addAnswer(new Answer('Gert-Jan'))
          ->addAnswer(new Answer('Iris'))
          ->addAnswer(new Answer('Jari'))
          ->addAnswer(new Answer('Lara'))
          ->addAnswer(new Answer('Lotte'))
          ->addAnswer(new Answer('Myrthe'))
          ->addAnswer(new Answer('Philine'))
          ->addAnswer(new Answer('Remy'))
          ->addAnswer(new Answer('Robbert'))
          ->addAnswer(new Answer('Tom'));
        $q->ordering = 15;
        $quiz->addQuestion($q);

        return $quiz;
    }

    private function createQuiz2(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 2';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Man'))
          ->addAnswer(new Answer('Vrouw', true));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek dieetwensen of allergieën?';
        $q->addAnswer(new Answer('nee'))
          ->addAnswer(new Answer('De Krtek is vegetariër', true))
          ->addAnswer(new Answer('De Krtek is flexitariër'))
          ->addAnswer(new Answer('De Krtek heeft een allergie'))
          ->addAnswer(new Answer('De Krtek heeft een intolerantie'))
          ->addAnswer(new Answer('De Krtek eet geen rundvlees'))
          ->addAnswer(new Answer('De Krtek eet geen waterdieren'));
        $q->ordering = 2;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoe heet het huisdier/de huisdieren van de Krtek?';
        $q->addAnswer(new Answer('Amy, Karel en Floyd'))
          ->addAnswer(new Answer('Flip en Majoor'))
          ->addAnswer(new Answer('Benji'))
          ->addAnswer(new Answer('Sini'))
          ->addAnswer(new Answer('Tom'))
          ->addAnswer(new Answer('De huisdieren van de Krtek hebben geen naam'))
          ->addAnswer(new Answer('De Krtek heeft geen huisdieren', true));
        $q->ordering = 3;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat dronk de Krtek deze ochtend bij het ontbijt?';
        $q->addAnswer(new Answer('Koffie'))
          ->addAnswer(new Answer('Thee'))
          ->addAnswer(new Answer('Water', true))
          ->addAnswer(new Answer('Melk'))
          ->addAnswer(new Answer('Sap'))
          ->addAnswer(new Answer('Niks'));
        $q->ordering = 4;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Waar ging de eerste vakantie die de Krtek zich nog herinnert heen?';
        $q->addAnswer(new Answer('Denemarken'))
          ->addAnswer(new Answer('Drenthe'))
          ->addAnswer(new Answer('Mallorca'))
          ->addAnswer(new Answer('Marokko'))
          ->addAnswer(new Answer('Oostenrijk'))
          ->addAnswer(new Answer('Turkije'))
          ->addAnswer(new Answer('Zweden', true));
        $q->ordering = 5;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Met welk groepje ging de Krtek als eerste het Douanespel in?';
        $q->addAnswer(new Answer('Het eerste groepje', true))
          ->addAnswer(new Answer('Het tweede groepje'))
          ->addAnswer(new Answer('Het derde groepje'))
          ->addAnswer(new Answer('Het vierde groepje'))
          ->addAnswer(new Answer('Het vijfde groepje'));
        $q->ordering = 6;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Gelooft de Krtek ergens in?';
        $q->addAnswer(new Answer('Nee'))
          ->addAnswer(new Answer('Het universum', true))
          ->addAnswer(new Answer('Toeval'))
          ->addAnswer(new Answer('De Krtek is hindoeïstisch'));
        $q->ordering = 7;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'At de Krtek op vrijdagavond heksenkaas tijdens het diner?';
        $q->addAnswer(new Answer('Ja', true))
          ->addAnswer(new Answer('Nee'));
        $q->ordering = 8;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoe laat ging de Krtek gisteravond naar bed?';
        $q->addAnswer(new Answer('Tussen 0:00 en 0:59 uur'))
          ->addAnswer(new Answer('Tussen 1:00 en 1:59 uur', true))
          ->addAnswer(new Answer('Tussen 2:00 en 2:59 uur'))
          ->addAnswer(new Answer('Na 3:00'));
        $q->ordering = 9;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoeveel batterijen heeft de Krtek naar het bord gebracht bij het douanespel?';
        $q->addAnswer(new Answer('1'))
          ->addAnswer(new Answer('2'))
          ->addAnswer(new Answer('3'))
          ->addAnswer(new Answer('geen', true));
        $q->ordering = 10;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat keek de Krtek als kind graag op TV?';
        $q->addAnswer(new Answer('Digimon', true))
          ->addAnswer(new Answer('Floris'))
          ->addAnswer(new Answer('Het huis Anubis'))
          ->addAnswer(new Answer('Sesamstraat'))
          ->addAnswer(new Answer('Spongebob Squarepants'))
          ->addAnswer(new Answer('Teletubbies'));
        $q->ordering = 11;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Waarin zat op de heenreis de bagage van de Krtek (voornamelijk)?';
        $q->addAnswer(new Answer('In koffer(s)', true))
          ->addAnswer(new Answer('In losse tas(sen)'))
          ->addAnswer(new Answer('In een rugzak'));
        $q->ordering = 12;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Van welk geluid gaan de haren van de Krtek overeind staan?';
        $q->addAnswer(new Answer('Een vork die door een metalen pan krast '))
          ->addAnswer(new Answer('Smakkende mensen'))
          ->addAnswer(new Answer('Een vork die over een bord schraapt'))
          ->addAnswer(new Answer('Schuren met schuurpapier'))
          ->addAnswer(new Answer('Nagels op een krijtbord'))
          ->addAnswer(new Answer('Servies dat tegen elkaar klettert'))
          ->addAnswer(new Answer('Het geroekoe van een duif', true))
          ->addAnswer(new Answer('Piepschuim'));
        $q->ordering = 13;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wilde de Krtek penningmeester worden?';
        $q->addAnswer(new Answer('Ja'))
          ->addAnswer(new Answer('Nee', true));
        $q->ordering = 14;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wie is de Krtek?';
        $q->addAnswer(new Answer('Claudia', true))
          ->addAnswer(new Answer('Eelco'))
          ->addAnswer(new Answer('Elise'))
          ->addAnswer(new Answer('Gert-Jan'))
          ->addAnswer(new Answer('Iris'))
          ->addAnswer(new Answer('Jari'))
          ->addAnswer(new Answer('Lara'))
          ->addAnswer(new Answer('Lotte'))
          ->addAnswer(new Answer('Myrthe'))
          ->addAnswer(new Answer('Philine'))
          ->addAnswer(new Answer('Remy'))
          ->addAnswer(new Answer('Robbert'))
          ->addAnswer(new Answer('Tom'));
        $q->ordering = 15;
        $quiz->addQuestion($q);

        return $quiz;
    }

    private function createQuiz3(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 3';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Man'))
          ->addAnswer(new Answer('Vrouw', true));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Bij welk onderdeel zat de Krtek bij de opdracht "Blind vertrouwen"?';
        // No answer was marked correct in the source spreadsheet for this question; "Beweging" was
        // chosen arbitrarily on import and is not necessarily correct for the real situation.
        $q->addAnswer(new Answer('Beweging', true))
          ->addAnswer(new Answer('Precisie'))
          ->addAnswer(new Answer('Oog voor Detail'))
          ->addAnswer(new Answer('Ruimtelijk Inzicht'))
          ->addAnswer(new Answer('Diepte'))
          ->addAnswer(new Answer('Nattigheid'))
          ->addAnswer(new Answer('Overzicht'));
        $q->ordering = 2;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Snurkt de Krtek?';
        $q->addAnswer(new Answer('Ja'))
          ->addAnswer(new Answer('Nee', true))
          ->addAnswer(new Answer('Nee?'));
        $q->ordering = 3;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat kon de Krtek bij de opdracht "Blind Vertrouwen" niet?';
        // No answer was marked correct in the source spreadsheet for this question; "Zien" was
        // chosen arbitrarily on import and is not necessarily correct for the real situation.
        $q->addAnswer(new Answer('Zien', true))
          ->addAnswer(new Answer('De handen gebruiken'));
        $q->ordering = 4;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek vanmorgen gedoucht?';
        // No answer was marked correct in the source spreadsheet for this question; "Ja" was
        // chosen arbitrarily on import and is not necessarily correct for the real situation.
        $q->addAnswer(new Answer('Ja', true))
          ->addAnswer(new Answer('Nee'));
        $q->ordering = 5;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'In welke ruimte keek de Krtek gisteren de video?';
        $q->addAnswer(new Answer('In de kleine keuken', true))
          ->addAnswer(new Answer('In de organisatie-huiskamer'));
        $q->ordering = 6;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Welke kleur drinken dronk de Krtek bij de cupcakes?';
        $q->addAnswer(new Answer('Kleurloos', true))
          ->addAnswer(new Answer('Groen'))
          ->addAnswer(new Answer('Rood'));
        $q->ordering = 7;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoe oud is de Krtek?';
        $q->addAnswer(new Answer('20'))
          ->addAnswer(new Answer('22'))
          ->addAnswer(new Answer('23'))
          ->addAnswer(new Answer('25'))
          ->addAnswer(new Answer('26'))
          ->addAnswer(new Answer('27'))
          ->addAnswer(new Answer('29', true))
          ->addAnswer(new Answer('30'))
          ->addAnswer(new Answer('31'))
          ->addAnswer(new Answer('32'))
          ->addAnswer(new Answer('33'));
        $q->ordering = 8;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Bespeelt de Krtek een muziekinstrument en zo ja, welke?';
        $q->addAnswer(new Answer('Ja, piano'))
          ->addAnswer(new Answer('Ja, gitaar'))
          ->addAnswer(new Answer('Ja, gitaar en een beetje mondharmonica'))
          ->addAnswer(new Answer('Ja, een beetje ukulele'))
          ->addAnswer(new Answer('Nee', true));
        $q->ordering = 9;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Hoeveel geld is er verdiend met het onderdeel van de Krtek bij de opdracht "Blind Vertrouwen"?';
        // No answer was marked correct in the source spreadsheet for this question; "€ 0,-" was
        // chosen arbitrarily on import and is not necessarily correct for the real situation.
        $q->addAnswer(new Answer('€ 0,-', true))
          ->addAnswer(new Answer('€ 10,-'))
          ->addAnswer(new Answer('€ 15,-'))
          ->addAnswer(new Answer('€ 20,-'))
          ->addAnswer(new Answer('€ 30,-'));
        $q->ordering = 10;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat is de schoenmaat van de Krtek?';
        $q->addAnswer(new Answer('37'))
          ->addAnswer(new Answer('39'))
          ->addAnswer(new Answer('39'))
          ->addAnswer(new Answer('40', true))
          ->addAnswer(new Answer('42'))
          ->addAnswer(new Answer('42.5'))
          ->addAnswer(new Answer('43'))
          ->addAnswer(new Answer('46'));
        $q->ordering = 11;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Op welk huisnummer woont de Krtek?';
        $q->addAnswer(new Answer('12'))
          ->addAnswer(new Answer('16'))
          ->addAnswer(new Answer('20'))
          ->addAnswer(new Answer('21'))
          ->addAnswer(new Answer('51'))
          ->addAnswer(new Answer('58-2', true))
          ->addAnswer(new Answer('73'))
          ->addAnswer(new Answer('350'));
        $q->ordering = 12;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Via wie kwam de Krtek er achter dat Sinterklaas niet bestond?';
        $q->addAnswer(new Answer('Moeder', true))
          ->addAnswer(new Answer('Vader'))
          ->addAnswer(new Answer('Beide ouders'))
          ->addAnswer(new Answer('Vriendin'))
          ->addAnswer(new Answer('De Krtek heeft geen idee'))
          ->addAnswer(new Answer('De Krtek vertelde het zelf aan zijn ouders'));
        $q->ordering = 13;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek gisteravond gebiecht?';
        $q->addAnswer(new Answer('Ja'))
          ->addAnswer(new Answer('Nee', true));
        $q->ordering = 14;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wie is de Krtek?';
        $q->addAnswer(new Answer('Claudia', true))
          ->addAnswer(new Answer('Eelco'))
          ->addAnswer(new Answer('Elise'))
          ->addAnswer(new Answer('Gert-Jan'))
          ->addAnswer(new Answer('Iris'))
          ->addAnswer(new Answer('Jari'))
          ->addAnswer(new Answer('Lara'))
          ->addAnswer(new Answer('Lotte'))
          ->addAnswer(new Answer('Myrthe'))
          ->addAnswer(new Answer('Philine'))
          ->addAnswer(new Answer('Remy'))
          ->addAnswer(new Answer('Robbert'))
          ->addAnswer(new Answer('Tom'));
        $q->ordering = 15;
        $quiz->addQuestion($q);

        return $quiz;
    }

    private function createQuiz4(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 4';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Man'))
          ->addAnswer(new Answer('Vrouw', true));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Waar zat de Krtek vanmiddag aan tafel tijdens de lunch?';
        $q->addAnswer(new Answer('Aan de kant van de buitenmuur'))
          ->addAnswer(new Answer('Aan de kant van de ingang', true));
        $q->ordering = 2;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat dronk de Krtek tijdens de lunch?';
        $q->addAnswer(new Answer('Thee'))
          ->addAnswer(new Answer('Chocomel'))
          ->addAnswer(new Answer('Bier'))
          ->addAnswer(new Answer('Appelsap'))
          ->addAnswer(new Answer('Ice tea green'))
          ->addAnswer(new Answer('Pepsi', true))
          ->addAnswer(new Answer('Pepsi max'))
          ->addAnswer(new Answer('Fristi'))
          ->addAnswer(new Answer('Cappuccino'))
          ->addAnswer(new Answer("Jus d'orange"));
        $q->ordering = 3;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Is er iets dat de Krtek graag viert?';
        $q->addAnswer(new Answer('Nee'))
          ->addAnswer(new Answer("Nee, gezellige avonden hoeven voor de Krtek niet samen te vallen met 'bijzondere' momenten."))
          ->addAnswer(new Answer('Sinterklaas'))
          ->addAnswer(new Answer('Kerst'))
          ->addAnswer(new Answer('Oud en Nieuw'))
          ->addAnswer(new Answer('Gay Pride', true))
          ->addAnswer(new Answer('Bevrijdingsdag, want dat vier ik meteen mijn verjaardag'))
          ->addAnswer(new Answer('Het leven'));
        $q->ordering = 4;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek nog opa’s/oma’s? Zo ja, wat zijn de namen?';
        $q->addAnswer(new Answer('Ja, Oma Toos', true))
          ->addAnswer(new Answer('Ja, Opa Piet en Oma Door'))
          ->addAnswer(new Answer('Ja, Oma Greet, Opa Wim en Oma Bep'))
          ->addAnswer(new Answer('Ja, Opa Cor (Eigenlijk Cornelis), Oma Grada en Oma Willy'))
          ->addAnswer(new Answer('Nee'));
        $q->ordering = 5;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat is het eerste dat de Krtek zou doen als hij een dag van geslacht veranderd?';
        $q->addAnswer(new Answer('Plassen'))
          ->addAnswer(new Answer('Wildplassen', true))
          ->addAnswer(new Answer('Staand plassen'))
          ->addAnswer(new Answer('in de spiegel kijken'))
          ->addAnswer(new Answer('Een geslachtelijk onderzoek'))
          ->addAnswer(new Answer('Heel de dag voor de spiegel staan'))
          ->addAnswer(new Answer('Testen hoe goed een beha werkt als telefoonhouder'))
          ->addAnswer(new Answer('Een dag werken en kijken hoe de wereld je anders behandeld'));
        $q->ordering = 6;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek vanmorgen een geheim bericht gevonden op het toilet?';
        $q->addAnswer(new Answer('Ja'))
          ->addAnswer(new Answer('Nee', true));
        $q->ordering = 7;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek gebeld met de ontvoerde kandidaten tijdens de opdracht Happen, Trappen en Ontsnappen?';
        // No answer was marked correct in the source spreadsheet for this question; "Ja" was
        // chosen arbitrarily on import and is not necessarily correct for the real situation.
        $q->addAnswer(new Answer('Ja', true))
          ->addAnswer(new Answer('Nee'))
          ->addAnswer(new Answer('De Krtek was zelf één van de ontvoerde kandidaten'));
        $q->ordering = 8;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'In welke groep zat de Krtek tijdens de opdracht Happen, Trappen en Ontsnappen?';
        $q->addAnswer(new Answer('De langzame fietsgroep'))
          ->addAnswer(new Answer('De gemiddelde fietsgroep', true))
          ->addAnswer(new Answer('De snelle fietsgroep'))
          ->addAnswer(new Answer('De ontvoerde kandidaten'));
        $q->ordering = 9;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Als hoeveelste was het groepje van de Krtek op de Grebbeberg?';
        $q->addAnswer(new Answer('Als eerste'))
          ->addAnswer(new Answer('Als tweede'))
          ->addAnswer(new Answer('Het groepje van de Krtek kwam niet bij de Grebbeberg', true));
        $q->ordering = 10;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat is het favoriete muzieknummer allertijden van de Krtek?';
        $q->addAnswer(new Answer('Taylor Swift - Long Live'))
          ->addAnswer(new Answer('Kiefer Sutherland - Something you love'))
          ->addAnswer(new Answer('Yes-R - Hey Schatje'))
          ->addAnswer(new Answer('Bronsku Beat - Smalltown Boy', true))
          ->addAnswer(new Answer('Queen - Bohemian Rhapsody'))
          ->addAnswer(new Answer('Imagine Dragons - Birds'))
          ->addAnswer(new Answer('Chipz - 1001 Arabian Nights'))
          ->addAnswer(new Answer('Nightwish - Ghost Love Score'));
        $q->ordering = 11;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Aan welk gerecht heeft de Krtek de grootste bijdrage geleverd bij het koken?';
        $q->addAnswer(new Answer('Voorgerecht'))
          ->addAnswer(new Answer('Hoodgerecht', true))
          ->addAnswer(new Answer('Nagerecht'))
          ->addAnswer(new Answer('Geen bijdrage'));
        $q->ordering = 12;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek de fruitliedjesopdracht op de juiste GPS-locatie uitgevoerd?';
        $q->addAnswer(new Answer('Ja', true))
          ->addAnswer(new Answer('Nee'))
          ->addAnswer(new Answer('De Krtek heeft deze opdracht niet uitgevoerd'));
        $q->ordering = 13;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Als hoeveelste ging de Krtek vanmiddag biechten?';
        $q->addAnswer(new Answer('Eerste'))
          ->addAnswer(new Answer('Tweede'))
          ->addAnswer(new Answer('Derde'))
          ->addAnswer(new Answer('Vierde', true))
          ->addAnswer(new Answer('Vijfde'))
          ->addAnswer(new Answer('Zesde'))
          ->addAnswer(new Answer('Zevende'))
          ->addAnswer(new Answer('Achtste'))
          ->addAnswer(new Answer('Negende'))
          ->addAnswer(new Answer('Tiende'))
          ->addAnswer(new Answer('Elfde'))
          ->addAnswer(new Answer('Twaalfde'))
          ->addAnswer(new Answer('Dertiende'));
        $q->ordering = 14;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wie is de Krtek?';
        $q->addAnswer(new Answer('Claudia', true))
          ->addAnswer(new Answer('Eelco'))
          ->addAnswer(new Answer('Elise'))
          ->addAnswer(new Answer('Gert-Jan'))
          ->addAnswer(new Answer('Iris'))
          ->addAnswer(new Answer('Jari'))
          ->addAnswer(new Answer('Lara'))
          ->addAnswer(new Answer('Lotte'))
          ->addAnswer(new Answer('Myrthe'))
          ->addAnswer(new Answer('Philine'))
          ->addAnswer(new Answer('Remy'))
          ->addAnswer(new Answer('Robbert'))
          ->addAnswer(new Answer('Tom'));
        $q->ordering = 15;
        $quiz->addQuestion($q);

        return $quiz;
    }

    private function createQuiz5(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 5';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Man'))
          ->addAnswer(new Answer('Vrouw', true));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Bij welk team hoorde de Krtek tijdens de opdracht "Pyjamaparty"?';
        $q->addAnswer(new Answer('Team Groen'))
          ->addAnswer(new Answer('Team Rood', true));
        $q->ordering = 2;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = "Is de Krtek een 80's, 90's of 00's -kid?";
        $q->addAnswer(new Answer("80's"))
          ->addAnswer(new Answer("90's", true))
          ->addAnswer(new Answer("00's"));
        $q->ordering = 3;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat is de lievelingsgeur van de Krtek?';
        $q->addAnswer(new Answer('Banaan'))
          ->addAnswer(new Answer('Blauw'))
          ->addAnswer(new Answer('De zee'))
          ->addAnswer(new Answer('Verse appeltaart'))
          ->addAnswer(new Answer('Glow van JLO'))
          ->addAnswer(new Answer('Lavendel'))
          ->addAnswer(new Answer('Vanille', true));
        $q->ordering = 4;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Aan welke tafel zat de Krtek bij het avondeten?';
        $q->addAnswer(new Answer('Aan de tafel het dichtst bij het terras'))
          ->addAnswer(new Answer('Aan de tafel in het midden'))
          ->addAnswer(new Answer('Aan de tafel het dichtst bij het fornuis', true));
        $q->ordering = 5;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heeft de Krtek iets in een wijnglas gegoten bij de poging om een rood bruisend drankje te maken?';
        $q->addAnswer(new Answer('Ja'))
          ->addAnswer(new Answer('Nee', true));
        $q->ordering = 6;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wie was de kamergenoot van de Krtek tijdens de trust Nobody-Reis in Tsjechië?';
        $q->addAnswer(new Answer('Daniëlle'))
          ->addAnswer(new Answer('Edwin'))
          ->addAnswer(new Answer('Eelco'))
          ->addAnswer(new Answer('Elise'))
          ->addAnswer(new Answer('Gerry'))
          ->addAnswer(new Answer('Gert-Jan'))
          ->addAnswer(new Answer('Joelle'))
          ->addAnswer(new Answer('Lara'))
          ->addAnswer(new Answer('Lotte'))
          ->addAnswer(new Answer('Myrthe'))
          ->addAnswer(new Answer('Niemand', true));
        $q->ordering = 7;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Met welke bekende persoon zou de Krtek wel eens een beschuitje willen eten?';
        $q->addAnswer(new Answer('Art Rooijakkers'))
          ->addAnswer(new Answer('Emma Watson', true))
          ->addAnswer(new Answer('India de Beaufort'))
          ->addAnswer(new Answer('Jennifer Aniston'))
          ->addAnswer(new Answer('Lewis Hamilton'))
          ->addAnswer(new Answer('Sara Bereilles'));
        $q->ordering = 8;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Heb de Krtek wel eens iets gestolen? Zo ja, wat?';
        $q->addAnswer(new Answer('Nee'))
          ->addAnswer(new Answer('Ja, snoep'))
          ->addAnswer(new Answer('Ja, een stripboek', true))
          ->addAnswer(new Answer('Hooguit wat koeken uit de keukenkastjes'))
          ->addAnswer(new Answer('Ja, meermaals onbewust niet gescande boodschappen, met opzet een een sleutelhanger in Disneyland Parijs, een Gamecube spel van een klasgenoot en pins in de Super Panda Circus'));
        $q->ordering = 9;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat dronk de Krtek tijdens de opdracht "Pyjamaparty"?';
        $q->addAnswer(new Answer('Water', true))
          ->addAnswer(new Answer('Chocomel'))
          ->addAnswer(new Answer('Bier'))
          ->addAnswer(new Answer('Fanta'))
          ->addAnswer(new Answer('Cola'))
          ->addAnswer(new Answer('Cola zero'))
          ->addAnswer(new Answer('Rode wijn'))
          ->addAnswer(new Answer('Witte wijn'));
        $q->ordering = 10;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wat is het lievelingsdrankje van de Krtek?';
        $q->addAnswer(new Answer('Cola zero'))
          ->addAnswer(new Answer('Dubbelfris Appel/Perzik'))
          ->addAnswer(new Answer('IPA bier'))
          ->addAnswer(new Answer('Lindemans biertje'))
          ->addAnswer(new Answer('Moijto'))
          ->addAnswer(new Answer('Rode wijn'))
          ->addAnswer(new Answer('Sgroppino', true))
          ->addAnswer(new Answer('Virgin mojito'));
        $q->ordering = 11;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'In welke huiskamer zat de Krtek tijdens het kijken van de aflevering in de opdracht "Pyjamaparty"?';
        $q->addAnswer(new Answer('Bellefleur', true))
          ->addAnswer(new Answer('Juttepeer'));
        $q->ordering = 12;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Waar zat de Krtek op tijdens de opdracht "Pyjamaparty"?';
        $q->addAnswer(new Answer('Een stoel'))
          ->addAnswer(new Answer('Een bank', true))
          ->addAnswer(new Answer('Een krukje'))
          ->addAnswer(new Answer('De grond'));
        $q->ordering = 13;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Welke bekende persoon kan de Krtek echt niet uitstaan?';
        $q->addAnswer(new Answer('Chantal Janzen', true))
          ->addAnswer(new Answer('Gordon'))
          ->addAnswer(new Answer('Linda de Mol'))
          ->addAnswer(new Answer('Maurice de Hond'))
          ->addAnswer(new Answer('Sjaak Zwart'))
          ->addAnswer(new Answer('Thierry Baudet'));
        $q->ordering = 14;
        $quiz->addQuestion($q);

        $q = new Question();
        $q->question = 'Wie is de Krtek?';
        $q->addAnswer(new Answer('Claudia', true))
          ->addAnswer(new Answer('Eelco'))
          ->addAnswer(new Answer('Elise'))
          ->addAnswer(new Answer('Gert-Jan'))
          ->addAnswer(new Answer('Iris'))
          ->addAnswer(new Answer('Jari'))
          ->addAnswer(new Answer('Lara'))
          ->addAnswer(new Answer('Lotte'))
          ->addAnswer(new Answer('Myrthe'))
          ->addAnswer(new Answer('Philine'))
          ->addAnswer(new Answer('Remy'))
          ->addAnswer(new Answer('Rianne'))
          ->addAnswer(new Answer('Robbert'))
          ->addAnswer(new Answer('Rowan'))
          ->addAnswer(new Answer('Tom'));
        $q->ordering = 15;
        $quiz->addQuestion($q);

        return $quiz;
    }

    private function createQuiz6(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 6';
        $quiz->season = $season;

        return $quiz;
    }

    private function createQuiz7(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 7';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Man'))
          ->addAnswer(new Answer('Vrouw', true));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $quiz->finalizedAt = new DateTimeImmutable();

        return $quiz;
    }

    private function createQuiz8(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 8';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Man'))
          ->addAnswer(new Answer('Vrouw', true));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $quiz->finalizedAt = new DateTimeImmutable();

        return $quiz;
    }

    private function createQuiz9(Season $season): Quiz
    {
        $quiz = new Quiz();
        $quiz->name = 'Quiz 9';
        $quiz->season = $season;

        $q = new Question();
        $q->question = 'Is de Krtek een man of een vrouw?';
        $q->addAnswer(new Answer('Man'))
          ->addAnswer(new Answer('Vrouw', true));
        $q->ordering = 1;
        $quiz->addQuestion($q);

        $quiz->finalizedAt = new DateTimeImmutable();

        return $quiz;
    }
}
