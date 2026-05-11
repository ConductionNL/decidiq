<?php
/**
 * Decidesk Decision Controller
 *
 * Controller for Decision-specific operations such as server-side publication
 * enforcement (OWASP A01 — Broken Access Control).
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for Decision-specific operations.
 *
 * Provides a dedicated publish endpoint that enforces server-side admin checks
 * and validates outcome/publication state before persisting — preventing the
 * frontend-only guard bypass described in OWASP A01:2021 / ADR-005.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
 */
class DecisionController extends Controller
{
    /**
     * Constructor for DecisionController.
     *
     * @param IRequest           $request      The HTTP request
     * @param ContainerInterface $container    DI container (lazy-loads OpenRegister services)
     * @param IUserSession       $userSession  The current user session
     * @param IGroupManager      $groupManager Group manager for admin checks
     * @param LoggerInterface    $logger       The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Publish a Decision server-side.
     *
     * POST /api/decisions/{decisionId}/publish
     *
     * Validates server-side that outcome='adopted' and isPublished='internal' before
     * persisting — preventing frontend-only guard bypass (OWASP A01 / ADR-005).
     * Requires Nextcloud admin role to match the governance-level protection on
     * the Minutes lifecycle.
     *
     * Sets isPublished to 'public' (p3-citizen-participation enum) so the decision
     * becomes visible in the citizen transparency portal and via the ORI API.
     *
     * Returns 200 with the updated Decision object on success.
     * Returns 401 when not authenticated.
     * Returns 403 when the caller is not a Nextcloud administrator.
     * Returns 404 when the Decision object is not found.
     * Returns 422 when outcome ≠ 'adopted' or isPublished is not 'internal'.
     * Returns 503 when OpenRegister is unavailable.
     *
     * @param string $decisionId UUID of the Decision object
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
     */
    public function publish(string $decisionId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['message' => 'Unauthenticated.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Only administrators may publish decisions (OWASP A01 — Broken Access Control).
        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['message' => 'Forbidden: only administrators may publish decisions.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            return new JSONResponse(
                ['message' => 'OpenRegister is not available.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');
        $entity = $objectService->find(id: $decisionId);

        if ($entity === null) {
            return new JSONResponse(
                ['message' => sprintf('Decision "%s" not found.', $decisionId)],
                Http::STATUS_NOT_FOUND
            );
        }

        $decision = $entity->getObject();

        // Server-side guard: only adopted, unpublished decisions may be published.
        if (($decision['outcome'] ?? '') !== 'adopted') {
            return new JSONResponse(
                ['message' => 'Only decisions with outcome "adopted" may be published.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        // P3-citizen-participation: isPublished is now an enum ('internal' | 'public' | 'confidential').
        // Only 'internal' decisions are eligible for publication; backward-compatible with the legacy
        // boolean form by treating true/missing-as-true as already published.
        $currentPublication = $decision['isPublished'] ?? 'internal';
        if ($currentPublication === 'public' || $currentPublication === 'confidential' || $currentPublication === true) {
            return new JSONResponse(
                ['message' => 'Decision is already published.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $updated = $decision;
        $updated['isPublished'] = 'public';
        $updated['publishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        try {
            $saved = $objectService->saveObject(
                object: $updated,
                register: 'decidesk',
                schema: 'decision',
                uuid: $decisionId
            );

            if ($saved instanceof \stdClass === true || is_array($saved) === true) {
                $result = (array) $saved;
            } else {
                $result = $updated;
            }

            $this->logger->info(
                'Decidesk: Decision published',
                ['id' => $decisionId, 'publishedBy' => $user->getUID()]
            );

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: Failed to publish Decision',
                ['id' => $decisionId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['message' => 'Failed to publish decision. Please try again.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }//end try

    }//end publish()
}//end class
