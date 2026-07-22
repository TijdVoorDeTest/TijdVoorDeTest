<?php

declare(strict_types=1);

namespace Tvdt\Controller\Backoffice;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tvdt\Controller\AbstractController;
use Tvdt\Entity\Answer;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\QuizCandidate;
use Tvdt\Entity\Season;
use Tvdt\Enum\FlashType;
use Tvdt\Exception\ErrorClearingQuizException;
use Tvdt\Repository\GivenAnswerRepository;
use Tvdt\Repository\QuestionRepository;
use Tvdt\Repository\QuizCandidateRepository;
use Tvdt\Repository\QuizRepository;
use Tvdt\Security\Voter\SeasonVoter;

#[AsController]
#[IsGranted('IS_AUTHENTICATED')]
class QuizController extends AbstractController
{
    public function __construct(
        private readonly QuizRepository $quizRepository,
        private readonly TranslatorInterface $translator,
        private readonly QuizCandidateRepository $quizCandidateRepository,
        private readonly GivenAnswerRepository $givenAnswerRepository,
        private readonly QuestionRepository $questionRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}',
        name: 'tvdt_backoffice_quiz',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
    )]
    public function index(Season $season, Quiz $quiz): Response
    {
        return $this->redirectToRoute('tvdt_backoffice_quiz_overview', ['seasonCode' => $season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}/overview',
        name: 'tvdt_backoffice_quiz_overview',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
    )]
    public function overview(Season $season, Quiz $quiz): Response
    {
        $fetchedQuiz = $this->quizRepository->fetchWithQuestionsAndCandidates($quiz->id);

        return $this->render('backoffice/quiz.html.twig', [
            'season' => $season,
            'quiz' => $fetchedQuiz,
            'questionErrors' => $fetchedQuiz->getQuestionErrors(),
            'activeTab' => 'overview',
            'template' => 'backoffice/quiz/tab_overview.html.twig',
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}/result',
        name: 'tvdt_backoffice_quiz_result',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
    )]
    public function result(Season $season, Quiz $quiz): Response
    {
        $fetchedQuiz = $this->quizRepository->fetchWithQuestions($quiz->id);

        return $this->render('backoffice/quiz.html.twig', [
            'season' => $season,
            'quiz' => $fetchedQuiz,
            'result' => $this->quizRepository->getScores($quiz),
            'activeTab' => 'result',
            'template' => 'backoffice/quiz/tab_result.html.twig',
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}/candidates-list',
        name: 'tvdt_backoffice_quiz_candidates_tab',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
    )]
    public function candidatesTab(Season $season, Quiz $quiz): Response
    {
        $candidateData = $this->buildCandidateData($season, $quiz, $quiz->candidateData);

        return $this->render('backoffice/quiz.html.twig', [
            'season' => $season,
            'quiz' => $quiz,
            'candidateData' => $candidateData,
            'activeTab' => 'candidates',
            'template' => 'backoffice/quiz/tab_candidates_list.html.twig',
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}/answer-mapping',
        name: 'tvdt_backoffice_quiz_candidates',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
    )]
    public function answerMapping(Season $season, Quiz $quiz): Response
    {
        $fetchedQuiz = $this->quizRepository->fetchWithQuestions($quiz->id);

        if ($fetchedQuiz->questions->isEmpty()) {
            $this->addFlash(FlashType::Warning, $this->translator->trans('This quiz has no questions yet'));

            return $this->redirectToRoute('tvdt_backoffice_quiz_overview', ['seasonCode' => $season->seasonCode, 'quiz' => $quiz->id]);
        }

        $firstQuestion = $fetchedQuiz->questions->first();
        \assert($firstQuestion instanceof Question);

        return $this->redirectToRoute('tvdt_backoffice_quiz_candidates_question', [
            'seasonCode' => $season->seasonCode,
            'quiz' => $quiz->id,
            'question' => $firstQuestion->id,
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}/candidates/{question}',
        name: 'tvdt_backoffice_quiz_candidates_question',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function candidates_question(Season $season, Quiz $quiz, Question $question): Response
    {
        if (false === $season->quizzes->contains($quiz)
            || false === $quiz->questions->contains($question)) {
            throw new BadRequestHttpException('Invalid quiz or question');
        }

        // Eager-load this question's answers + candidates in one query to avoid an N+1
        // when the template checks `answer.candidates.contains(candidate)` per answer/candidate pair.
        $fetchedQuestion = $this->questionRepository->fetchWithAnswerCandidates($question->id);

        return $this->render('backoffice/quiz.html.twig', [
            'season' => $season,
            'quiz' => $quiz,
            'question' => $fetchedQuestion,
            'candidates' => $season->candidates,
            'activeTab' => 'answers',
            'template' => 'backoffice/quiz/tab_candidates.html.twig',
        ]);
    }

    #[IsCsrfTokenValid('candidate_answer')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}/candidates/{question}',
        name: 'tvdt_backoffice_quiz_candidates_question_save',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function saveCandidateAnswers(Season $season, Quiz $quiz, Question $question, Request $request): RedirectResponse
    {
        if (false === $season->quizzes->contains($quiz)
            || false === $quiz->questions->contains($question)) {
            throw new BadRequestHttpException('Invalid quiz or question');
        }

        $candidateAnswers = $request->request->all('candidate_answer');

        // Clear existing candidate-answer associations for this question
        foreach ($question->answers as $answer) {
            if (false === $quiz->questions->contains($answer->question)) {
                throw new BadRequestHttpException('Invalid question');
            }

            $answer->candidates->clear();
        }

        // Add new associations
        foreach ($candidateAnswers as $candidateId => $answerIds) {
            $candidate = $this->em->getRepository(Candidate::class)->find($candidateId);

            if (false === $season->candidates->contains($candidate)) {
                throw new BadRequestHttpException('Invalid candidate');
            }

            foreach ((array) $answerIds as $answerId) {
                $answer = $this->em->getRepository(Answer::class)->find($answerId);

                if (false === $question->answers->contains($answer)) {
                    throw new BadRequestHttpException('Invalid answer');
                }

                if ($answer && $candidate) {
                    $answer->addCandidate($candidate);
                }
            }
        }

        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Candidate answers saved'));

        return $this->redirectToRoute('tvdt_backoffice_quiz_candidates_question', [
            'seasonCode' => $season->seasonCode,
            'quiz' => $quiz->id,
            'question' => $question->id,
        ]);
    }

    #[IsCsrfTokenValid('enable_quiz')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/backoffice/season/{seasonCode:season}/quiz/{quiz}/enable',
        name: 'tvdt_backoffice_enable',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID.'|null'],
        methods: ['POST'],
    )]
    public function enableQuiz(Season $season, ?Quiz $quiz, Request $request): RedirectResponse
    {
        if ($quiz instanceof Quiz && !$quiz->isFinalized) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('The quiz must be finalized before it can be activated'));

            return $this->redirectToRoute('tvdt_backoffice_quiz_overview', ['seasonCode' => $season->seasonCode, 'quiz' => $quiz->id]);
        }

        $season->activeQuiz = $quiz;
        $this->em->flush();

        if ($quiz instanceof Quiz) {
            return $this->redirectToRoute('tvdt_backoffice_quiz_overview', ['seasonCode' => $season->seasonCode, 'quiz' => $quiz->id]);
        }

        // When deactivating, stay on the quiz page if one was passed
        $previousQuizId = $request->request->getString('redirect_quiz');
        if ('' !== $previousQuizId) {
            $previousQuiz = $this->em->getRepository(Quiz::class)->find($previousQuizId);
            if ($previousQuiz instanceof Quiz && $previousQuiz->season === $season) {
                return $this->redirectToRoute('tvdt_backoffice_quiz_overview', ['seasonCode' => $season->seasonCode, 'quiz' => $previousQuiz->id]);
            }
        }

        return $this->redirectToRoute('tvdt_backoffice_season', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('clear_quiz')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/clear',
        name: 'tvdt_backoffice_quiz_clear',
        requirements: ['quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function clearQuiz(Quiz $quiz): RedirectResponse
    {
        try {
            $this->quizRepository->clearQuiz($quiz);
            $this->addFlash(FlashType::Success, $this->translator->trans('Quiz cleared and no longer finalized'));
        } catch (ErrorClearingQuizException) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Error clearing quiz'));
        }

        return $this->redirectToRoute('tvdt_backoffice_quiz', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsCsrfTokenValid('finalize_quiz')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/finalize',
        name: 'tvdt_backoffice_quiz_finalize',
        requirements: ['quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function finalizeQuiz(Quiz $quiz): RedirectResponse
    {
        if ($quiz->questions->isEmpty() || [] !== $quiz->getQuestionErrors()) {
            $this->addFlash(FlashType::Warning, $this->translator->trans('The quiz cannot be finalized while it has errors'));
        } elseif (!$quiz->isFinalized) {
            $quiz->finalizedAt = new DateTimeImmutable();
            $this->em->flush();
            $this->addFlash(FlashType::Success, $this->translator->trans('Quiz finalized'));
        } else {
            $this->addFlash(FlashType::Warning, $this->translator->trans('The quiz is already finalized'));
        }

        return $this->redirectToRoute('tvdt_backoffice_quiz', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsCsrfTokenValid('unfinalize_quiz')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/unfinalize',
        name: 'tvdt_backoffice_quiz_unfinalize',
        requirements: ['quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function unfinalizeQuiz(Quiz $quiz): RedirectResponse
    {
        if ($quiz->hasStartedCandidates) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('The quiz has already been filled in and can no longer be altered'));
        } elseif ($quiz->season->activeQuiz === $quiz) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Deactivate the quiz before undoing the finalization'));
        } else {
            $quiz->finalizedAt = null;
            $this->em->flush();
            $this->addFlash(FlashType::Success, $this->translator->trans('Quiz is no longer finalized'));
        }

        return $this->redirectToRoute('tvdt_backoffice_quiz', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsCsrfTokenValid('rename_quiz')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/rename',
        name: 'tvdt_backoffice_quiz_rename',
        requirements: ['quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function renameQuiz(Quiz $quiz, Request $request): RedirectResponse
    {
        $name = mb_trim($request->request->getString('name'));

        if ('' === $name || mb_strlen($name) > 64) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('The quiz name must be between 1 and 64 characters'));

            return $this->redirectToRoute('tvdt_backoffice_quiz', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
        }

        $quiz->name = $name;

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('A quiz with this name already exists in this season'));

            return $this->redirectToRoute('tvdt_backoffice_quiz', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
        }

        $this->addFlash(FlashType::Success, $this->translator->trans('Quiz renamed'));

        return $this->redirectToRoute('tvdt_backoffice_quiz', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsCsrfTokenValid('delete_quiz')]
    #[IsGranted(SeasonVoter::DELETE, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/delete',
        name: 'tvdt_backoffice_quiz_delete',
        requirements: ['quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function deleteQuiz(Quiz $quiz): RedirectResponse
    {
        $this->quizRepository->deleteQuiz($quiz);

        $this->addFlash(FlashType::Success, $this->translator->trans('Quiz deleted'));

        return $this->redirectToRoute('tvdt_backoffice_season', ['seasonCode' => $quiz->season->seasonCode]);
    }

    #[IsCsrfTokenValid('candidate_result')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/candidate/{candidate}/modify_result',
        name: 'tvdt_backoffice_modify_result',
        requirements: ['quiz' => Requirement::UUID, 'candidate' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function modifyResult(Quiz $quiz, Candidate $candidate, Request $request): RedirectResponse
    {
        $corrections = (float) $request->request->get('corrections');
        $penalty = (int) $request->request->get('penalty');

        $this->quizCandidateRepository->setResultForCandidate($quiz, $candidate, $corrections, $penalty);

        return $this->redirectToRoute('tvdt_backoffice_quiz_result', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsCsrfTokenValid('quiz_dropouts')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/modify_dropouts',
        name: 'tvdt_backoffice_modify_dropouts',
        requirements: ['quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function modifyDropouts(Quiz $quiz, Request $request): RedirectResponse
    {
        $quiz->dropouts = max(1, $request->request->getInt('dropouts'));
        $this->em->flush();

        return $this->redirectToRoute('tvdt_backoffice_quiz_result', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsCsrfTokenValid('toggle_candidate')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/candidate/{candidate}/toggle',
        name: 'tvdt_backoffice_toggle_candidate',
        requirements: ['quiz' => Requirement::UUID, 'candidate' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function toggleCandidate(Quiz $quiz, Candidate $candidate): RedirectResponse
    {
        $quizCandidate = $this->quizCandidateRepository->findOneBy([
            'quiz' => $quiz,
            'candidate' => $candidate,
        ]);

        if (!$quizCandidate instanceof QuizCandidate) {
            // Create new QuizCandidate if it doesn't exist (inactive by default when first toggling)
            $quizCandidate = new QuizCandidate($quiz, $candidate);
            $quizCandidate->active = false;
            $this->em->persist($quizCandidate);
        } else {
            $quizCandidate->active = !$quizCandidate->active;
        }

        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Candidate status updated'));

        return $this->redirectToRoute('tvdt_backoffice_quiz_candidates_tab', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    #[IsCsrfTokenValid('reset_candidate_progress')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/backoffice/quiz/{quiz}/candidate/{candidate}/reset',
        name: 'tvdt_backoffice_reset_candidate_progress',
        requirements: ['quiz' => Requirement::UUID, 'candidate' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function resetCandidateProgress(Quiz $quiz, Candidate $candidate): RedirectResponse
    {
        $this->em->wrapInTransaction(function () use ($quiz, $candidate): void {
            $this->givenAnswerRepository->deleteAllForCandidateInQuiz($quiz, $candidate);
            $this->quizCandidateRepository->resetProgressForCandidate($quiz, $candidate);
        });

        $this->addFlash(FlashType::Success, $this->translator->trans('Candidate progress reset'));

        return $this->redirectToRoute('tvdt_backoffice_quiz_candidates_tab', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }

    /**
     * Pre-computes per-candidate data (quiz participation and given answer counts) to avoid nested loops in templates.
     *
     * @param iterable<QuizCandidate> $quizCandidates
     *
     * @return list<array{candidate: Candidate, quizCandidate: QuizCandidate|null, givenAnswersCount: int}>
     */
    private function buildCandidateData(Season $season, Quiz $quiz, iterable $quizCandidates): array
    {
        $quizCandidatesByCandidateId = [];
        foreach ($quizCandidates as $qc) {
            $quizCandidatesByCandidateId[$qc->candidate->id->toString()] = $qc;
        }

        $givenAnswersCountByCandidateId = $this->quizRepository->getGivenAnswersCountPerCandidate($quiz);

        $candidateData = [];
        foreach ($season->candidates as $candidate) {
            $candidateIdString = $candidate->id->toString();
            $candidateData[] = [
                'candidate' => $candidate,
                'quizCandidate' => $quizCandidatesByCandidateId[$candidateIdString] ?? null,
                'givenAnswersCount' => $givenAnswersCountByCandidateId[$candidateIdString] ?? 0,
            ];
        }

        return $candidateData;
    }
}
