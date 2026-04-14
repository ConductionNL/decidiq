<?php

/**
 * Decidesk Minutes Controller
 *
 * Controller for Minutes-specific operations such as draft generation
 * and server-side lifecycle transitions.
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
 * Provides endpoints for draft generation and server-side lifecycle transitions.
 * Lifecycle transitions are enforced here — only sequential forward transitions
 * are accepted, and signedBy is populated from the authenticated session user.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesController extends Controller
{

    /**
     * Allowed forward lifecycle transitions.
     * Key = current lifecycle, value = next allowed lifecycle.
     *
     * @var array<string,string>
     */
    private const TRANSITIONS = [
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
     * @param IUserSession             $userSession              The user session
     * @param ContainerInterface       $container                The DI container (lazy-loads OpenRegister)
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private MinutesGenerationService $minutesGenerationService,
        private IUserSession $userSession,
        private ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a Dutch draft text for the given Minutes object.
     *
     * POST /api/minutes/{minutesId}/generate-draft
     *
     * Returns { "preview": "<generated text>" } on success.
     * Returns 401 when the request is unauthenticated.
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
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
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
     * Perform a server-side lifecycle transition on a Minutes object.
     *
     * POST /api/minutes/{minutesId}/transition
     * Body: { "to": "review"|"approved"|"signed"|"published" }
     *
     * Only sequential forward transitions are accepted. On "approved" and "signed"
     * transitions the authenticated user's display name is appended to signedBy
     * server-side, preventing client forgery of signatory attribution.
     *
     * Returns 200 with the updated object on success.
     * Returns 400 when the requested transition is not valid.
     * Returns 401 when the request is unauthenticated.
     * Returns 404 when the Minutes object is not found.
     * Returns 503 when OpenRegister is unavailable.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5
     */
    public function transitionLifecycle(string $minutesId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $to = $this->request->getParam('to');
        if (!is_string($to) || $to === '') {
            return new JSONResponse(['message' => "Parameter 'to' is required."], Http::STATUS_BAD_REQUEST);
        }

        try {
            $objectService = $this->getObjectService();
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => 'Service temporarily unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        // Fetch the current Minutes object.
        try {
            $entity  = $objectService->find($minutesId, [], 'decidesk', 'minutes');
            $minutes = $entity->getObject();
        } catch (\Throwable) {
            return new JSONResponse(['message' => 'Minutes not found'], Http::STATUS_NOT_FOUND);
        }

        $currentLifecycle = $minutes['lifecycle'] ?? 'draft';
        $allowed          = self::TRANSITIONS[$currentLifecycle] ?? null;

        if ($allowed !== $to) {
            return new JSONResponse(
                ['message' => "Transition from '{$currentLifecycle}' to '{$to}' is not allowed."],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Populate audit fields server-side so the client cannot forge them.
        $displayName = $user->getDisplayName();

        if ($to === 'approved') {
            $minutes['approvedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $signers               = is_array($minutes['signedBy'] ?? null) ? $minutes['signedBy'] : [];
            if ($displayName !== '' && !in_array($displayName, $signers, true)) {
                $signers[] = $displayName;
            }

            $minutes['signedBy'] = $signers;
        }

        if ($to === 'signed') {
            $signers = is_array($minutes['signedBy'] ?? null) ? $minutes['signedBy'] : [];
            if ($displayName !== '' && !in_array($displayName, $signers, true)) {
                $signers[] = $displayName;
            }

            $minutes['signedBy'] = $signers;
        }

        $minutes['lifecycle'] = $to;

        try {
            $objectService->setRegister('decidesk');
            $objectService->setSchema('minutes');
            $updated = $objectService->saveObject($minutes, 'decidesk', 'minutes', $minutesId);
            return new JSONResponse($updated instanceof \ArrayAccess || is_array($updated) ? $updated : $minutes);
        } catch (\Throwable) {
            return new JSONResponse(['message' => 'Service temporarily unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

    }//end transitionLifecycle()

    /**
     * Resolve the OpenRegister ObjectService from the DI container.
     *
     * @throws \RuntimeException When OpenRegister is not available
     *
     * @return object
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'OpenRegister ObjectService is not available.',
                0,
                $e
            );
        }

    }//end getObjectService()
}//end class
