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
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
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
            // #316: Only return published objects on public ORI endpoints — draft/closed/unpublished
            // objects must not be visible to anonymous callers.
            // ADR-005: motions/amendments are now `decision` objects discriminated by
            // `decisionType`; decisions gate public visibility via `isPublished=public`
            // (not the meeting `lifecycle=published` state used by other resources).
            // OpenRegister resolves the register/schema context from INSIDE the
            // `filters` array (ObjectService::prepareFindAllConfig) and takes a
            // single config array — not named register:/schema:/params: arguments
            // (the latter raised "Unknown named parameter $register").
            $filters = [
                'register' => 'decidesk',
                'schema'   => $schema,
            ];

            $decisionType = self::DECISION_TYPE_MAP[$resource] ?? null;
            if ($resource === 'publications') {
                // Publish-decisions-via-opencatalogi task 5.2 — the PublicationPayload
                // feed has no `lifecycle`/`isPublished` field; its anonymous visibility
                // is governed solely by the RBAC published-predicate the schema declares
                // (public group when publicatiedatum <= $now). OR enforces that rule for
                // anonymous callers; the controller additionally filters the window in
                // PHP below (defence-in-depth so a misconfigured RBAC rule cannot leak
                // future-dated or depublished payloads through the harvest feed).
                // No server-side filter field is added; the RBAC rule + the PHP
                // window check below are the gate.
                unset($decisionType);
            } else if ($decisionType !== null) {
                // ADR-005: motion/amendment ORI resources are decisions gated by
                // `isPublished=public` and discriminated by `decisionType`.
                $filters['isPublished']  = 'public';
                $filters['decisionType'] = $decisionType;
            } else if (in_array(needle: $resource, haystack: self::NO_LIFECYCLE_GATE, strict: true) === false) {
                // Person/Membership are public reference data without a lifecycle field;
                // all other resources require the published lifecycle gate (#316).
                $filters['lifecycle'] = 'published';
            }//end if

            $objects = $objectService->findAll(['limit' => 100, 'filters' => $filters]);
        } catch (Throwable $e) {
            $this->logger->error(message: 'OriController index failed', context: ['resource' => $resource, 'exception' => $e]);
            return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        $type  = self::ORI_TYPE_MAP[$resource];
        $items = [];
        foreach (($objects ?? []) as $object) {
            // FindAll() yields ObjectEntity instances; jsonSerialize() gives the
            // flat property map (title, lifecycle, motionType, …). A raw (array)
            // cast mangles the entity's protected props, leaving the serializer
            // with only @self/id — so normalise to the serialised array first.
            if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
                $objectArray = $object->jsonSerialize();
            } else {
                $objectArray = (array) $object;
            }

            if ($resource === 'publications') {
                // Defence-in-depth published-predicate window: only emit payloads
                // that are live RIGHT NOW (publicatiedatum in the past and either no
                // depublicatiedatum or one still in the future). A future-dated or
                // already-depublished payload must never appear in the harvest feed.
                if ($this->isPayloadLive(object: $objectArray) === false) {
                    continue;
                }

                // Each payload self-declares its ORI @type via `oriType`
                // (Besluit / Vergadering / Verslag); fall back to the collection type.
                $itemType = ($objectArray['oriType'] ?? null);
                if (is_string($itemType) === false || $itemType === '') {
                    $itemType = $type;
                }

                $items[] = $this->serializeOri(type: $itemType, object: $objectArray);
                continue;
            }

            $items[] = $this->serializeOri(type: $type, object: $objectArray);
        }//end foreach

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
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
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
        } catch (Throwable $e) {
            $this->logger->error(message: 'OriController show failed', context: ['resource' => $resource, 'id' => $id, 'exception' => $e]);
            return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($object === null) {
            return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
        }

        if ($resource === 'publications') {
            // PublicationPayload visibility is the RBAC published-predicate window
            // (publicatiedatum <= $now, not depublished). A future-dated or
            // depublished payload is not-found for anonymous callers — return 404
            // (not 403) so the endpoint never confirms an unpublished payload exists.
            if ($this->isPayloadLive(object: (array) $object) === false) {
                return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
            }

            $itemType = ($object['oriType'] ?? null);
            if (is_string($itemType) === false || $itemType === '') {
                $itemType = self::ORI_TYPE_MAP[$resource];
            }

            $payload  = $this->serializeOri(type: $itemType, object: (array) $object);
            $response = new JSONResponse($payload);
            $response->addHeader(name: 'Content-Type', value: 'application/ld+json');
            $this->applyCorsHeaders(response: $response);

            return $response;
        }//end if

        // #316: Treat non-published objects as not-found for anonymous callers.
        // Return 404 (not 403) to avoid confirming the object exists.
        // M2: only enforce the lifecycle gate when the object actually carries a
        // lifecycle/status field; schemas without it (votes, persons, etc.) pass through.
        $lifecycle = $object['lifecycle'] ?? $object['status'] ?? null;
        if ($lifecycle !== null && $lifecycle !== 'published') {
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
        // L1: prefer the entity's own uuid field over @self.id (which is an
        // internal OpenRegister reference and must not be surfaced publicly).
        $id = ($object['uuid'] ?? ($object['id'] ?? ($self['id'] ?? null)));

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

        // C5: only expose email for Organisation-typed resources to prevent
        // accidental leakage of private contact details from other types.
        // popolo-decision-makers (owner-confirmed): Person email IS exposed on the
        // public ORI /persons output — elected/officeholder contact is open-government
        // transparency data, consistent with ORI/Popolo Person serialization.
        if (($type === 'Organization' || $type === 'Person') && isset($object['email']) === true) {
            $payload['email'] = $object['email'];
        }

        // Publish-decisions-via-opencatalogi task 5.2 — PublicationPayload-specific
        // ORI fields. PublicationPayloads are derived, allow-list objects (no UID,
        // no voter identities, no contact details by construction), so every field
        // present here is safe to surface on the harvest feed. The payload self-
        // declares its $type via oriType (Besluit / Vergadering / Verslag).
        $oriType = ($object['oriType'] ?? null);
        if (is_string($oriType) === true && $oriType !== '') {
            $payload['oriType'] = $oriType;

            if (isset($object['schemaOrgType']) === true) {
                $payload['schemaOrgType'] = $object['schemaOrgType'];
            }

            if (isset($object['bodyName']) === true) {
                $payload['body'] = $object['bodyName'];
            }

            if (isset($object['outcome']) === true) {
                $payload['outcome'] = $object['outcome'];
            }

            if (isset($object['decisionDate']) === true) {
                $payload['decision_date'] = $object['decisionDate'];
            }

            if (isset($object['legalBasis']) === true) {
                $payload['legal_basis'] = $object['legalBasis'];
            }

            if (isset($object['voteTotals']) === true) {
                $payload['vote_totals'] = $object['voteTotals'];
            }

            if (isset($object['meetingDate']) === true) {
                $payload['meeting_date'] = $object['meetingDate'];
            }

            if (isset($object['agendaItems']) === true) {
                $payload['agenda_items'] = $object['agendaItems'];
            }

            if (isset($object['content']) === true) {
                $payload['content'] = $object['content'];
            }

            if (isset($object['attendance']) === true) {
                $payload['attendance'] = $object['attendance'];
            }

            if (isset($object['publicatiedatum']) === true) {
                $payload['published_at'] = $object['publicatiedatum'];
            }
        }//end if

        return $payload;

    }//end serializeOri()

    /**
     * Evaluate the RBAC published-predicate window for a PublicationPayload.
     *
     * A payload is "live" — and therefore visible on the anonymous ORI harvest
     * feed — when its `publicatiedatum` is set and not in the future, AND its
     * `depublicatiedatum` is either unset or still in the future. This mirrors
     * the public-group `authorization.read` rule the PublicationPayload schema
     * declares (`publicatiedatum <= $now`); the controller re-checks it in PHP as
     * defence-in-depth so a misconfigured RBAC rule cannot leak future-dated or
     * already-depublished payloads through the harvest surface.
     *
     * @param array<string, mixed> $object The serialized PublicationPayload
     *
     * @return bool True when the payload is currently publicly visible
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    private function isPayloadLive(array $object): bool
    {
        $publishedRaw = ($object['publicatiedatum'] ?? null);
        if (is_string($publishedRaw) === false || $publishedRaw === '') {
            return false;
        }

        $now       = new \DateTimeImmutable();
        $published = $this->parseDate(value: $publishedRaw);
        if ($published === null || $published > $now) {
            return false;
        }

        $depublishedRaw = ($object['depublicatiedatum'] ?? null);
        if (is_string($depublishedRaw) === true && $depublishedRaw !== '') {
            $depublished = $this->parseDate(value: $depublishedRaw);
            if ($depublished !== null && $depublished <= $now) {
                return false;
            }
        }

        return true;

    }//end isPayloadLive()

    /**
     * Parse an ISO-8601 / ATOM date string into a DateTimeImmutable.
     *
     * @param string $value The date-time string
     *
     * @return \DateTimeImmutable|null The parsed value, or null when unparseable
     */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }

    }//end parseDate()

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
