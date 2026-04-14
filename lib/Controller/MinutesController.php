<?php

/**
 * Decidesk Minutes Controller
 *
 * Controller for Minutes-specific operations such as draft generation
 * and server-side lifecycle transition enforcement.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Controller for Minutes-specific operations.
 *
 * Provides endpoints for generating a structured Dutch draft from
 * a linked meeting's data and for enforcing server-side lifecycle
 * transitions with signatory attribution.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesController extends Controller
{

    /**
     * Allowed sequential lifecycle transitions: current => next.
     */
    private const LIFECYCLE_TRANSITIONS = [
        'draft'    => 'review',
        'review'   => 'approved',
        'approved' => 'signed',
        'signed'   => 'published',
    ];

    /**
     * Constructor for MinutesController.
     *
     * @param IRequest                 $request                  The HTTP request
     * @param MinutesGenerationService $minutesGenerationService The generation service
     * @param ContainerInterface       $container                DI container (lazy-loads ObjectService)
     * @param IUserSession             $userSession              The current user session
     * @param string|null              $userId                   The current user ID (null = unauthenticated)
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private ?string $userId,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a Dutch draft text for the given Minutes object.
     *
     * POST /api/minutes/{minutesId}/generate-draft
     *
     * Returns { "preview": "<generated text>" } on success.
     * Returns 401 when the request is not authenticated.
     * Returns 404 when the Minutes object is not found.
     * Returns 503 when OpenRegister is unavailable.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function generateDraft(string $minutesId): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $preview = $this->minutesGenerationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

    }//end generateDraft()

    /**
     * Transition the lifecycle state of a Minutes object server-side.
     *
     * POST /api/minutes/{minutesId}/transition
     *
     * Validates that the requested lifecycle value is the immediate next step in
     * the fixed sequence (draft → review → approved → signed → published).
     * Populates signedBy from the authenticated server-side user session for the
     * "approved" and "signed" transitions so that forged client-side attribution
     * is impossible.
     *
     * Returns 200 with the updated Minutes object on success.
     * Returns 401 when the request is not authenticated.
     * Returns 404 when the Minutes object is not found.
     * Returns 422 when the requested transition is not the valid next step.
     * Returns 503 when OpenRegister is unavailable.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function transition(string $minutesId): JSONResponse
    {
        if ($this->userId === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $newLifecycle = $this->request->getParam('lifecycle');
        if ($newLifecycle === null || is_string($newLifecycle) === false || $newLifecycle === '') {
            return new JSONResponse(
                ['message' => 'Missing or invalid lifecycle parameter.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Service temporarily unavailable.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        // Fetch the current Minutes object.
        try {
            $minutesEntity = $objectService->find(
                id: $minutesId,
                register: 'decidesk',
                schema: 'minutes'
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Service temporarily unavailable.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        if ($minutesEntity === null) {
            return new JSONResponse(
                ['message' => 'Minutes not found.'],
                Http::STATUS_NOT_FOUND
            );
        }

        $minutes          = $minutesEntity->getObject();
        $currentLifecycle = $minutes['lifecycle'] ?? 'draft';
        $allowedNext      = self::LIFECYCLE_TRANSITIONS[$currentLifecycle] ?? null;

        if ($allowedNext !== $newLifecycle) {
            return new JSONResponse(
                [
                    'message' => sprintf(
                        'Invalid transition: "%s" → "%s". Expected next step: "%s".',
                        $currentLifecycle,
                        $newLifecycle,
                        $allowedNext ?? '(terminal)'
                    ),
                ],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $minutes['lifecycle'] = $newLifecycle;

        // Populate signedBy from the server-side user session — never from the client.
        if ($newLifecycle === 'approved' || $newLifecycle === 'signed') {
            $user = $this->userSession->getUser();
            if ($user !== null) {
                $displayName = $user->getDisplayName();
            } else {
                $displayName = $this->userId;
            }

            $signedBy = $minutes['signedBy'] ?? [];
            if (in_array($displayName, $signedBy, true) === false) {
                $signedBy[] = $displayName;
            }

            $minutes['signedBy'] = $signedBy;
        }

        if ($newLifecycle === 'approved') {
            $minutes['approvedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }

        // Save the updated Minutes object via ObjectService.
        try {
            $objectService->setRegister('decidesk');
            $objectService->setSchema('minutes');
            $saved = $objectService->saveObject(
                object: $minutes,
                register: 'decidesk',
                schema: 'minutes',
                uuid: $minutesId
            );

            if (is_array($saved) === true) {
                $responseData = $saved;
            } else {
                $responseData = $minutes;
            }

            return new JSONResponse($responseData);
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'Service temporarily unavailable.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }//end try

    }//end transition()
}//end class
