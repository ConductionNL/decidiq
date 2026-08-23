<?php

/**
 * Decidiq Public REST API Controller
 *
 * Thin pass-through over OpenRegister ObjectService that exposes
 * governance entities at `/api/v1/{resource}` per the Dutch
 * REST-API Design Rules (REQ-API-001, REQ-API-002, REQ-API-003).
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
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
 * @spec openspec/changes/p4-integration/tasks.md#task-1
 * @spec openspec/changes/p4-integration/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public REST API for Decidiq governance entities.
 *
 * Authentication is delegated to Nextcloud session tokens and (when present)
 * the built-in `oauth2` app. Scope enforcement is read against the per-schema
 * OAuthScope register entries declared by `lib/Settings/decidesk_register.json`
 * — Decidiq does not implement custom token validation (REQ-OAUTH-002).
 *
 * @spec openspec/changes/p4-integration/tasks.md#task-1
 * @spec openspec/changes/p4-integration/tasks.md#task-2
 * @spec openspec/changes/p4-integration/tasks.md#task-9
 */
class ApiController extends Controller {

	/**
	 * Map of public REST resource slug → register schema slug.
	 *
	 * @var array<string, string>
	 */
	private const RESOURCE_MAP = [
		'governance-bodies' => 'governance-body',
		'persons' => 'participant',
		'memberships' => 'participant',
		'meetings' => 'meeting',
		'motions' => 'decision',
		'voting-rounds' => 'voting-round',
		'votes' => 'vote',
		'agenda-items' => 'agenda-item',
		'minutes' => 'minutes',
		'decisions' => 'decision',
		'action-items' => 'action-item',
		'amendments' => 'decision',
	];

	/**
	 * REST resource slugs sourced from the unified `decision` schema (ADR-005).
	 *
	 * `motion` and `amendment` are no longer schemas — they are `decisionType`
	 * discriminator values on `Decision`. The slug alone can no longer select
	 * the resource, so these entries carry the discriminator the list must
	 * filter on and the detail lookup must verify. `decisions` is deliberately
	 * absent: it is the whole supertype and is not narrowed by a type.
	 *
	 * @var array<string, string>
	 */
	private const RESOURCE_DECISION_TYPES = [
		'motions' => 'motion',
		'amendments' => 'amendment',
	];

	/**
	 * Map of public REST resource slug → required OAuth scope.
	 *
	 * @var array<string, string>
	 */
	private const SCOPE_MAP = [
		'governance-bodies' => 'governance-bodies:read',
		'persons' => 'governance-bodies:read',
		'memberships' => 'governance-bodies:read',
		'meetings' => 'meetings:read',
		'agenda-items' => 'meetings:read',
		'minutes' => 'meetings:read',
		'decisions' => 'meetings:read',
		'action-items' => 'meetings:read',
		'motions' => 'motions:read',
		'amendments' => 'motions:read',
		'voting-rounds' => 'votes:read',
		'votes' => 'votes:read',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param IUserSession $userSession The user session
	 * @param IConfig $config The Nextcloud config service
	 * @param ContainerInterface $container The DI container (for ObjectService lookup)
	 * @param LoggerInterface $logger PSR-3 logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * List entities for a public REST resource.
	 *
	 * @param string $resource The REST resource slug (e.g. `meetings`)
	 *
	 * @return JSONResponse Paginated list envelope or error
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1
	 * @spec openspec/changes/p4-integration/tasks.md#task-2
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $resource): JSONResponse {
		$schema = self::RESOURCE_MAP[$resource] ?? null;
		if ($schema === null) {
			return $this->errorResponse(message: 'Unknown resource', status: Http::STATUS_NOT_FOUND);
		}

		if ($this->userSession->getUser() === null) {
			return $this->errorResponse(message: 'Unauthorized', status: Http::STATUS_UNAUTHORIZED);
		}

		$page = (int)$this->request->getParam(key: '_page', default: 1);
		$limit = (int)$this->request->getParam(key: '_limit', default: 25);
		if ($limit > 100 || $limit < 1 || $page < 1) {
			return $this->errorResponse(message: 'Invalid pagination parameters', status: Http::STATUS_BAD_REQUEST);
		}

		// ObjectService::findAll() takes a single $config array. The previous
		// named-argument form (register:/schema:/params:) threw "Unknown named
		// parameter" — and `params:` was never a real key at all — so every list
		// request fell into the catch below and returned a 500. Register/schema
		// are read from inside `filters`; limit/offset are top-level config keys.
		try {
			$objectService = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService');
			$offset = (($page - 1) * $limit);
			$filters = [
				'register' => 'decidesk',
				'schema' => $schema,
			];
			// ADR-005: /motions and /amendments are decisions narrowed by the
			// decisionType discriminator, not by their own (retired) schema.
			$decisionType = (self::RESOURCE_DECISION_TYPES[$resource] ?? null);
			if ($decisionType !== null) {
				$filters['decisionType'] = $decisionType;
			}

			$results = $objectService->findAll(
				[
					'filters' => $filters,
					'limit' => $limit,
					'offset' => $offset,
				]
			);
			$total = 0;
			if (is_array($results) === true) {
				$total = count($results);
			}

			$pages = ((int)ceil((float)$total / max(1, $limit)));
		} catch (Throwable $e) {
			$this->logger->error(message: 'ApiController index failed', context: ['resource' => $resource, 'exception' => $e]);
			return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		$response = new JSONResponse(
			[
				'total' => $total,
				'page' => $page,
				'pages' => $pages,
				'results' => ($results ?? []),
			]
		);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end index()

	/**
	 * Retrieve a single entity by id.
	 *
	 * @param string $resource The REST resource slug
	 * @param string $id The entity UUID
	 *
	 * @return JSONResponse The serialized entity or error
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-2
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $resource, string $id): JSONResponse {
		$schema = self::RESOURCE_MAP[$resource] ?? null;
		if ($schema === null) {
			return $this->errorResponse(message: 'Unknown resource', status: Http::STATUS_NOT_FOUND);
		}

		if ($this->userSession->getUser() === null) {
			return $this->errorResponse(message: 'Unauthorized', status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$objectService = $this->container->get(id: 'OCA\\OpenRegister\\Service\\ObjectService');
			$entity = $objectService->find(id: $id, register: 'decidesk', schema: $schema);
			$object = null;
			if ($entity !== null) {
				$object = $entity->jsonSerialize();
			}

			// ADR-005: /motions/{id} and /amendments/{id} resolve through the
			// unified decision schema, so the id alone no longer proves the
			// resource type — a decision of the wrong type is Not Found here,
			// exactly as it was when each type had its own schema.
			$decisionType = (self::RESOURCE_DECISION_TYPES[$resource] ?? null);
			if ($object !== null
				&& $decisionType !== null
				&& ($object['decisionType'] ?? null) !== $decisionType
			) {
				$object = null;
			}
		} catch (Throwable $e) {
			$this->logger->error(message: 'ApiController show failed', context: ['resource' => $resource, 'id' => $id, 'exception' => $e]);
			return $this->errorResponse(message: 'Internal server error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		if ($object === null) {
			return $this->errorResponse(message: 'Not found', status: Http::STATUS_NOT_FOUND);
		}

		$response = new JSONResponse($object);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end show()

	/**
	 * CORS preflight handler for `/api/v1/{resource}`.
	 *
	 * @param string $resource The REST resource slug
	 *
	 * @return JSONResponse HTTP 200 with Access-Control-* headers
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function preflight(string $resource): JSONResponse {
		unset($resource);

		$response = new JSONResponse([], Http::STATUS_OK);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end preflight()

	/**
	 * CORS preflight handler for `/api/v1/{resource}/{id}`.
	 *
	 * @param string $resource The REST resource slug
	 * @param string $id The entity UUID
	 *
	 * @return JSONResponse HTTP 200 with Access-Control-* headers
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.4
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function preflightItem(string $resource, string $id): JSONResponse {
		unset($resource, $id);

		$response = new JSONResponse([], Http::STATUS_OK);
		$this->applyCorsHeaders(response: $response);

		return $response;
	}//end preflightItem()

	/**
	 * Build a consistent JSON error envelope (REQ-API-003).
	 *
	 * @param string $message The user-facing message
	 * @param int $status The HTTP status code
	 *
	 * @return JSONResponse The decorated error response
	 *
	 * @spec openspec/changes/p4-integration/tasks.md#task-1.2
	 */
	private function errorResponse(string $message, int $status): JSONResponse {
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
	private function applyCorsHeaders(JSONResponse $response): void {
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
