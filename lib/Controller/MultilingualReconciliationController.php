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
    use RequiresOrAdmin;

    use GovernanceControllerTrait;

    /**
     * Constructor.
     *
     * @param IRequest                          $request      HTTP request
     * @param MultilingualReconciliationService $reconciler   Reconciliation service
     * @param IUserSession                      $userSession  User session
     * @param IGroupManager                     $groupManager Group manager
     */
    public function __construct(
        IRequest $request,
        private readonly MultilingualReconciliationService $reconciler,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Enqueue a minutes record for translation.
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

        $result = $this->reconciler->queue($minutesId, $sourceLocale, $targetLocales);
        if ($result['success'] === false) {
            return new JSONResponse(
                ['message' => $result['message']],
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
        $result = $this->reconciler->status($limit);
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
        $result     = $this->reconciler->processQueue($maxEntries);
        $status     = Http::STATUS_UNPROCESSABLE_ENTITY;
        if ($result['success'] === true) {
            $status = Http::STATUS_OK;
        }

        return new JSONResponse(
            [
                'processed' => $result['processed'],
                'completed' => $result['completed'],
                'failed'    => $result['failed'],
                'message'   => $result['message'],
            ],
            $status
        );

    }//end process()

    // Admin guard requireAdmin() comes from the shared RequiresOrAdmin trait
    // (consume-or-rbac-authorization, REQ-RBAC-004).
}//end class
