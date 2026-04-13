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
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
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
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Thin controller for minutes draft generation.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesController extends Controller
{
    /**
     * Constructor for the MinutesController.
     *
     * @param IRequest                 $request                  The request object
     * @param MinutesGenerationService $minutesGenerationService The generation service
     * @param ContainerInterface       $container                The DI container
     * @param IAppConfig               $appConfig                The app config
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private readonly MinutesGenerationService $minutesGenerationService,
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate a draft minutes document from the linked meeting data.
     *
     * Authorization: row-level access is enforced by OpenRegister's ObjectService::findObject().
     * If the calling user does not have read permission on the Minutes object, findObject() will
     * return empty and this endpoint returns 404. Broad read access for all authenticated members
     * is intentional per the Decidesk governance model — minutes content is not private once a
     * meeting has taken place.
     *
     * @param string $minutesId The UUID of the Minutes object
     *
     * @return JSONResponse JSON with preview text or error
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
     */
    public function generateDraft(string $minutesId): JSONResponse
    {
        try {
            // Verify the Minutes object exists and is accessible before delegating to the service.
            // OpenRegister enforces row-level ACL — findObject() returns empty when access is denied.
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', 'decidesk');
            $minutes       = $objectService->findObject(register: $register, schema: 'minutes', id: $minutesId);
            if (empty($minutes) === true) {
                return new JSONResponse(['message' => 'Minutes object not found'], Http::STATUS_NOT_FOUND);
            }

            $preview = $this->minutesGenerationService->generateDraft($minutesId);
            return new JSONResponse(['preview' => $preview]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Failed to generate draft'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end generateDraft()
}//end class
