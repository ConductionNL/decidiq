<?php

/**
 * Decidesk Health Controller
 *
 * Thin AppHost adopter: subclasses the OpenRegister AppHost
 * {@see \OCA\OpenRegister\AppHost\Controller\GenericHealthController} and
 * reshapes the engine result into the published REQ-API-004 response body
 * (`{status, baseUrl, version, openregister}`). Health-check execution,
 * the always-200 status-code policy, CORS, and the public auth posture all
 * come from the engine (declared in the `observability` block of
 * `src/manifest.json`); only the legacy body shape is owned here.
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
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.5
 * @spec openspec/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Public, declarative health endpoint — REQ-API-004 body shape.
 *
 * The bespoke OR DI-container probe is replaced by the engine's `orAvailable`
 * check (manifest `observability.health.checks`); this controller maps the
 * engine result back onto the historical reverse-proxy-verification body.
 * It holds its own references to the engine collaborators (the generic keeps
 * its copies private), injected via the constructor closure in Application.php.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class HealthController extends GenericHealthController
{
    /**
     * Constructor.
     *
     * @param IRequest            $request        The request object.
     * @param IConfig             $config         The Nextcloud config service (baseUrl).
     * @param ManifestLoader      $manifestLoader Loads the observability config.
     * @param HealthCheckExecutor $executor       Runs the declarative checks.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IConfig $config,
        private readonly ManifestLoader $manifestLoader,
        private readonly HealthCheckExecutor $executor,
    ) {
        parent::__construct(
            appName: Application::APP_ID,
            request: $request,
            manifestLoader: $manifestLoader,
            executor: $executor
        );

    }//end __construct()

    /**
     * GET /api/health and the legacy /api/v1/health — REQ-API-004 body.
     *
     * Runs the engine checks (orAvailable + always-200 policy + CORS, from the
     * manifest), then reshapes the result into the published body that
     * reverse-proxy probes verify: the effective base URL, the app version, and
     * a flattened `openregister: connected|unavailable` status.
     *
     * @return JSONResponse HTTP 200 with status/baseUrl/version/openregister.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.5
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $appId    = $this->appName;
        $manifest = $this->manifestLoader->load(appId: $appId);
        $result   = $this->executor->execute(manifest: $manifest);

        $baseUrl = $this->config->getSystemValueString(key: 'overwrite.cli.url', default: '');
        $version = $this->manifestLoader->appVersion(appId: $appId);

        // Flatten the engine's `checks.openregister` (ok|failed[: ...]) back to
        // the historical `connected|unavailable` value.
        $orCheck      = (string) ($result->checks['openregister'] ?? 'failed');
        $openregister = 'unavailable';
        if (str_starts_with($orCheck, 'ok') === true) {
            $openregister = 'connected';
        }

        $response = new JSONResponse(
            [
                'status'       => $result->status,
                'baseUrl'      => $baseUrl,
                'version'      => $version,
                'openregister' => $openregister,
            ],
            $result->httpStatusCode
        );

        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end index()

    /**
     * Legacy alias target for `GET /api/v1/health`. Delegates to {@see index()}.
     *
     * Kept so existing reverse-proxy probes on the historical URL keep working
     * during the deprecation window.
     *
     * @return JSONResponse HTTP 200 with status/baseUrl/version/openregister.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function status(): JSONResponse
    {
        return $this->index();

    }//end status()

    /**
     * CORS preflight for the legacy `OPTIONS /api/v1/health` route.
     *
     * @return JSONResponse HTTP 200 with Access-Control-* headers.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function statusOptions(): JSONResponse
    {
        $response = new JSONResponse([], Http::STATUS_OK);
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end statusOptions()

    /**
     * Apply CORS headers using the configured proxy origin when available
     * (REQ-API-004 parity with the pre-adoption controller).
     *
     * @param JSONResponse $response The response to decorate.
     *
     * @return void
     */
    private function applyCorsHeaders(JSONResponse $response): void
    {
        $origin = $this->config->getSystemValueString(key: 'overwrite.cli.url', default: '*');

        $allowedOrigin = '*';
        if ($origin !== '') {
            $allowedOrigin = $origin;
        }

        $response->addHeader(name: 'Access-Control-Allow-Origin', value: $allowedOrigin);
        $response->addHeader(name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS');
        $response->addHeader(name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type, X-Requested-With');

    }//end applyCorsHeaders()
}//end class
