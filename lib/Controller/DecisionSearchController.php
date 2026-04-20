<?php

/**
 * Decidesk Decision Search Controller
 *
 * Handles the Smart Picker search endpoint for Decisions.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Thin controller for Decision search endpoint.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
 */
class DecisionSearchController extends Controller
{
    /**
     * Constructor for DecisionSearchController.
     *
     * @param IRequest           $request   The HTTP request
     * @param ContainerInterface $container The DI container
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Search for Decisions.
     *
     * GET /api/decisions/search?q={query}
     *
     * Returns up to 20 Decisions matching the query term.
     *
     * @param string $q The search query string
     *
     * @return JSONResponse with array of decisions
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
     */
    public function search(?string $q=''): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable) {
            return new JSONResponse([]);
        }

        if ($q === null || $q === '') {
            return new JSONResponse([]);
        }

        try {
            $objectService->setRegister('decidesk');
            $objectService->setSchema('decision');

            $decisions = $objectService->findAll(
                limit: 20,
                offset: 0,
                order: [],
                filters: ['_search' => $q]
            );

            $results = [];
            foreach ($decisions as $decision) {
                $results[] = [
                    'id'           => $decision['id'] ?? '',
                    'title'        => $decision['title'] ?? '',
                    'decisionDate' => $decision['decisionDate'] ?? '',
                    'outcome'      => $decision['outcome'] ?? '',
                    'url'          => sprintf('/apps/decidesk/decisions/%s', $decision['id'] ?? ''),
                ];
            }

            return new JSONResponse($results);
        } catch (\Throwable) {
            return new JSONResponse([]);
        }//end try
    }//end search()
}//end class
