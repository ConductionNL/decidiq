<?php

/**
 * Decidesk Metrics Controller
 *
 * Thin AppHost adopter: subclasses the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Controller\GenericMetricsController}, which
 * renders the manifest-declared Prometheus metrics (admin-only, ADR-006). The
 * subclass exists only to keep the `metrics#index` route target concrete
 * (gate-5 / gate-14) while the implementation lives in the generic. Decidesk
 * had no metrics endpoint before AppHost adoption; this is an additive
 * compliance upgrade serving the implicit `decidesk_info` / `decidesk_up`
 * gauges from the `observability` block of `src/manifest.json`.
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
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCP\IRequest;

/**
 * Admin-only Prometheus metrics endpoint — delegates to the AppHost generic.
 *
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
 */
class MetricsController extends GenericMetricsController
{
    /**
     * Constructor.
     *
     * @param IRequest       $request        The request object.
     * @param ManifestLoader $manifestLoader Loads the observability config.
     * @param MetricsEngine  $engine         Renders the Prometheus exposition.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        ManifestLoader $manifestLoader,
        MetricsEngine $engine,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request,
            manifestLoader: $manifestLoader,
            engine: $engine
        );

    }//end __construct()
}//end class
