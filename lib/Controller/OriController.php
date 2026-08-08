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
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\OriSerializer;
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
        'persons'       => 'person',
        'memberships'   => 'membership',
        'events'        => 'meeting',
        'agendaitems'   => 'agenda-item',
        'motions'       => 'decision',
        'amendments'    => 'decision',
        'voteevents'    => 'voting-round',
        'votes'         => 'vote',
        'reports'       => 'minutes',
        // Publish-decisions-via-opencatalogi task 5.2 — ORI harvest feed over the
        // derived, immutable PublicationPayload objects produced by the publication
        // flow. This is the harvest-able feed the deferred follow-up specifies: a
        // single ORI surface a national/OAI-PMH harvester can poll to discover all
        // published decisions/agendas/minutes without per-type endpoints. Visibility
        // is gated by the same RBAC published-predicate the payload schema declares
        // (publicatiedatum <= $now, not depublished).
        'publications'  => 'publication-payload',
    ];

    /**
     * ORI resource slugs that are now sourced from the unified `decision`
     * schema (ADR-005). Maps the resource slug to the `decisionType`
     * discriminator used to filter decisions down to that ORI resource.
     *
     * @var array<string, string>
     */
    private const DECISION_TYPE_MAP = [
        'motions'    => 'motion',
        'amendments' => 'amendment',
    ];

    /**
     * ORI resource slugs that must NOT be filtered by lifecycle.
     *
     * Person and Membership are public reference data (Popolo identity and
     * org-relationship) and carry no lifecycle field. Adding a lifecycle=published
     * filter would return zero objects.
     *
     * @var list<string>
     */
    private const NO_LIFECYCLE_GATE = [
        'persons',
        'memberships',
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
        // The publication-payload feed self-declares its ORI @type per item via the
        // payload's own `oriType` (Besluit / Vergadering / Verslag); this envelope
        // label describes the harvest collection.
        'publications'  => 'Publication',
    ];

    /**
     * ORI JSON-LD context URL.
     */
    private const ORI_CONTEXT = OriSerializer::ORI_CONTEXT;

    /**
     * The ORI resource slug backed by derived PublicationPayload objects.
     */
    private const PUBLICATIONS = OriSerializer::PUBLICATIONS_RESOURCE;

    /**
     * Constructor.
     *
     * @param IRequest           $request    The request object
     * @param IConfig            $config     The Nextcloud config service
     * @param ContainerInterface $container  The DI container
     * @param LoggerInterface    $logger     PSR-3 logger
     * @param OriSerializer      $serializer The ORI JSON-LD serializer
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IConfig $config,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly OriSerializer $serializer,
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
     * @spec openspec/specs/public-publication/spec.md
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
            $objects       = $objectService->findAll(
                [
                    'limit'   => 100,
                    'filters' => $this->buildFilters(resource: $resource, schema: $schema),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(message: 'OriController index failed', context: ['resource' => $resource, 'exception' => $e]);
            return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        $type  = self::ORI_TYPE_MAP[$resource];
        $items = $this->serializer->serializeCollection(resource: $resource, type: $type, objects: ($objects ?? []));

        return $this->jsonLdResponse(
            payload: [
                '@context' => self::ORI_CONTEXT,
                '@type'    => $type,
                'count'    => count($items),
                'items'    => $items,
            ]
        );

    }//end index()

    /**
     * Build the OpenRegister findAll() filter set for an ORI resource.
     *
     * #316: Only return published objects on public ORI endpoints — draft/closed/
     * unpublished objects must not be visible to anonymous callers.
     * ADR-005: motions/amendments are now `decision` objects discriminated by
     * `decisionType`; decisions gate public visibility via `isPublished=public`
     * (not the meeting `lifecycle=published` state used by other resources).
     * OpenRegister resolves the register/schema context from INSIDE the `filters`
     * array (ObjectService::prepareFindAllConfig) and takes a single config array
     * — not named register:/schema:/params: arguments (the latter raised
     * "Unknown named parameter $register").
     *
     * @param string $resource The ORI resource slug
     * @param string $schema   The resolved register schema slug
     *
     * @return array<string, string> The filter set
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     * @spec openspec/specs/public-publication/spec.md
     */
    private function buildFilters(string $resource, string $schema): array
    {
        $filters = [
            'register' => 'decidesk',
            'schema'   => $schema,
        ];

        if ($resource === self::PUBLICATIONS) {
            // Publish-decisions-via-opencatalogi task 5.2 — the PublicationPayload
            // feed has no `lifecycle`/`isPublished` field; its anonymous visibility
            // is governed solely by the RBAC published-predicate the schema declares
            // (public group when publicatiedatum <= $now). OR enforces that rule for
            // anonymous callers; the serializer additionally filters the window in
            // PHP (defence-in-depth so a misconfigured RBAC rule cannot leak
            // future-dated or depublished payloads through the harvest feed).
            return $filters;
        }

        $decisionType = self::DECISION_TYPE_MAP[$resource] ?? null;
        if ($decisionType !== null) {
            // ADR-005: motion/amendment ORI resources are decisions gated by
            // `isPublished=public` and discriminated by `decisionType`.
            $filters['isPublished']  = 'public';
            $filters['decisionType'] = $decisionType;

            return $filters;
        }

        if (in_array(needle: $resource, haystack: self::NO_LIFECYCLE_GATE, strict: true) === false) {
            // Person/Membership are public reference data without a lifecycle field;
            // all other resources require the published lifecycle gate (#316).
            $filters['lifecycle'] = 'published';
        }

        return $filters;

    }//end buildFilters()

    /**
     * Drop an object whose `decisionType` does not match the ORI resource.
     *
     * ADR-005 folded motion and amendment into the one `decision` schema, so
     * `/motions/{id}` and `/amendments/{id}` resolve through the same schema and
     * the schema no longer distinguishes them. index() narrows by `decisionType`
     * (DECISION_TYPE_MAP); the detail endpoint must apply the same discriminator,
     * or `/amendments/{id}` would serve a motion as an ORI Amendment.
     *
     * @param string                    $resource The ORI resource slug
     * @param array<string, mixed>|null $object   The serialized object, or null
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return array<string, mixed>|null The object, or null when it is the wrong type
     */
    private function narrowToDecisionType(string $resource, ?array $object): ?array
    {
        $decisionType = (self::DECISION_TYPE_MAP[$resource] ?? null);
        if ($object === null || $decisionType === null) {
            return $object;
        }

        if (($object['decisionType'] ?? null) !== $decisionType) {
            return null;
        }

        return $object;

    }//end narrowToDecisionType()

    /**
     * Drop an object the collection endpoint would never have returned.
     *
     * The invariant: **show() must never return an object index() would exclude.**
     * index() expresses anonymous visibility as OpenRegister filters in
     * buildFilters(); this is the same predicate evaluated against a fetched
     * object, so the item endpoint and the collection endpoint cannot drift.
     *
     * It replaces isLifecycleBlocked(), which asked the wrong question two ways:
     *
     * - It gated `motions`/`amendments` on `lifecycle === 'published'`. Those are
     *   `decision` objects, whose lifecycle enum is
     *   `draft…proposed…decided…enacted…archived…withdrawn` — `published` is not
     *   a member, so the check could never pass and `/motions/{id}` and
     *   `/amendments/{id}` returned 404 for **every** motion, including public
     *   ones. index() correctly gates decisions on `isPublished=public`.
     * - It fell **open** when the object carried no lifecycle/status field at all
     *   (`$lifecycle !== null && …`). `vote`, `voting-round`, `agenda-item` and
     *   `governance-body` declare no such property, so `/votes/{id}`,
     *   `/voteevents/{id}`, `/agendaitems/{id}` and `/organizations/{id}` served
     *   the object to any anonymous caller holding a UUID — while index() filters
     *   `lifecycle=published` and returns nothing for those same resources.
     *   Individual ballots were readable one UUID at a time.
     *
     * Nothing downstream refused either: of the 37 schemas in
     * `lib/Settings/decidesk_register.json` only PublicationPayload declares an
     * `authorization` block, and OpenRegister's PermissionHandler grants `read`
     * unconditionally for an empty block (`if (empty($authorization) === true …)
     * { … return true; }`) — its anonymous fail-closed list covers write actions
     * only (create/update/delete), never read.
     *
     * Absent gating field means withheld: the check is fail-closed, matching what
     * index()'s filter does to an object that cannot satisfy it.
     *
     * PUBLICATIONS is excluded here and gated by isPayloadLive() in show(),
     * mirroring buildFilters()'s own early return for that resource.
     *
     * @param string                    $resource The ORI resource slug
     * @param array<string, mixed>|null $object   The serialized object, or null
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return array<string, mixed>|null The object, or null when it is not publicly visible
     */
    private function narrowToPublicVisibility(string $resource, ?array $object): ?array
    {
        if ($object === null || $resource === self::PUBLICATIONS) {
            return $object;
        }

        if ((self::DECISION_TYPE_MAP[$resource] ?? null) !== null) {
            if (($object['isPublished'] ?? null) !== 'public') {
                return null;
            }

            return $object;
        }

        if (in_array(needle: $resource, haystack: self::NO_LIFECYCLE_GATE, strict: true) === true) {
            return $object;
        }

        if (($object['lifecycle'] ?? null) !== 'published') {
            return null;
        }

        return $object;

    }//end narrowToPublicVisibility()

    /**
     * Retrieve a single ORI resource by id.
     *
     * @param string $resource The ORI resource slug
     * @param string $id       The entity UUID
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return JSONResponse The JSON-LD entity or error
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
            $entity        = $objectService->find(id: $id, register: 'decidesk', schema: $schema);
            $object        = null;
            if ($entity !== null) {
                $object = $entity->jsonSerialize();
            }

            $object = $this->narrowToDecisionType(resource: $resource, object: $object);
            $object = $this->narrowToPublicVisibility(resource: $resource, object: $object);
        } catch (Throwable $e) {
            $this->logger->error(message: 'OriController show failed', context: ['resource' => $resource, 'id' => $id, 'exception' => $e]);
            return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        if ($object === null) {
            return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
        }

        $type = self::ORI_TYPE_MAP[$resource];

        if ($resource === self::PUBLICATIONS) {
            // PublicationPayload visibility is the RBAC published-predicate window
            // (publicatiedatum <= $now, not depublished). A future-dated or
            // depublished payload is not-found for anonymous callers — return 404
            // (not 403) so the endpoint never confirms an unpublished payload exists.
            if ($this->serializer->isPayloadLive(object: (array) $object) === false) {
                return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
            }

            return $this->jsonLdResponse(
                payload: $this->serializer->serializePayload(object: (array) $object, fallbackType: $type)
            );
        }//end if

        // #316's visibility gate is applied by narrowToPublicVisibility() above,
        // alongside the decisionType discriminator, so that a withheld object and
        // a wrong-type object leave through the same 404 — never confirming that
        // an unpublished object exists.
        return $this->jsonLdResponse(payload: $this->serializer->serialize(type: $type, object: (array) $object));

    }//end show()

    /**
     * Wrap a payload in a CORS-decorated JSON-LD response.
     *
     * @param array<string, mixed> $payload The JSON-LD body
     *
     * @return JSONResponse The decorated response
     *
     * @spec openspec/changes/p4-integration/tasks.md#task-11
     */
    private function jsonLdResponse(array $payload): JSONResponse
    {
        $response = new JSONResponse($payload);
        $response->addHeader(name: 'Content-Type', value: 'application/ld+json');
        $this->applyCorsHeaders(response: $response);

        return $response;

    }//end jsonLdResponse()

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

        $allowedOrigin = '*';
        if ($origin !== '') {
            $allowedOrigin = $origin;
        }

        $response->addHeader(name: 'Access-Control-Allow-Origin', value: $allowedOrigin);
        $response->addHeader(name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS');
        $response->addHeader(name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type, X-Requested-With');

    }//end applyCorsHeaders()
}//end class
