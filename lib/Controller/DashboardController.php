<?php

/**
 * Decidesk Dashboard Controller
 *
 * SPA host: renders the SPA from `templates/index.php` and serves the Vue
 * history-mode catch-all. Behaviourally identical to the OpenRegister AppHost
 * `GenericDashboardController`, but implemented locally and depending on
 * nothing outside decidesk and OCP.
 *
 * ⚠️ This class MUST NOT `extends` — nor name in any resolved position — a
 * class from another app. Nextcloud's router `ReflectionClass()`es every file
 * in `lib/Controller/` while MATCHING a route, so an unresolvable parent makes
 * EVERY route in decidesk return HTTP 500 — including routes with no
 * OpenRegister involvement at all. `extends` is resolved by the AUTOLOADER,
 * not the DI container, so no amount of lazy registration can rescue it, and
 * the 10 lines below are cheaper than a whole-app outage. See decidesk#377.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * SPA host for decidesk.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class DashboardController extends Controller
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

    /**
     * Render the main SPA page from `templates/index.php`.
     *
     * @return TemplateResponse The rendered decidesk index template.
     *
     * @spec openspec/specs/apphost-adoption/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse
    {
        return $this->renderIndex();

    }//end page()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @return TemplateResponse The rendered decidesk index template.
     *
     * @spec openspec/specs/apphost-adoption/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(): TemplateResponse
    {
        return $this->page();

    }//end catchAll()

    /**
     * Build the `index` TemplateResponse.
     *
     * @return TemplateResponse The rendered decidesk index template.
     */
    protected function renderIndex(): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index');

    }//end renderIndex()
}//end class
