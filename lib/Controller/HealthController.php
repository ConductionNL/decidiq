<?php

/**
 * Decidesk Health Controller
 *
 * Public health check endpoint for reverse-proxy URL verification and
 * OpenRegister connectivity status (REQ-API-004, REQ-PROXY-001).
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
 * @spec openspec/changes/p4-integration/tasks.md#task-1.5
 * @spec openspec/changes/p4-integration/tasks.md#task-10.1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Public health-check controller.
 *
 * Returns the effective base URL (so admins can verify reverse-proxy
 * configuration), the Decidesk app version, and OpenRegister connectivity.
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-10
 */
class HealthController extends Controller
{
    /**
     * Constructor for HealthController.
     *
     * @param IRequest           $request   The request object
     * @param IConfig            $config    The Nextcloud config service
     * @param ContainerInterface $container The DI container (for lazy OR lookup)
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IConfig $config,
        private readonly ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Return the integration health summary.
     *
     * Always returns HTTP 200. If OpenRegister is unreachable, the body reports
     * `status: degraded` so reverse-proxy probes still succeed (REQ-API-004).
     *
     * @return JSONResponse HTTP 200 with status/baseUrl/version/openregister
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-1.5
     * @spec openspec/changes/p4-integration/tasks.md#task-10.1
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function status(): JSONResponse
    {
        $baseUrl  = $this->config->getSystemValueString(key: 'overwrite.cli.url', default: '');
        $version  = $this->config->getAppValue(appName: Application::APP_ID, key: 'installed_version', default: '0.0.0');
        $orStatus = 'unavailable';

        try {
            $objectService = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService');
            if ($objectService !== null) {
                $orStatus = 'connected';
            }
        } catch (Throwable $e) {
            $orStatus = 'unavailable';
        }

        $statusValue = 'degraded';
        if ($orStatus === 'connected') {
            $statusValue = 'ok';
        }

        $payload = [
            'status'       => $statusValue,
            'baseUrl'      => $baseUrl,
            'version'      => $version,
            'openregister' => $orStatus,
        ];

        $response = new JSONResponse($payload, Http::STATUS_OK);
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end status()

    /**
     * CORS preflight for the health endpoint.
     *
     * @return JSONResponse HTTP 200 with Access-Control-* headers
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-1.4
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
     * Apply CORS headers using the configured proxy origin when available.
     *
     * @param JSONResponse $response The response to decorate
     *
     * @return void
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-1.4
     * @spec openspec/changes/p4-integration/tasks.md#task-10.4
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
