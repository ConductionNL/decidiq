<?php

/**
 * Decidesk Decision Public Controller
 *
 * Handles public unauthenticated access to published decisions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Controller for public Decision access (no authentication required).
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
 */
class DecisionPublicController extends Controller
{
    /**
     * Constructor for DecisionPublicController.
     *
     * @param IRequest           $request   The HTTP request
     * @param ContainerInterface $container The DI container
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get a published Decision as public endpoint.
     *
     * GET /api/decisions/{id}/public
     *
     * Returns only: title, text, decisionDate, outcome, legalBasis
     * Returns 403 if Decision is not published.
     * Returns 404 if Decision not found.
     *
     * @param string $id UUID of the Decision object
     *
     * @return JSONResponse with published Decision fields or error
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
     */
    public function getPublicDecision(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable) {
            return new JSONResponse(
                ['message' => 'Service unavailable'],
                503
            );
        }

        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');
        $decisionEntity = $objectService->find($id);

        if ($decisionEntity === null) {
            return new JSONResponse(
                ['message' => 'Decision not found'],
                404
            );
        }

        $decision = $decisionEntity->getObject();

        // Check if published
        if (!($decision['isPublished'] ?? false)) {
            return new JSONResponse(
                ['message' => 'Decision is not publicly available'],
                403
            );
        }

        // Return only whitelisted fields
        return new JSONResponse(
                [
                    'title'        => $decision['title'] ?? '',
                    'text'         => $decision['text'] ?? '',
                    'decisionDate' => $decision['decisionDate'] ?? '',
                    'outcome'      => $decision['outcome'] ?? '',
                    'legalBasis'   => $decision['legalBasis'] ?? '',
                ]
                );
    }//end getPublicDecision()

    /**
     * Handle CORS OPTIONS request for public Decision endpoint.
     *
     * OPTIONS /api/decisions/{id}/public
     *
     * @param string $id UUID of the Decision object
     *
     * @return JSONResponse with CORS headers
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
     */
    public function optionsPublicDecision(string $id): JSONResponse
    {
        return new JSONResponse([]);
    }//end optionsPublicDecision()
}//end class
