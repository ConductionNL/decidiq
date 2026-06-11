<?php

/**
 * Decidesk Projection Controller
 *
 * Public API for projection display without authentication.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\VotingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public projection controller (no authentication required).
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
 */
class ProjectionController extends Controller
{
    /**
     * Constructor for ProjectionController.
     *
     * @param IRequest      $request       The request object
     * @param VotingService $votingService The voting service
     *
     * @return void
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
     */
    public function __construct(
        IRequest $request,
        private readonly VotingService $votingService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Get public-state for a VotingRound for projection display.
     *
     * Returns aggregate vote counts and preselected option, with no individual vote
     * values or participant identities. Accessible without authentication.
     *
     * @param string $id The voting round UUID
     *
     * @return JSONResponse The public-state array or error
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function publicState(string $id): JSONResponse
    {
        $state = $this->votingService->getPublicState(votingRoundId: $id);

        if ($state === null) {
            return new JSONResponse(['message' => 'VotingRound not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($state);

    }//end publicState()
}//end class
