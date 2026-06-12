<?php
/**
 * Decidesk Multilingual Reconciliation Controller
 *
 * Phase 6 — admin/secretary-gated REST surface for the multilingual minutes
 * reconciliation queue: enqueue, list, and force-process queued entries.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MultilingualReconciliationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the multilingual reconciliation queue.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualReconciliationController extends Controller
{
    use BoardPortalControllerTrait;

    /**
     * Constructor.
     *
     * @param IRequest                          $request               HTTP request
     * @param MultilingualReconciliationService $reconciliationService Reconciliation service
     * @param IUserSession                      $userSession           User session
     * @param IGroupManager                     $groupManager          Group manager
     */
    public function __construct(
        IRequest $request,
        private readonly MultilingualReconciliationService $reconciliationService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Enqueue a board-minutes record for translation.
     *
     * Body params:
     * - minutesId (string, required)
     * - sourceLocale (string, required)
     * - targetLocales (array<string>, required)
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return JSONResponse
     */
    public function queue(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $minutesId     = (string) $this->request->getParam('minutesId', '');
        $sourceLocale  = (string) $this->request->getParam('sourceLocale', '');
        $targetLocales = (array) $this->request->getParam('targetLocales', []);

        if ($minutesId === '' || $sourceLocale === '' || $targetLocales === []) {
            return new JSONResponse(
                ['message' => "Missing required parameters: 'minutesId', 'sourceLocale', 'targetLocales'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->reconciliationService->queue($minutesId, $sourceLocale, $targetLocales);
        if (($result['success'] ?? false) === false) {
            return new JSONResponse(
                ['message' => (string) ($result['message'] ?? 'Failed to queue translation.')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse(
            [
                'results' => $result['entries'],
                'total'   => count($result['entries']),
            ],
            Http::STATUS_CREATED
        );

    }//end queue()

    /**
     * Return queue status (counts per status + listing).
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return JSONResponse
     */
    public function status(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $limit  = (int) $this->request->getParam('limit', 50);
        $result = $this->reconciliationService->status($limit);
        return new JSONResponse(
            [
                'summary' => $result['summary'],
                'results' => $result['entries'],
            ]
        );

    }//end status()

    /**
     * Force-process up to N queue entries (operational endpoint).
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return JSONResponse
     */
    public function process(): JSONResponse
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $maxEntries = (int) $this->request->getParam('maxEntries', 10);
        $result     = $this->reconciliationService->processQueue($maxEntries);
        $status     = Http::STATUS_UNPROCESSABLE_ENTITY;
        if (($result['success'] ?? false) === true) {
            $status = Http::STATUS_OK;
        }

        return new JSONResponse(
            [
                'processed' => $result['processed'] ?? 0,
                'completed' => $result['completed'] ?? 0,
                'failed'    => $result['failed'] ?? 0,
                'message'   => $result['message'] ?? '',
            ],
            $status
        );

    }//end process()

    /**
     * Reject the caller with 401/403 when they are not a Nextcloud admin.
     *
     * @return JSONResponse|null
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['message' => 'Administrator role required.'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireAdmin()
}//end class
