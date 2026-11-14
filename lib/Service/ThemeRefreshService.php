<?php

/**
 * Decidiq Theme Refresh Service
 *
 * Keeps the published page of an active public theme current: for every
 * theme whose status is active and that is marked public, it compares the
 * timeline of its current published version with the timeline its linked
 * items give now, and publishes a new version (rectify: the new version
 * replaces the old one) when they differ. A concluded theme, a theme not
 * marked public and a theme that was never published are left alone
 * (REQ-THP-004).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Republishes active public themes whose timeline changed.
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current
 */
class ThemeRefreshService {
	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Who the refresh is recorded as.
	 *
	 * @var string
	 */
	public const ACTOR = 'system';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface      $objectService OpenRegister's object service.
	 * @param ThemeTimelineBuilder        $timeline      The theme timeline.
	 * @param PublicationServiceInterface $publications  The publication path.
	 * @param LoggerInterface             $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ThemeTimelineBuilder $timeline,
		private readonly PublicationServiceInterface $publications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Republish every active public theme whose timeline changed since its last version.
	 *
	 * @return array{republished: list<string>, unchanged: list<string>, failed: list<string>} Theme ids by outcome.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current
	 */
	public function refresh(): array {
		$outcome = ['republished' => [], 'unchanged' => [], 'failed' => []];
		foreach ($this->rows(schema: 'governance-theme', filters: ['status' => 'active']) as $theme) {
			$themeId = (string)($theme['id'] ?? ($theme['@self']['id'] ?? ''));
			if ($themeId === '' || ($theme['isPublic'] ?? false) !== true) {
				continue;
			}

			$record = $this->currentRecord(themeId: $themeId);
			if ($record === null) {
				continue;
			}

			$outcome[$this->refreshOne(theme: $theme, themeId: $themeId, record: $record)][] = $themeId;
		}

		return $outcome;
	}//end refresh()

	/**
	 * Compare one theme's published timeline with its timeline now, and republish when they differ.
	 *
	 * @param array<string,mixed> $theme   The theme.
	 * @param string              $themeId Its id.
	 * @param array<string,mixed> $record  Its current publication record.
	 *
	 * @return string republished, unchanged or failed.
	 */
	private function refreshOne(array $theme, string $themeId, array $record): string {
		try {
			$payload = $this->one(schema: 'publication-payload', id: (string)($record['payloadObject'] ?? ''));
			$now     = $this->timeline->publishedEntries(theme: $theme);
			if ($payload !== null && self::sameTimeline(published: ($payload['timeline'] ?? []), now: $now) === true) {
				return 'unchanged';
			}

			$this->publications->rectify(
				recordId: (string)($record['id'] ?? ($record['@self']['id'] ?? '')),
				actorId: self::ACTOR,
				reason: 'The theme timeline changed; the nightly refresh published a new version.'
			);

			return 'republished';
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq theme refresh: a theme could not be republished', ['themeId' => $themeId, 'exception' => $e->getMessage()]);

			return 'failed';
		}
	}//end refreshOne()

	/**
	 * Whether two timelines hold the same entries in the same order.
	 *
	 * @param mixed                            $published The published payload's timeline.
	 * @param list<array<string,mixed>>        $now       The timeline now.
	 *
	 * @return bool
	 */
	private static function sameTimeline(mixed $published, array $now): bool {
		if (is_array($published) === false) {
			return false;
		}

		$normal = static function (array $entries): array {
			$out = [];
			foreach ($entries as $entry) {
				$entry = (array)$entry;
				ksort($entry);
				$out[] = $entry;
			}

			return $out;
		};

		return $normal(array_values($published)) === $normal($now);
	}//end sameTimeline()

	/**
	 * The theme's current publication: the published record with the highest version.
	 *
	 * @param string $themeId The theme.
	 *
	 * @return array<string,mixed>|null
	 */
	private function currentRecord(string $themeId): ?array {
		$current = null;
		foreach ($this->rows(
			schema: 'publication-record',
			filters: ['sourceType' => 'theme', 'sourceObject' => $themeId, 'status' => 'published']
		) as $record) {
			if ($current === null || (int)($record['payloadVersion'] ?? 0) > (int)($current['payloadVersion'] ?? 0)) {
				$current = $record;
			}
		}

		return $current;
	}//end currentRecord()

	/**
	 * Rows of a schema matching the filters, as arrays.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		try {
			$this->objectService->setRegister(self::REGISTER);
			$this->objectService->setSchema($schema);
			$rows = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => 10000]);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq theme refresh: ' . $schema . ' could not be read', ['exception' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$data = self::dataOf(entity: $row);
			if ($data !== []) {
				$out[] = $data;
			}
		}

		return $out;
	}//end rows()

	/**
	 * One object by id, null when absent.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		$data   = self::dataOf(entity: $entity);
		if ($data === []) {
			return null;
		}

		return $data;
	}//end one()

	/**
	 * An entity or row as an array.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string,mixed>
	 */
	private static function dataOf(mixed $entity): array {
		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$entity = $entity->jsonSerialize();
		}

		if (is_array($entity) === false) {
			return [];
		}

		return $entity;
	}//end dataOf()
}//end class
