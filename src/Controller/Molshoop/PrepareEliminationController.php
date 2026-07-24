<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Tvdt\Controller\AbstractController;
use Tvdt\Dto\Result;
use Tvdt\Entity\Elimination;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Enum\FlashType;
use Tvdt\Factory\EliminationFactory;
use Tvdt\Repository\EliminationRepository;
use Tvdt\Repository\QuizRepository;
use Tvdt\Security\Voter\SeasonVoter;

final class PrepareEliminationController extends AbstractController
{
    public function __construct(
        private readonly EliminationFactory $eliminationFactory,
        private readonly EntityManagerInterface $em,
        private readonly QuizRepository $quizRepository,
        private readonly EliminationRepository $eliminationRepository,
    ) {}

    #[IsCsrfTokenValid('prepare_elimination')]
    #[IsGranted(SeasonVoter::ELIMINATION, 'quiz')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/quiz/{quiz}/elimination/prepare',
        name: 'tvdt_prepare_elimination',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function index(Season $season, Quiz $quiz): RedirectResponse
    {
        $elimination = $this->eliminationFactory->createEliminationFromQuiz($quiz);

        return $this->redirectToRoute('tvdt_prepare_elimination_view', ['elimination' => $elimination->id]);
    }

    #[IsCsrfTokenValid('prepare_elimination', methods: ['POST'])]
    #[IsGranted(SeasonVoter::ELIMINATION, 'elimination')]
    #[Route(
        '/molshoop/elimination/{elimination}',
        name: 'tvdt_prepare_elimination_view',
        requirements: ['elimination' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function viewElimination(Elimination $elimination, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $elimination->updateFromInputBag($request->request);
            $this->em->flush();

            if ($request->request->getBoolean('start')) {
                return $this->redirectToRoute('tvdt_elimination', ['elimination' => $elimination->id]);
            }

            $this->addFlash(FlashType::Success, 'Elimination updated');

            return $this->redirectToRoute('tvdt_prepare_elimination_view', ['elimination' => $elimination->id]);
        }

        // Eager-load screenViews.candidate in one query to avoid an N+1 when the screen-view log table renders.
        $this->eliminationRepository->fetchWithScreenViewCandidates($elimination->id);

        return $this->render('molshoop/prepare_elimination/index.html.twig', [
            'controller_name' => 'PrepareEliminationController',
            'elimination' => $elimination,
            'candidates' => $this->getOrderedCandidates($elimination),
        ]);
    }

    /**
     * The candidates in an elimination (name => colour), ordered like the results list (score desc, time asc), each
     * paired with their live score/time so both can be shown while preparing the elimination. A candidate can be
     * missing a result if their given answers were reset after the elimination was prepared.
     *
     * @return array<string, ?Result>
     */
    private function getOrderedCandidates(Elimination $elimination): array
    {
        $resultsByName = [];
        foreach ($this->quizRepository->getScores($elimination->quiz) as $result) {
            $resultsByName[$result->name] = $result;
        }

        $candidates = array_intersect_key($resultsByName, $elimination->data);

        foreach (array_keys($elimination->data) as $name) {
            if (!\array_key_exists($name, $candidates)) {
                $candidates[$name] = null;
            }
        }

        return $candidates;
    }

    #[IsCsrfTokenValid('delete_elimination')]
    #[IsGranted(SeasonVoter::DELETE, 'elimination')]
    #[Route(
        '/molshoop/elimination/{elimination}/delete',
        name: 'tvdt_prepare_elimination_delete',
        requirements: ['elimination' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function deleteElimination(Elimination $elimination): RedirectResponse
    {
        $quiz = $elimination->quiz;

        $this->em->remove($elimination);
        $this->em->flush();

        $this->addFlash(FlashType::Success, 'Elimination deleted');

        return $this->redirectToRoute('tvdt_molshoop_quiz', ['seasonCode' => $quiz->season->seasonCode, 'quiz' => $quiz->id]);
    }
}
