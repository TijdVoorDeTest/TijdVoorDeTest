<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tvdt\Controller\AbstractController;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Entity\SeasonSettings;
use Tvdt\Enum\FlashType;
use Tvdt\Form\CreateSeasonFormType;
use Tvdt\Helpers\FilenameSanitizer;
use Tvdt\Repository\SeasonRepository;
use Tvdt\Security\Voter\SeasonVoter;
use Tvdt\Service\QuizSpreadsheetService;

#[AsController]
#[IsGranted('IS_AUTHENTICATED')]
final class MolshoopController extends AbstractController
{
    public function __construct(
        private readonly SeasonRepository $seasonRepository,
        private readonly QuizSpreadsheetService $excel,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/molshoop/', name: 'tvdt_molshoop_index')]
    public function index(): Response
    {
        return $this->render('molshoop/index.html.twig', [
            'seasons' => $this->seasonRepository->getSeasonsForUser($this->authenticatedUser),
        ]);
    }

    #[Route('/molshoop/season/add', name: 'tvdt_molshoop_season_add', priority: 10)]
    public function addSeason(Request $request): Response
    {
        $season = new Season();
        $form = $this->createForm(CreateSeasonFormType::class, $season);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $season->addOwner($this->authenticatedUser);
            $season->generateSeasonCode();
            if ($season->settings instanceof SeasonSettings) {
                $season->settings->locale = $this->authenticatedUser->locale;
            }

            $this->em->persist($season);
            $this->em->flush();

            return $this->redirectToRoute('tvdt_molshoop_season', ['seasonCode' => $season->seasonCode]);
        }

        return $this->render('molshoop/season_add.html.twig', ['form' => $form]);
    }

    #[Route('/molshoop/template', name: 'tvdt_molshoop_template', priority: 10)]
    public function getTemplate(): StreamedResponse
    {
        $response = new StreamedResponse($this->excel->generateTemplate());
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="template.xlsx"');

        return $response;
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'quiz')]
    #[Route(
        '/molshoop/quiz/{quiz}/export',
        name: 'tvdt_molshoop_quiz_export',
        requirements: ['quiz' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function exportQuiz(Quiz $quiz): Response
    {
        if (!$this->authenticatedUser->isVerified) {
            $this->addFlash(FlashType::Warning, $this->translator->trans('Please confirm your email address before exporting a quiz.'));

            return $this->redirectToRoute('tvdt_molshoop_season', ['seasonCode' => $quiz->season->seasonCode]);
        }

        $response = new StreamedResponse($this->excel->quizToXlsx($quiz));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, FilenameSanitizer::sanitize($quiz->name).'.xlsx'));

        return $response;
    }
}
