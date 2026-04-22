<?php

/**
 * Decidesk Decision Analytics Controller
 *
 * API endpoint for decision analytics and KPI data.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\ICache;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin controller for decision analytics API endpoint.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
 */
class DecisionAnalyticsController extends Controller
{
    /**
     * Construct the DecisionAnalyticsController.
     *
     * @param string             $appName     Application name
     * @param IRequest           $request     HTTP request
     * @param ContainerInterface $container   DI container
     * @param ICache             $cache       Cache service
     * @param IUserSession       $userSession User session
     * @param LoggerInterface    $logger      Logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly ICache $cache,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get decision analytics data.
     *
     * GET /api/decisions/analytics?governanceBodyId={id}
     *
     * @param string $governanceBodyId Optional governance body filter
     *
     * @return JSONResponse with analytics data
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     */
    public function analytics(string $governanceBodyId=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->requireGovernanceBodyAccess(bodyId: $governanceBodyId, uid: $user->getUID());
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        if ($governanceBodyId !== '') {
            $cacheKey = "decidesk_analytics_$governanceBodyId";
        } else {
            $cacheKey = 'decidesk_analytics_all';
        }

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return new JSONResponse(json_decode($cached, true));
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('decidesk');
            $objectService->setSchema('Decision');

            $params = [];
            if (empty($governanceBodyId) === false) {
                $params['governanceBodyId'] = $governanceBodyId;
            }

            $decisions = $objectService->findAll(params: $params);

            $decisionsPerMonth   = $this->groupDecisionsByMonth(decisions: $decisions);
            $outcomeDistribution = $this->groupDecisionsByOutcome(decisions: $decisions);
            $pendingApprovals    = $this->countPendingApprovals(decisions: $decisions);
            $overdueActionItems  = $this->countOverdueActionItems();

            $response = [
                'decisionsPerMonth'   => $decisionsPerMonth,
                'outcomeDistribution' => $outcomeDistribution,
                'pendingApprovals'    => $pendingApprovals,
                'overdueActionItems'  => $overdueActionItems,
            ];

            $this->cache->set($cacheKey, json_encode($response), 900);

            $jsonResponse = new JSONResponse($response);
            $jsonResponse->addHeader('Cache-Control', 'max-age=900');
            return $jsonResponse;
        } catch (\Throwable $e) {
            $this->logger->error("Analytics error: {$e->getMessage()}");
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end analytics()

    /**
     * Group decisions by month.
     *
     * @param array $decisions Array of decision objects
     *
     * @return array Monthly decision counts
     */
    private function groupDecisionsByMonth(array $decisions): array
    {
        $byMonth = [];
        foreach ($decisions as $decision) {
            $date            = $decision['decisionDate'] ?? date(\DateTime::ATOM);
            $month           = substr($date, 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
        }

        ksort($byMonth);
        return array_map(
            fn ($month, $count) => ['month' => $month, 'count' => $count],
            array_keys($byMonth),
            array_values($byMonth)
        );
    }//end groupDecisionsByMonth()

    /**
     * Group decisions by outcome.
     *
     * @param array $decisions Array of decision objects
     *
     * @return array Outcome distribution
     */
    private function groupDecisionsByOutcome(array $decisions): array
    {
        $byOutcome = [];
        foreach ($decisions as $decision) {
            $outcome = $decision['outcome'] ?? 'unknown';
            $byOutcome[$outcome] = ($byOutcome[$outcome] ?? 0) + 1;
        }

        return array_map(
            fn ($outcome, $count) => ['outcome' => $outcome, 'count' => $count],
            array_keys($byOutcome),
            array_values($byOutcome)
        );
    }//end groupDecisionsByOutcome()

    /**
     * Count pending approvals.
     *
     * @param array $decisions Array of decision objects
     *
     * @return int Count of decisions in review
     */
    private function countPendingApprovals(array $decisions): int
    {
        return count(
                array_filter(
            $decisions,
            fn ($d) => in_array($d['lifecycle'] ?? '', ['legal-review', 'committee-review'], true)
        )
                );
    }//end countPendingApprovals()

    /**
     * Count overdue action items.
     *
     * @return int Count of overdue action items
     */
    private function countOverdueActionItems(): int
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('decidesk');
            $objectService->setSchema('ActionItem');

            $actionItems = $objectService->findAll(params: ['taskStatus' => 'overdue']);
            return count($actionItems);
        } catch (\Throwable) {
            return 0;
        }
    }//end countOverdueActionItems()

    /**
     * Verify the authenticated user has access to the requested governance body.
     *
     * When a specific body is requested, the user must appear as a Person linked
     * to that governance body (verified via OpenRegister). Aggregate analytics
     * (empty bodyId) are available to all authenticated users.
     *
     * @param string $bodyId Governance body UUID, or '' for aggregate.
     * @param string $uid    Nextcloud UID of the authenticated user.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the user is not a member of the requested body.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-5
     */
    private function requireGovernanceBodyAccess(string $bodyId, string $uid): void
    {
        if (empty($bodyId) === true) {
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->setRegister('decidesk');
            $objectService->setSchema('Person');

            $members = $objectService->findAll(
                params: ['governanceBodyId' => $bodyId, 'nextcloudUserId' => $uid]
            );
            if (empty($members) === true) {
                throw new OCSForbiddenException(
                    'Access denied: not a member of the requested governance body'
                );
            }
        } catch (OCSForbiddenException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new OCSForbiddenException('Authorization service unavailable');
        }
    }//end requireGovernanceBodyAccess()
}//end class
