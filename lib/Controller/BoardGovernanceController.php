<?php
/**
 * Decidesk Board Governance Controller
 *
 * Secretary/admin-only endpoints for generating annual governance reports.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\GovernanceReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Thin controller for governance reporting endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
 */
class BoardGovernanceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request      The request.
     * @param GovernanceReportingService $reporting    The reporting service.
     * @param IUserSession               $userSession  The user session.
     * @param IGroupManager              $groupManager The group manager.
     * @param IAppConfig                 $appConfig    The app config.
     * @param LoggerInterface            $logger       The logger.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     */
    public function __construct(
        IRequest $request,
        private readonly GovernanceReportingService $reporting,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the caller to be a board secretary (configured group) or admin.
     *
     * @return JSONResponse|null
     */
    private function requireSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid          = $user->getUID();
        $secretaryGrp = $this->appConfig->getValueString('decidesk', 'board_secretary_group', '');
        $authorized   = $this->groupManager->isAdmin($uid);
        if ($secretaryGrp !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $secretaryGrp);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Board secretary role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireSecretary()

    /**
     * Generate an annual governance report.
     *
     * POST /api/board/governance-reports
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     */
    #[NoAdminRequired]
    public function generate(): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $boardId = (string) ($this->request->getParam('boardId', ''));
        $year    = (int) ($this->request->getParam('year', (int) date('Y')));
        if ($boardId === '') {
            return new JSONResponse(['message' => 'boardId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $report = $this->reporting->generateAnnualReport($boardId, $year);
            return new JSONResponse($report, Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Decidesk: governance report generation failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not generate report'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end generate()
}//end class
