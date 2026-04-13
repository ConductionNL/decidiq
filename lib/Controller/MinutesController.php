<?php

/**
 * Minutes Controller
 *
 * Thin controller for minutes-specific API actions.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use OCP\IAppConfig;

/**
 * Thin controller for minutes draft generation.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
 */
class MinutesController extends Controller
{
    /**
     * Constructor for the MinutesController.
     *
     * @param IRequest                 $request                  The request object
     * @param MinutesGenerationService $minutesGenerationService The generation service
     * @param IGroupManager            $groupManager             The group manager
     * @param IUserSession             $userSession              The user session
     * @param ContainerInterface       $container                The DI container
     * @param IAppConfig               $appConfig                The app config
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function __construct(
        IRequest $request,
        private readonly MinutesGenerationService $minutesGenerationService,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a draft minutes document from the linked meeting data.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse JSON with preview text or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function generateDraft(string $minutesId): JSONResponse
    {
        try {
            $preview = $this->minutesGenerationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }
    }//end generateDraft()

    /**
     * Perform a lifecycle transition on a Minutes object.
     *
     * Governance-critical transitions (approved, signed, published) require
     * admin role. The draft → review transition is available to any authenticated user.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse Updated object or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2
     */
    public function transition(string $minutesId): JSONResponse
    {
        $lifecycle = $this->request->getParam('lifecycle');

        if (empty($lifecycle) === true) {
            return new JSONResponse(['message' => 'Missing lifecycle parameter'], Http::STATUS_BAD_REQUEST);
        }

        // Validate against the complete set of allowed lifecycle values.
        $allowedLifecycles = ['draft', 'review', 'approved', 'signed', 'published'];
        if (in_array($lifecycle, $allowedLifecycles, true) === false) {
            return new JSONResponse(['message' => 'Invalid lifecycle value'], Http::STATUS_BAD_REQUEST);
        }

        // Governance-critical transitions require admin role.
        $restrictedTransitions = ['approved', 'signed', 'published'];
        if (in_array($lifecycle, $restrictedTransitions, true) === true) {
            $user = $this->userSession->getUser();
            if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
                return new JSONResponse(['message' => 'Insufficient permissions for this lifecycle transition'], Http::STATUS_FORBIDDEN);
            }
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', 'decidesk');

            $minutes = $objectService->findObject(register: $register, schema: 'minutes', id: $minutesId);
            if (empty($minutes) === true) {
                return new JSONResponse(['message' => 'Minutes object not found'], Http::STATUS_NOT_FOUND);
            }

            $minutes['lifecycle'] = $lifecycle;

            if ($lifecycle === 'approved') {
                $minutes['approvedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $minutes['version']    = (int) ($minutes['version'] ?? 1) + 1;
                // Spec task 5.3: append the approving user's display name to signedBy.
                $currentUser = $this->userSession->getUser();
                if ($currentUser !== null) {
                    $signedBy = $minutes['signedBy'] ?? [];
                    if (is_array($signedBy) === false) {
                        $signedBy = [];
                    }

                    $signedBy[]          = $currentUser->getDisplayName();
                    $minutes['signedBy'] = $signedBy;
                }
            }

            $updated = $objectService->saveObject(register: $register, schema: 'minutes', object: $minutes);
            return new JSONResponse($updated);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Transition failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end transition()
}//end class
