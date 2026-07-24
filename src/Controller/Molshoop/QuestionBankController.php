<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tvdt\Controller\AbstractController;
use Tvdt\Entity\BankQuestion;
use Tvdt\Entity\BankQuestionUsage;
use Tvdt\Entity\QuestionLabel;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Enum\FlashType;
use Tvdt\Enum\LabelColour;
use Tvdt\Exception\BankQuestionAlreadyUsedException;
use Tvdt\Exception\BankQuestionIncompleteException;
use Tvdt\Exception\QuizLockedException;
use Tvdt\Form\BankQuestionFormType;
use Tvdt\Repository\BankQuestionRepository;
use Tvdt\Repository\QuestionLabelRepository;
use Tvdt\Repository\QuizRepository;
use Tvdt\Security\Voter\SeasonVoter;
use Tvdt\Service\QuestionBankService;

#[AsController]
#[IsGranted('IS_AUTHENTICATED')]
class QuestionBankController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
        private readonly BankQuestionRepository $bankQuestionRepository,
        private readonly QuestionLabelRepository $questionLabelRepository,
        private readonly QuizRepository $quizRepository,
        private readonly QuestionBankService $questionBankService,
        private readonly SluggerInterface $slugger,
    ) {}

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank',
        name: 'tvdt_molshoop_question_bank',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        priority: 10,
    )]
    public function index(Season $season, Request $request): Response
    {
        $label = null;
        $labelSlug = $request->query->getString('label');
        if ('' !== $labelSlug) {
            $label = $this->questionLabelRepository->findBySlugAndSeason($labelSlug, $season);
        }

        return $this->render('molshoop/season.html.twig', [
            'season' => $season,
            'bankQuestions' => $this->bankQuestionRepository->findBySeason($season, $label),
            'assignableQuizzes' => $this->quizRepository->findAssignableForSeason($season),
            'activeLabel' => $label,
            'labelColours' => LabelColour::cases(),
            'activeTab' => 'question-bank',
            'template' => 'molshoop/season/tab_question_bank.html.twig',
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/new',
        name: 'tvdt_molshoop_question_bank_new',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        priority: 10,
    )]
    public function new(Season $season, Request $request): Response
    {
        $bankQuestion = new BankQuestion();

        $isTurboFrame = $request->headers->has('Turbo-Frame');

        $form = $this->createForm(BankQuestionFormType::class, $bankQuestion, [
            'season' => $season,
            'action' => $this->generateUrl('tvdt_molshoop_question_bank_new', ['seasonCode' => $season->seasonCode]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $season->addBankQuestion($bankQuestion);
            $this->em->persist($bankQuestion);
            $this->em->flush();

            $this->addFlash(FlashType::Success, $this->translator->trans('Question added to the question bank'));

            if ($isTurboFrame) {
                return new Response('<turbo-frame id="bank-question-modal-frame"></turbo-frame>');
            }

            return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
        }

        $template = $isTurboFrame
            ? 'molshoop/question_bank/_frame.html.twig'
            : 'molshoop/question_bank/form.html.twig';

        return $this->render($template, [
            'season' => $season,
            'form' => $form,
            'bankQuestion' => null,
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/{bankQuestion}/edit',
        name: 'tvdt_molshoop_question_bank_edit',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'bankQuestion' => Requirement::UUID],
        priority: 10,
    )]
    public function edit(Season $season, BankQuestion $bankQuestion, Request $request): Response
    {
        $this->assertSameSeason($season, $bankQuestion->season);

        $isTurboFrame = $request->headers->has('Turbo-Frame');

        $form = $this->createForm(BankQuestionFormType::class, $bankQuestion, [
            'season' => $season,
            'action' => $this->generateUrl('tvdt_molshoop_question_bank_edit', [
                'seasonCode' => $season->seasonCode,
                'bankQuestion' => $bankQuestion->id,
            ]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->syncUsagesAfterEdit($bankQuestion);

            $this->addFlash(FlashType::Success, $this->translator->trans('Question updated'));

            if ($isTurboFrame) {
                return new Response('<turbo-frame id="bank-question-modal-frame"></turbo-frame>');
            }

            return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
        }

        $template = $isTurboFrame
            ? 'molshoop/question_bank/_frame.html.twig'
            : 'molshoop/question_bank/form.html.twig';

        return $this->render($template, [
            'season' => $season,
            'form' => $form,
            'bankQuestion' => $bankQuestion,
        ]);
    }

    #[IsCsrfTokenValid('delete_bank_question')]
    #[IsGranted(SeasonVoter::DELETE, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/{bankQuestion}/delete',
        name: 'tvdt_molshoop_question_bank_delete',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'bankQuestion' => Requirement::UUID],
        methods: ['POST'],
        priority: 10,
    )]
    public function delete(Season $season, BankQuestion $bankQuestion): RedirectResponse
    {
        $this->assertSameSeason($season, $bankQuestion->season);

        $hasLockedUsages = $bankQuestion->usages->exists(
            static fn (int $key, BankQuestionUsage $usage): bool => $usage->quiz->isLocked,
        );
        if ($hasLockedUsages) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('This question cannot be deleted because it is used in a locked or active quiz'));

            return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
        }

        $this->em->remove($bankQuestion);
        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Question removed from the question bank'));

        return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('assign_bank_question')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/{bankQuestion}/assign',
        name: 'tvdt_molshoop_question_bank_assign',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'bankQuestion' => Requirement::UUID],
        methods: ['POST'],
        priority: 10,
    )]
    public function assign(Season $season, BankQuestion $bankQuestion, Request $request): RedirectResponse
    {
        $this->assertSameSeason($season, $bankQuestion->season);

        $quizId = $request->request->getString('quiz');
        if (!Uuid::isValid($quizId)) {
            throw new BadRequestHttpException('Invalid quiz');
        }

        $quiz = $this->em->getRepository(Quiz::class)->find($quizId);
        if (!$quiz instanceof Quiz || $quiz->season !== $season) {
            throw new BadRequestHttpException('Invalid quiz');
        }

        $this->denyAccessUnlessGranted(SeasonVoter::MODIFY_QUIZ_CONTENT, $quiz);

        try {
            $this->questionBankService->assignToQuiz($bankQuestion, $quiz);
            $this->addFlash(FlashType::Success, $this->translator->trans('Question added to quiz {quiz}', ['quiz' => $quiz->name]));
        } catch (QuizLockedException) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('This quiz can no longer be altered'));
        } catch (BankQuestionAlreadyUsedException) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('This question has already been used'));
        } catch (BankQuestionIncompleteException) {
            $this->addFlash(FlashType::Warning, $this->translator->trans('This question is incomplete: it needs at least two answers and exactly one correct answer'));
        }

        return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('add_question_label')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/labels',
        name: 'tvdt_molshoop_question_bank_labels',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        methods: ['POST'],
        priority: 15,
    )]
    public function addLabel(Season $season, Request $request): RedirectResponse
    {
        $name = mb_trim($request->request->getString('name'));

        if ('' === $name || mb_strlen($name) > 64) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Invalid label name'));

            return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
        }

        $slug = mb_strtolower($this->slugger->slug($name)->toString());

        $colour = LabelColour::tryFrom($request->request->getString('colour')) ?? LabelColour::Gray;

        $exists = $season->questionLabels->exists(static fn (int $key, QuestionLabel $label): bool => $label->name === $name);
        if (!$exists) {
            if ($this->questionLabelRepository->slugExistsForSeason($slug, $season)) {
                $this->addFlash(FlashType::Danger, $this->translator->trans('A label with a similar name already exists'));

                return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
            }

            try {
                $newLabel = new QuestionLabel($name);
                $newLabel->slug = $slug;
                $newLabel->colour = $colour;
                $season->addQuestionLabel($newLabel);
                $this->em->flush();
                $this->addFlash(FlashType::Success, $this->translator->trans('Label added'));
            } catch (UniqueConstraintViolationException) {
                // Concurrent request already inserted the same label; treat as a no-op
            }
        }

        return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('delete_question_label')]
    #[IsGranted(SeasonVoter::DELETE, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/labels/{labelSlug}/delete',
        name: 'tvdt_molshoop_question_bank_label_delete',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'labelSlug' => '[a-z0-9-]+'],
        methods: ['POST'],
        priority: 15,
    )]
    public function deleteLabel(Season $season, string $labelSlug): RedirectResponse
    {
        $label = $this->questionLabelRepository->findBySlugAndSeason($labelSlug, $season);
        if (!$label instanceof QuestionLabel) {
            throw $this->createNotFoundException();
        }

        foreach ($label->bankQuestions as $bankQuestion) {
            $bankQuestion->removeLabel($label);
        }

        $this->em->remove($label);
        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Label removed'));

        return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('unassign_bank_question')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/{bankQuestion}/unassign/{usage}',
        name: 'tvdt_molshoop_question_bank_unassign',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'bankQuestion' => Requirement::UUID, 'usage' => Requirement::UUID],
        methods: ['POST'],
        priority: 10,
    )]
    public function unassign(Season $season, BankQuestion $bankQuestion, BankQuestionUsage $usage): RedirectResponse
    {
        $this->assertSameSeason($season, $bankQuestion->season);

        if ($usage->bankQuestion !== $bankQuestion) {
            throw new NotFoundHttpException();
        }

        if ($usage->quiz->isLocked) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('This quiz can no longer be altered'));

            return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
        }

        $this->questionBankService->unassignFromQuiz($usage);
        $this->addFlash(FlashType::Success, $this->translator->trans('Question removed from quiz {quiz}', ['quiz' => $usage->quiz->name]));

        return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('sync_bank_question')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/question-bank/{bankQuestion}/sync/{usage}',
        name: 'tvdt_molshoop_question_bank_sync',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'bankQuestion' => Requirement::UUID, 'usage' => Requirement::UUID],
        methods: ['POST'],
        priority: 10,
    )]
    public function syncToQuiz(Season $season, BankQuestion $bankQuestion, BankQuestionUsage $usage): RedirectResponse
    {
        $this->assertSameSeason($season, $bankQuestion->season);

        if ($usage->bankQuestion !== $bankQuestion) {
            throw new NotFoundHttpException();
        }

        if ($usage->quiz->isLocked) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('This quiz can no longer be altered'));

            return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
        }

        $this->questionBankService->syncToQuiz($bankQuestion, $usage);
        $this->em->flush();
        $this->addFlash(FlashType::Success, $this->translator->trans('Question synced to quiz {quiz}', ['quiz' => $usage->quiz->name]));

        return $this->redirectToRoute('tvdt_molshoop_question_bank', ['seasonCode' => $season->seasonCode]);
    }

    private function syncUsagesAfterEdit(BankQuestion $bankQuestion): void
    {
        $pendingNames = [];
        $synced = false;
        foreach ($bankQuestion->usages as $usage) {
            if (!$usage->quiz->isLocked) {
                $this->questionBankService->syncToQuiz($bankQuestion, $usage);
                $synced = true;
            } else {
                $pendingNames[] = $usage->quiz->name;
            }
        }

        if ($synced) {
            $this->em->flush();
        }

        if ([] !== $pendingNames) {
            $this->addFlash(
                FlashType::Warning,
                $this->translator->trans(
                    'The question was not synced to finalized quiz(zes): {quizzes}. Use the Sync button to update them.',
                    ['quizzes' => implode(', ', $pendingNames)],
                ),
            );
        }
    }
}
