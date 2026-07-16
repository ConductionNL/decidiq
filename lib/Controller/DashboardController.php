<?php

/**
 * Decidesk Dashboard Controller
 *
 * Thin AppHost adopter: subclasses the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Controller\GenericDashboardController}, which
 * renders the SPA from `templates/index.php` and serves the Vue history-mode
 * catch-all. No decidesk-specific behaviour — the subclass exists only to keep
 * the `dashboard#page` / `dashboard#catchAll` route targets concrete (gate-5 /
 * gate-14) while the implementation lives in the generic.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2.
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
 * @spec openspec/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCP\IRequest;

/**
 * SPA host for decidesk — delegates entirely to the AppHost generic.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class DashboardController extends GenericDashboardController
{
    /**
     * Constructor.
     *
     * @param IRequest $request The request object.
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()
}//end class
