<?php

/**
 * Decidesk ORI API 1.4 Controller
 *
 * ORI-compliant JSON-LD endpoints at `/api/ori/v1/{resource}` for council
 * information transparency (REQ-ORI-001..004).
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
 * @spec openspec/changes/p4-integration/tasks.md#task-11
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ORI API 1.4 compatibility controller.
 *
 * Serializes Decidesk register objects per the VNG ORI specification. ORI is
 * versioned independently from the general v1 REST API (REQ-ORI-001) so its
 * URL and response envelope live in this dedicated controller.
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-11
 */
class OriController extends Controller
{

    /**
     * Map of ORI resource slug → register schema slug.
     *
     * @var array<string, string>
     */
    private const RESOURCE_MAP = [
        'organizations' => 'governance-body',
        'persons'       => 'participant',
        'memberships'   => 'participant',
        'events'        => 'meeting',
        'agendaitems'   => 'agenda-item',
        'motions'       => 'motion',
        'amendments'    => 'amendment',
        'voteevents'    => 'voting-round',
        'votes'         => 'vote',
        'reports'       => 'minutes',
    ];

    /**
     * Map of ORI resource slug → ORI/Akoma Ntoso @type label.
     *
     * @var array<string, string>
     */
    private const ORI_TYPE_MAP = [
        'organizations' => 'Organization',
        'persons'       => 'Person',
        'memberships'   => 'Membership',
        'events'        => 'Event',
        'agendaitems'   => 'AgendaItem',
        'motions'       => 'Motion',
        'amendments'    => 'Amendment',
        'voteevents'    => 'VoteEvent',
        'votes'         => 'Vote',
        'reports'       => 'Report',
    ];

    /**
     * ORI JSON-LD context URL.
     */
    private const ORI_CONTEXT = 'https://argu.co/ns/core';


    /**
     * Constructor.
     *
     * @param IRequest           $request   The request object
     * @param IConfig            $config    The Nextcloud config service
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    PSR-3 logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IConfig $config,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()


    /**
     * List ORI resources.
     *
     * @param string $resource The ORI resource slug (e.g. `events`)
     *
     * @return JSONResponse JSON-LD list envelope or error
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $resource): JSONResponse
    {
        $schema = self::RESOURCE_MAP[$resource] ?? null;
        if ($schema === null) {
            return $this->errorResponse(message: 'Unknown resource', status: Http::STATUS_NOT_FOUND);
        }

        try {
            $objectService = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService');
            $objects       = $objectService->findAll(register: 'decidesk', schema: $schema, params: ['limit' => 100]);
        } catch (Throwable $e) {
            $this->logger->error(message: 'OriController index failed', context: ['resource' => $resource, 'exception' => $e]);
            return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $type    = self::ORI_TYPE_MAP[$resource];
        $items   = [];
        foreach (($objects ?? []) as $object) {
            $items[] = $this->serializeOri(type: $type, object: (array) $object);
        }

        $payload = [
            '@context' => self::ORI_CONTEXT,
            '@type'    => $type,
            'count'    => count($items),
            'items'    => $items,
        ];

        $response = new JSONResponse($payload);
        $response->addHeader(name: 'Content-Type', value: 'application/ld+json');
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end index()


    /**
     * Retrieve a single ORI resource by id.
     *
     * @param string $resource The ORI resource slug
     * @param string $id       The entity UUID
     *
     * @return JSONResponse The JSON-LD entity or error
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function show(string $resource, string $id): JSONResponse
    {
        $schema = self::RESOURCE_MAP[$resource] ?? null;
        if ($schema === null) {
            return $this->errorResponse(message: 'Unknown resource', status: Http::STATUS_NOT_FOUND);
        }

        try {
            $objectService = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService');
            $object        = $objectService->findObject(register: 'decidesk', schema: $schema, id: $id);
        } catch (Throwable $e) {
            $this->logger->error(message: 'OriController show failed', context: ['resource' => $resource, 'id' => $id, 'exception' => $e]);
            return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($object === null) {
            return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
        }

        $type    = self::ORI_TYPE_MAP[$resource];
        $payload = $this->serializeOri(type: $type, object: (array) $object);

        $response = new JSONResponse($payload);
        $response->addHeader(name: 'Content-Type', value: 'application/ld+json');
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end show()


    /**
     * CORS preflight handler for the list endpoint.
     *
     * @param string $resource The ORI resource slug
     *
     * @return JSONResponse HTTP 200 with Access-Control-* headers
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-1.4
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function preflight(string $resource): JSONResponse
    {
        unset($resource);

        $response = new JSONResponse([], Http::STATUS_OK);
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end preflight()


    /**
     * CORS preflight handler for the item endpoint.
     *
     * @param string $resource The ORI resource slug
     * @param string $id       The entity UUID
     *
     * @return JSONResponse HTTP 200 with Access-Control-* headers
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-1.4
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function preflightItem(string $resource, string $id): JSONResponse
    {
        unset($resource, $id);

        $response = new JSONResponse([], Http::STATUS_OK);
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end preflightItem()


    /**
     * Serialize a Decidesk register object as a JSON-LD ORI resource.
     *
     * Field renaming follows ORI 1.4 conventions:
     *   `title` → `name`, `scheduledDate` → `start_date`, `endDate` → `end_date`,
     *   `lifecycle` → `status`, `meetingType`/`bodyType` → `classification`.
     *
     * @param string               $type   The ORI @type label
     * @param array<string, mixed> $object The OpenRegister object payload
     *
     * @return array<string, mixed> The serialized ORI resource
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    private function serializeOri(string $type, array $object): array
    {
        $self = ($object['@self'] ?? []);
        $id   = ($self['id'] ?? ($object['id'] ?? ($object['uuid'] ?? null)));

        $payload = [
            '@context' => self::ORI_CONTEXT,
            '@type'    => $type,
            'id'       => $id,
        ];

        if (isset($object['title']) === true) {
            $payload['name'] = $object['title'];
        } else if (isset($object['name']) === true) {
            $payload['name'] = $object['name'];
        }

        if (isset($object['scheduledDate']) === true) {
            $payload['start_date'] = $object['scheduledDate'];
        }

        if (isset($object['endDate']) === true) {
            $payload['end_date'] = $object['endDate'];
        }

        if (isset($object['location']) === true) {
            $payload['location'] = $object['location'];
        }

        if (isset($object['lifecycle']) === true) {
            $payload['status'] = $object['lifecycle'];
        }

        if (isset($object['meetingType']) === true) {
            $payload['classification'] = $object['meetingType'];
        } else if (isset($object['bodyType']) === true) {
            $payload['classification'] = $object['bodyType'];
        } else if (isset($object['motionType']) === true) {
            $payload['classification'] = $object['motionType'];
        }

        if (isset($object['text']) === true) {
            $payload['text'] = $object['text'];
        }

        if (isset($object['email']) === true) {
            $payload['email'] = $object['email'];
        }

        return $payload;

    }//end serializeOri()


    /**
     * Build a consistent JSON error envelope (REQ-API-003).
     *
     * @param string $message The user-facing message
     * @param int    $status  The HTTP status code
     *
     * @return JSONResponse The decorated error response
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-1.2
     */
    private function errorResponse(string $message, int $status): JSONResponse
    {
        $response = new JSONResponse(['message' => $message, 'code' => $status], $status);
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end errorResponse()


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

        $response->addHeader(name: 'Access-Control-Allow-Origin', value: ($origin === '' ? '*' : $origin));
        $response->addHeader(name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS');
        $response->addHeader(name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type, X-Requested-With');

    }//end applyCorsHeaders()


}//end class
