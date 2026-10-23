<?php

/**
 * Decidiq Universal Search Provider
 *
 * Exposes decisions, meetings, and resolutions to Nextcloud's unified
 * search (OCP\Search\IProvider).
 *
 * @category Search
 * @package  OCA\Decidiq\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Search;

use OCA\Decidiq\AppInfo\Application;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * Unified-search provider over the Decidiq OpenRegister objects.
 *
 * ## Per-user visibility (OWASP A01 / ADR-005)
 *
 * The search is delegated to OpenRegister's ObjectService, whose findAll()
 * resolves the SESSION user and applies object-level RBAC — a user only
 * ever receives results they may read. No additional filtering happens
 * here, and none is needed: there is no way to query other users' objects
 * through this provider.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class DecidiqSearchProvider implements IProvider {

	/**
	 * Searched schema slugs mapped to their frontend route segment.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMAS = [
		'decision' => 'decisions',
		'meeting' => 'meetings',
		'minutes' => 'minutes',
	];

	/**
	 * The date each schema shows on its subline.
	 *
	 * @var array<string, string>
	 */
	private const SUBLINE_DATE = [
		'decision' => 'decisionDate',
		'meeting' => 'scheduledDate',
		'minutes' => 'approvedAt',
	];

	/**
	 * Maximum results fetched per schema per query.
	 *
	 * @var int
	 */
	private const LIMIT_PER_SCHEMA = 5;

	/**
	 * Constructor for DecidiqSearchProvider.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083: injected, typed)
	 * @param IURLGenerator $urlGenerator URL generator for deep links + icon
	 * @param IL10N $l10n Translations for the provider name and sublines
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Provider id.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string
	 */
	public function getId(): string {
		return Application::APP_ID;
	}//end getId()

	/**
	 * Translated provider name shown as the unified-search section header.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->l10n->t('Decidiq governance');
	}//end getName()

	/**
	 * Section order: top inside the app, late in the global list.
	 *
	 * @param string $route The current route
	 * @param array<array-key, mixed> $routeParameters The current route parameters
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return int|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $routeParameters is mandated by
	 * the OCP\Search\IProvider::getOrder() signature; ordering depends only on the
	 * route name, so the parameter cannot be removed.
	 */
	public function getOrder(string $route, array $routeParameters): ?int {
		if (str_starts_with($route, Application::APP_ID . '.') === true) {
			return -1;
		}

		return 25;
	}//end getOrder()

	/**
	 * Search decisions, meetings, and resolutions for the given term.
	 *
	 * Per-object visibility is OpenRegister RBAC (session-user scoped inside
	 * ObjectService::findAll) — see the class docblock.
	 *
	 * @param IUser $user The user running the search (session user, used by OR RBAC)
	 * @param ISearchQuery $query The unified-search query
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return SearchResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $user is mandated by the
	 * OCP\Search\IProvider::search() signature. Per-object visibility is enforced
	 * by OpenRegister RBAC from the session user inside ObjectService::findAll(),
	 * so this method never reads the parameter directly.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Search\SearchResult is a `final`
	 * class with a PRIVATE constructor; SearchResult::complete() /
	 * ::paginated() are the only ways to build the value object that
	 * OCP\Search\IProvider::search() is required to return. Nextcloud exposes no
	 * injectable factory for it, so the static call cannot be replaced without
	 * breaking the interface contract — it is not hidden coupling that a seam
	 * could remove. Verified against nextcloud lib/public/Search/SearchResult.php.
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if ($term === '') {
			return SearchResult::complete($this->getName(), []);
		}

		$entries = [];
		try {
			foreach (self::SCHEMAS as $schema => $segment) {
				// Register and schema go in 'filters': ObjectService::findAll()
				// sets its context from there only, and top-level keys were
				// silently ignored, so no schema was ever searched. The call
				// keeps OpenRegister's RBAC on (its default), so a searcher only
				// gets objects they may read.
				$rows = $this->objectService->findAll(
					[
						'filters' => ['register' => 'decidiq', 'schema' => $schema],
						'search' => $term,
						'limit' => self::LIMIT_PER_SCHEMA,
					]
				);

				foreach ($rows as $entity) {
					$row = $entity;
					if (is_object($entity) === true) {
						$row = (array)$entity->jsonSerialize();
					}

					if (is_array($row) === false) {
						continue;
					}

					$entry = $this->buildEntry(row: $row, schema: $schema, segment: $segment);
					if ($entry !== null) {
						$entries[] = $entry;
					}
				}
			}//end foreach
		} catch (\Throwable $e) {
			// Fail soft: a broken register must not take down unified search.
			$this->logger->error(
				'Decidiq: unified search failed',
				['term' => $term, 'exception' => $e->getMessage()]
			);
		}//end try

		return SearchResult::complete($this->getName(), $entries);
	}//end search()

	/**
	 * Build one search result entry from an OpenRegister object row.
	 *
	 * @param array<string, mixed> $row Object payload
	 * @param string $schema Schema slug the row came from
	 * @param string $segment Frontend route segment for the deep link
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return SearchResultEntry|null Null when the row carries no id/title
	 */
	private function buildEntry(array $row, string $schema, string $segment): ?SearchResultEntry {
		$uuid = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		$title = (string)($row['title'] ?? '');
		if ($uuid === '' || $title === '') {
			return null;
		}

		$sublineParts = [$this->schemaLabel(schema: $schema)];
		$status = (string)($row['lifecycle'] ?? ($row['status'] ?? ($row['outcome'] ?? '')));
		if ($status !== '') {
			$sublineParts[] = $status;
		}

		// The date part only (Y-m-d) of the schema's own date, when it has one.
		$date = (string)($row[self::SUBLINE_DATE[$schema] ?? ''] ?? '');
		if ($date !== '') {
			$sublineParts[] = substr($date, 0, 10);
		}

		return new SearchResultEntry(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
			$title,
			implode(' · ', $sublineParts),
			// The app router is a history router (src/main.js createWebHistory),
			// so the detail page is a path, not a `#/` hash it would ignore.
			rtrim($this->urlGenerator->linkToRoute('decidiq.dashboard.page'), '/') . '/' . $segment . '/' . rawurlencode($uuid),
			'icon-decidiq',
			true
		);

	}//end buildEntry()

	/**
	 * Translated label for a schema slug, rendered in the result subline.
	 *
	 * @param string $schema Schema slug
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return string
	 */
	private function schemaLabel(string $schema): string {
		return match ($schema) {
			'decision' => $this->l10n->t('Decision'),
			'meeting' => $this->l10n->t('Meeting'),
			'minutes' => $this->l10n->t('Minutes'),
			default => $schema,
		};

	}//end schemaLabel()
}//end class
