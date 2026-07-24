<?php

declare(strict_types=1);

namespace Tvdt\Controller\Molshoop;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tvdt\Controller\AbstractController;
use Tvdt\Entity\Question;
use Tvdt\Entity\Quiz;
use Tvdt\Entity\Season;
use Tvdt\Enum\FlashType;
use Tvdt\Form\QuestionFormType;
use Tvdt\Security\Voter\SeasonVoter;

#[AsController]
#[IsGranted('IS_AUTHENTICATED')]
class QuizQuestionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[IsGranted(SeasonVoter::MODIFY_QUIZ_CONTENT, subject: 'question')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/quiz/{quiz}/question/{question}/edit',
        name: 'tvdt_molshoop_quiz_question_edit',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID, 'question' => Requirement::UUID],
    )]
    public function edit(Season $season, Quiz $quiz, Question $question, Request $request): Response
    {
        if ($question->quiz !== $quiz || $quiz->season !== $season) {
            throw new NotFoundHttpException();
        }

        $isTurboFrame = $request->headers->has('Turbo-Frame');

        $form = $this->createForm(QuestionFormType::class, $question, [
            'action' => $this->generateUrl('tvdt_molshoop_quiz_question_edit', [
                'seasonCode' => $season->seasonCode,
                'quiz' => $quiz->id,
                'question' => $question->id,
            ]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->addFlash(FlashType::Success, $this->translator->trans('Question updated'));

            if ($isTurboFrame) {
                return new Response('<turbo-frame id="question-modal-frame"></turbo-frame>');
            }

            return $this->redirectToRoute('tvdt_molshoop_quiz_overview', [
                'seasonCode' => $season->seasonCode,
                'quiz' => $quiz->id,
            ]);
        }

        $template = $isTurboFrame
            ? 'molshoop/quiz/_question_frame.html.twig'
            : 'molshoop/quiz/question_form.html.twig';

        return $this->render($template, [
            'season' => $season,
            'quiz' => $quiz,
            'question' => $question,
            'form' => $form,
        ]);
    }

    #[IsGranted(SeasonVoter::EDIT, subject: 'season')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/quiz/{quiz}/question/{question}/view',
        name: 'tvdt_molshoop_quiz_question_view',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID, 'question' => Requirement::UUID],
    )]
    public function view(Season $season, Quiz $quiz, Question $question): Response
    {
        if ($question->quiz !== $quiz || $quiz->season !== $season) {
            throw new NotFoundHttpException();
        }

        return $this->render('molshoop/quiz/_question_detail_frame.html.twig', [
            'question' => $question,
        ]);
    }

    #[IsCsrfTokenValid('question_reorder')]
    #[IsGranted(SeasonVoter::MODIFY_QUIZ_CONTENT, subject: 'quiz')]
    #[Route(
        '/molshoop/season/{seasonCode:season}/quiz/{quiz}/questions/reorder',
        name: 'tvdt_molshoop_quiz_questions_reorder',
        requirements: ['seasonCode' => self::SEASON_CODE_REGEX, 'quiz' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function reorder(Season $season, Quiz $quiz, Request $request): Response
    {
        if ($quiz->season !== $season) {
            throw new NotFoundHttpException();
        }

        /** @var list<string> $ordering */
        $ordering = $request->request->all('ordering');

        $questionsById = [];
        foreach ($quiz->questions as $question) {
            $questionsById[$question->id->toString()] = $question;
        }

        foreach ($ordering as $questionId) {
            if (!isset($questionsById[$questionId])) {
                throw new BadRequestHttpException(\sprintf('Unknown question id: %s', $questionId));
            }
        }

        if (\count(array_unique($ordering)) !== \count($questionsById)) {
            throw new BadRequestHttpException('Ordering must include every question exactly once');
        }

        $position = 1;
        foreach ($ordering as $questionId) {
            $questionsById[$questionId]->ordering = $position++;
        }

        $this->em->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
