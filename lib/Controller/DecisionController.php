<?php

/**
 * Decidesk Decision Controller
 *
 * Controller for decision-specific API actions.
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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Controller for decision actions requiring server-side authorization.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
 */
class DecisionController extends Controller
{
    /**
     * Constructor for the DecisionController.
     *
     * @param IRequest           $request      The request object
     * @param IGroupManager      $groupManager The group manager
     * @param IUserSession       $userSession  The user session
     * @param ContainerInterface $container    The DI container
     * @param IAppConfig         $appConfig    The app config
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
     */
    public function __construct(
        IRequest $request,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Publish a decision.
     *
     * Sets isPublished=true and records publishedAt. Any authenticated user may
     * call this endpoint; admin role is enforced manually via IGroupManager::isAdmin().
     * The NoAdminRequired annotation is intentional — it prevents Nextcloud from
     * performing its own framework-level admin check so that the explicit guard
     * below is the single source of truth for this role gate.
     *
     * @param string $decisionId The UUID of the Decision object
     *
     * @return JSONResponse Updated object or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
     */
    public function publish(string $decisionId): JSONResponse
    {
        // NoAdminRequired intentional: admin is enforced below via IGroupManager::isAdmin()
        // rather than @AdminRequired so that the same guard handles the role check
        // and the response body in a single place.
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Insufficient permissions to publish a decision'], Http::STATUS_FORBIDDEN);
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', 'decidesk');

            $decision = $objectService->findObject(register: $register, schema: 'decision', id: $decisionId);
            if (empty($decision) === true) {
                return new JSONResponse(['message' => 'Decision object not found'], Http::STATUS_NOT_FOUND);
            }

            if (($decision['outcome'] ?? '') !== 'adopted') {
                return new JSONResponse(['message' => 'Only adopted decisions can be published'], Http::STATUS_UNPROCESSABLE_ENTITY);
            }

            $decision['isPublished'] = true;
            $decision['publishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            $updated = $objectService->saveObject(register: $register, schema: 'decision', object: $decision);
            return new JSONResponse($updated);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Publication failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end publish()
}//end class
