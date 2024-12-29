<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\DTO\DocumentationDTO;
use App\Entity\AssessmentStream;
use App\Entity\Project;
use App\Enum\Custom\RemarkType;
use App\Exception\InsufficientAttachmentRemarkParameters;
use App\Exception\InsufficientPermissionsToSaveRemarkException;
use App\Form\Application\DocumentationType;
use App\Service\RemarkService;
use App\Service\SanitizerService;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/documentation', name: 'documentation_')]
#[IsGranted('ROLE_USER')]
class DocumentationController extends AbstractController
{
    #[Route('/documentation-page/{id}', name: 'documentation_page', requirements: ['id' => "\d+"], methods: ['GET'])]
    #[IsGranted('ASSESSMENT_STREAM_ACCESS', 'assessmentStream')]
    public function documentationPage(AssessmentStream $assessmentStream, RemarkService $remarkService): Response
    {
        return $this->documentationPartial($assessmentStream, $remarkService, 'application/documentation/documentation_standalone_page.html.twig', true);
    }

    #[Route('/documentation/{id}', name: 'documentation', requirements: ['id' => "\d+"], methods: ['GET'])]
    #[IsGranted('ASSESSMENT_STREAM_ACCESS', 'assessmentStream')]
    public function documentation(Request $request, AssessmentStream $assessmentStream, RemarkService $remarkService): Response
    {
        return $this->documentationPartial(
            $assessmentStream,
            $remarkService,
            'application/documentation/partials/_documentation_container.html.twig',
            true
        );
    }

    #[Route('/save-documentation/{id}', name: 'save_documentation', requirements: ['id' => "\d+"], methods: ['POST'])]
    #[IsGranted('SAVE_REMARK', 'assessmentStream')]
    public function saveDocumentation(
        Request $request,
        AssessmentStream $assessmentStream,
        RemarkService $remarkService,
        SanitizerService $sanitizer
    ): JsonResponse {
        try {
            $currentUser = $this->getUser();

            $form = $this->createForm(
                DocumentationType::class,
                new DocumentationDTO(),
                [
                    'project' => $assessmentStream->getAssessment()->getProject(),
                ]
            );
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var DocumentationDTO $documentationDTO */
                $documentationDTO = $form->getData();
                $documentationDTO->setText($sanitizer->sanitizeValue($documentationDTO->getText()));
                if ($documentationDTO->getRemarkType() === RemarkType::VALIDATION) {
                    $remarkService->saveValidationRemark($documentationDTO, $assessmentStream, $currentUser);
                } else {
                    $remarkService->saveDocumentationRemark($documentationDTO, $assessmentStream, $currentUser);
                }

                return new JsonResponse(
                    [
                        'msg' => $this->translator->trans('application.assessment.submit_evidence_success', [], 'application'),
                        'data' => $this->documentationPartial($assessmentStream, $remarkService)->getContent(),
                    ],
                    Response::HTTP_OK
                );
            } elseif (!$form->isValid()) {
                $errors = $form->getErrors(true);
                $error = $errors[0]->getMessage();

                return new JsonResponse($error, Response::HTTP_BAD_REQUEST);
            }
        } catch (InsufficientAttachmentRemarkParameters|InsufficientPermissionsToSaveRemarkException $exception) {
            $error = $this->translator->trans('application.assessment.bad_attachment_values', [], 'application');
            $this->logger->info($error.$exception->getMessage(), $exception->getTrace()[0]);

            return new JsonResponse($error, Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse('', Response::HTTP_BAD_REQUEST);
    }

    public function documentationPartial(
        AssessmentStream $assessmentStream,
        RemarkService $remarkService,
        string $templatePath = 'application/documentation/partials/_documentation_body.html.twig',
        bool $renderDocumentationContent = false,
    ): Response {
        $documentationForm = $this->createForm(
            DocumentationType::class,
            new DocumentationDTO(),
            [
                'project' => $assessmentStream->getAssessment()->getProject(),
            ]
        );
        $documentationForm->get('assessmentStream')->setData($assessmentStream);

        return $this->render($templatePath, [
            'assessmentStream' => $assessmentStream,
            'documentationForm' => $documentationForm->createView(),
            'remarks' => $remarkService->findAllCurrentAndOldRemarks($assessmentStream),
            'remarkLevels' => $remarkService->findAllCurrentAndOldRemarkLevels($assessmentStream),
            'renderDocumentationContent' => $renderDocumentationContent,
        ]);
    }

    #[Route('/show/{id}/{file}', name: 'show', requirements: ['file' => '.*'])]
    #[IsGranted('PROJECT_ACCESS', 'project')]
    public function show(
        KernelInterface $kernel,
        Project $project,
        string $file,
    ): Response {
        $filePath = $this->findFilePath($project, $file, $kernel);

        $response = new Response(status: Response::HTTP_FORBIDDEN);
        if ($filePath !== null) {
            $response = $this->file($filePath, disposition: ResponseHeaderBag::DISPOSITION_INLINE);
        }

        return $response;
    }

    #[Route('/preview/{id}/{file}', name: 'preview', requirements: ['file' => '.*'])]
    #[IsGranted('PROJECT_ACCESS', 'project')]
    public function preview(
        KernelInterface $kernel,
        Project $project,
        string $file,
    ): Response {
        $filePath = $this->findFilePath($project, $file, $kernel);

        $response = new Response(status: Response::HTTP_FORBIDDEN);
        if ($filePath !== null) {
            // Get MIME Type
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $filePath);
            finfo_close($fileInfo);

            $response = $this->render('/application/documentation/preview.html.twig', [
                'mime' => $mimeType,
                'filePath' => $this->generateUrl(
                    'app_documentation_show',
                    [
                        'id' => $project->getId(),
                        'file' => $file,
                    ]
                ),
                'text' => file_get_contents($filePath, length: 200),
            ]);
        }

        return $response;
    }

    private function findFilePath(Project $project, string $file, KernelInterface $kernel): ?string
    {
        $finder = new Finder();

        try {
            $finder->files()->in($kernel->getProjectDir()."/private/projects/{$project->getId()}");
            $finder->path($file);
            $iterator = $finder->getIterator();
            $iterator->rewind();
            $foundSPLInfo = $iterator->current();
        } catch (DirectoryNotFoundException $e) {
            $foundSPLInfo = null;
        }

        $result = null;
        if ($foundSPLInfo !== null) {
            $result = $foundSPLInfo->getRealPath();
        }

        return $result;
    }

}
