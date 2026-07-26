<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTime;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tvdt\Controller\AbstractController;
use Tvdt\Entity\Candidate;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Entity\User;
use Tvdt\Enum\FlashType;
use Tvdt\Exception\SpreadsheetDataException;
use Tvdt\Form\AddCandidatesFormType;
use Tvdt\Form\SettingsForm;
use Tvdt\Form\UploadQuizFormType;
use Tvdt\Repository\CandidateRepository;
use Tvdt\Repository\QuizRepository;
use Tvdt\Repository\UserRepository;
use Tvdt\Security\Voter\SeasonVoter;
use Tvdt\Service\QuizSpreadsheetService;

#[AsController]
#[IsGranted('IS_AUTHENTICATED')]
class SeasonController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
        private readonly QuizSpreadsheetService $quizSpreadsheet,
        private readonly CandidateRepository $candidateRepository,
        private readonly QuizRepository $quizRepository,
        private readonly UserRepository $userRepository,
    ) {}

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}',
        name: 'tvdt_molshoop_season',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
    )]
    public function index(Season $season): Response
    {
        $this->quizRepository->eagerLoadStatusDataForSeason($season);

        return $this->render('molshoop/season.html.twig', [
            'season' => $season,
            'activeTab' => 'tests',
            'template' => 'molshoop/season/tab_tests.html.twig',
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/candidates',
        name: 'tvdt_molshoop_season_candidates',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        priority: 10,
    )]
    public function candidatesTab(Season $season): Response
    {
        return $this->render('molshoop/season.html.twig', [
            'season' => $season,
            'activeTab' => 'candidates',
            'template' => 'molshoop/season/tab_candidates.html.twig',
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/settings',
        name: 'tvdt_molshoop_season_settings',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        priority: 10,
    )]
    public function settingsTab(Season $season, Request $request): Response
    {
        $form = $this->createForm(SettingsForm::class, $season->settings);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
        }

        return $this->render('molshoop/season.html.twig', [
            'season' => $season,
            'form' => $form,
            'activeTab' => 'settings',
            'template' => 'molshoop/season/tab_settings.html.twig',
        ]);
    }

    #[IsCsrfTokenValid('regenerate_season_code')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/settings/regenerate-code',
        name: 'tvdt_molshoop_season_regenerate_code',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        methods: ['POST'],
    )]
    public function regenerateSeasonCode(Season $season): RedirectResponse
    {
        $season->generateSeasonCode();
        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Season code regenerated'));

        return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('add_season_owner')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/settings/add-owner',
        name: 'tvdt_molshoop_season_add_owner',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        methods: ['POST'],
    )]
    public function addOwner(Season $season, Request $request): RedirectResponse
    {
        $email = $request->request->getString('email');
        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('No user found with this email address'));

            return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
        }

        if ($season->isOwner($user)) {
            $this->addFlash(FlashType::Info, $this->translator->trans('This user is already an owner of this season'));

            return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
        }

        $season->addOwner($user);
        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Owner added'));

        return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('remove_season_owner')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/settings/owner/{owner}/remove',
        name: 'tvdt_molshoop_season_remove_owner',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'owner' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function removeOwner(Season $season, User $owner): RedirectResponse
    {
        if (!$season->isOwner($owner)) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('This user is not an owner of this season'));

            return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
        }

        if ($season->owners->count() <= 1) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Cannot remove the last owner of a season'));

            return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
        }

        $isSelf = $owner === $this->authenticatedUser;

        $season->removeOwner($owner);
        $this->em->flush();

        if ($isSelf) {
            $this->addFlash(FlashType::Success, $this->translator->trans('You left the season'));

            return $this->redirectToRoute('tvdt_molshoop_index');
        }

        $this->addFlash(FlashType::Success, $this->translator->trans('Owner removed'));

        return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('delete_season')]
    #[IsGranted(SeasonVoter::DELETE, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/settings/delete',
        name: 'tvdt_molshoop_season_delete',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        methods: ['POST'],
    )]
    public function deleteSeason(Season $season, Request $request): RedirectResponse
    {
        $confirmation = mb_strtolower(mb_trim($request->request->getString('confirmation')));

        if (!\in_array($confirmation, ['verwijderen', 'delete'], true)) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('Type delete to confirm'));

            return $this->redirectToRoute('tvdt_molshoop_season_settings', ['seasonCode' => $season->seasonCode]);
        }

        // Soft delete only: this hides the season everywhere without touching its tests,
        // candidates, or answers, so it can still be restored later (see issue for restoring a
        // soft-deleted season). Permanent removal only happens via GDPR account deletion
        // (UserRepository::deleteUser()) or the scheduled cleanup job (see issue for that).
        $season->setDeletedAt(new DateTime());
        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Season deleted'));

        return $this->redirectToRoute('tvdt_molshoop_index');
    }

    #[IsCsrfTokenValid('rename_season')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/rename',
        name: 'tvdt_molshoop_season_rename',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        methods: ['POST'],
    )]
    public function renameSeason(Season $season, Request $request): RedirectResponse
    {
        $name = mb_trim($request->request->getString('name'));

        if ('' === $name || mb_strlen($name) > 64) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('The season name must be between 1 and 64 characters'));

            return $this->redirectToRoute('tvdt_molshoop_season', ['seasonCode' => $season->seasonCode]);
        }

        $season->name = $name;
        $this->em->flush();

        $this->addFlash(FlashType::Success, $this->translator->trans('Season renamed'));

        return $this->redirectToRoute('tvdt_molshoop_season', ['seasonCode' => $season->seasonCode]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/add-candidate',
        name: 'tvdt_molshoop_add_candidates',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        priority: 10,
    )]
    public function addCandidates(Season $season, Request $request): Response
    {
        $isTurboFrame = $request->headers->has('Turbo-Frame');

        $form = $this->createForm(AddCandidatesFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $candidates = $form->get('candidates')->getData();
            foreach (explode("\n", (string) $candidates) as $candidate) {
                $season->addCandidate(new Candidate(mb_rtrim($candidate)));
            }

            $this->em->flush();

            if ($isTurboFrame) {
                return new Response('<turbo-frame id="add-candidates-modal-frame"></turbo-frame>');
            }

            return $this->redirectToRoute('tvdt_molshoop_season_candidates', ['seasonCode' => $season->seasonCode]);
        }

        $template = $isTurboFrame
            ? 'molshoop/season/_add_candidates_frame.html.twig'
            : 'molshoop/season_add_candidates.html.twig';

        return $this->render($template, ['form' => $form, 'season' => $season]);
    }

    #[IsCsrfTokenValid('rename_candidate')]
    #[IsGranted(SeasonVoter::EDIT, subject: 'candidate')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/candidate/{candidate}/rename',
        name: 'tvdt_molshoop_candidate_rename',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'candidate' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function renameCandidate(Season $season, Candidate $candidate, Request $request): RedirectResponse
    {
        $name = mb_trim($request->request->getString('name'));

        if ('' === $name || mb_strlen($name) > 16) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('The candidate name must be between 1 and 16 characters'));

            return $this->redirectToRoute('tvdt_molshoop_season_candidates', ['seasonCode' => $season->seasonCode]);
        }

        $candidate->name = $name;

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash(FlashType::Danger, $this->translator->trans('A candidate with this name already exists in this season'));

            return $this->redirectToRoute('tvdt_molshoop_season_candidates', ['seasonCode' => $season->seasonCode]);
        }

        $this->addFlash(FlashType::Success, $this->translator->trans('Candidate renamed'));

        return $this->redirectToRoute('tvdt_molshoop_season_candidates', ['seasonCode' => $season->seasonCode]);
    }

    #[IsCsrfTokenValid('delete_candidate')]
    #[IsGranted(SeasonVoter::DELETE, subject: 'candidate')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/candidate/{candidate}/delete',
        name: 'tvdt_molshoop_candidate_delete',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'candidate' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function deleteCandidate(Season $season, Candidate $candidate): RedirectResponse
    {
        $this->candidateRepository->deleteCandidate($candidate);

        $this->addFlash(FlashType::Success, $this->translator->trans('Candidate deleted'));

        return $this->redirectToRoute('tvdt_molshoop_season_candidates', ['seasonCode' => $season->seasonCode]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/add-quiz',
        name: 'tvdt_molshoop_quiz_add',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        priority: 10,
    )]
    public function addQuiz(Request $request, Season $season): Response
    {
        $quiz = new Quiz();
        $form = $this->createForm(UploadQuizFormType::class, $quiz);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /* @var UploadedFile $sheet */
            $sheet = $form->get('sheet')->getData();

            try {
                $this->quizSpreadsheet->xlsxToQuiz($quiz, $sheet);
            } catch (SpreadsheetDataException $spreadsheetDataException) {
                foreach ($spreadsheetDataException->errors as $error) {
                    $this->addFlash(FlashType::Danger, $error);
                }

                return $this->redirectToRoute('tvdt_molshoop_quiz_add', ['seasonCode' => $season->seasonCode]);
            }

            $quiz->season = $season;
            $this->em->persist($quiz);
            $this->em->flush();

            $this->addFlash(FlashType::Success, $this->translator->trans('Quiz Added!'));

            return $this->redirectToRoute('tvdt_molshoop_season', ['seasonCode' => $season->seasonCode]);
        }

        return $this->render('/molshoop/quiz_add.html.twig', ['form' => $form, 'season' => $season]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/add-blank-quiz',
        name: 'tvdt_molshoop_quiz_add_blank',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX],
        priority: 10,
    )]
    public function addBlankQuiz(Request $request, Season $season): Response
    {
        $form = $this->createFormBuilder(new Quiz())
            ->add('name', TextType::class, [
                'label' => $this->translator->trans('Quiz name'),
                'translation_domain' => false,
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 64),
                ],
            ])
            ->add('save', SubmitType::class, ['label' => $this->translator->trans('Create'), 'translation_domain' => false])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Quiz $quiz */
            $quiz = $form->getData();
            $quiz->season = $season;
            $this->em->persist($quiz);

            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                $form->get('name')->addError(new FormError($this->translator->trans('A quiz with this name already exists in this season')));

                return $this->render('/molshoop/quiz_add_blank.html.twig', ['form' => $form, 'season' => $season]);
            }

            $this->addFlash(FlashType::Success, $this->translator->trans('Quiz Added!'));

            return $this->redirectToRoute('tvdt_molshoop_quiz_overview', [
                'seasonCode' => $season->seasonCode,
                'quiz' => $quiz->id,
            ]);
        }

        return $this->render('/molshoop/quiz_add_blank.html.twig', ['form' => $form, 'season' => $season]);
    }
}
