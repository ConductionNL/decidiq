<?php

/**
 * Builds the timeline of a theme from the meetings, agenda items, decisions,
 * commitments and long-term agenda items linked to it.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * One entry per linked object: `{ date, kind, title, objectId }`, oldest first,
 * undated entries last. The published timeline keeps only entries whose object
 * is public itself, and adds where its publication is:
 *
 * - a meeting or decision: its own published `PublicationRecord` (the agenda or
 *   the decision), whose payload is the publication;
 * - an agenda item: the published agenda of its meeting;
 * - a commitment or long-term agenda item: its own `publicationDate`, which
 *   OpenRegister serves anonymously once it has passed.
 *
 * A linked object that cannot be read is left out and logged; it never fails
 * the theme.
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */
class ThemeTimelineBuilder {

	/**
	 * The theme's link properties: the schema each links to, the entry kind,
	 * the date and title properties of the linked object, in order of preference.
	 *
	 * @var array<string, array{schema: string, kind: string, date: list<string>, title: list<string>}>
	 */
	public const LINKS = [
		'meetings' => ['schema' => 'meeting', 'kind' => 'meeting', 'date' => ['scheduledDate'], 'title' => ['title']],
		'agendaItems' => ['schema' => 'agenda-item', 'kind' => 'agendaItem', 'date' => [], 'title' => ['title']],
		'decisions' => ['schema' => 'decision', 'kind' => 'decision', 'date' => ['decisionDate', 'adoptionDate'], 'title' => ['title']],
		'commitments' => ['schema' => 'governance-commitment', 'kind' => 'commitment', 'date' => ['deadline'], 'title' => ['text']],
		'plannedAgendaItems' => ['schema' => 'planned-agenda-item', 'kind' => 'plannedAgendaItem', 'date' => ['plannedPeriod'], 'title' => ['subject']],
	];

	/**
	 * The register every linked object lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param LoggerInterface        $logger        Logger.
	 * @param ThemeTimelineDates     $dates         Date helpers.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
		private readonly ThemeTimelineDates $dates = new ThemeTimelineDates(),
	) {
	}//end __construct()

	/**
	 * Every entry of the theme's timeline, oldest first, each marked published or not.
	 *
	 * @param array<string, mixed> $theme The theme object data.
	 *
	 * @return list<array{date: ?string, kind: string, title: string, objectId: string, published: bool, publication: ?string}> The entries.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
	 */
	public function entries(array $theme): array {
		$entries = [];
		foreach (self::LINKS as $property => $link) {
			foreach (self::idsOf(value: ($theme[$property] ?? [])) as $objectId) {
				$object = $this->load(schema: $link['schema'], id: $objectId);
				if ($object === null) {
					continue;
				}

				$entries[] = $this->entry(link: $link, objectId: $objectId, object: $object);
			}
		}

		usort(
			$entries,
			fn (array $first, array $second): int => $this->dates->compare(first: $first['date'], second: $second['date'])
		);

		return $entries;
	}//end entries()

	/**
	 * The entries a resident may see: only those published themselves, with their publication.
	 *
	 * @param array<string, mixed> $theme The theme object data.
	 *
	 * @return list<array{date: ?string, kind: string, title: string, objectId: string, publication: string}> The entries.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-003-a-published-theme-carries-only-published-items
	 */
	public function publishedEntries(array $theme): array {
		$published = [];
		foreach ($this->entries(theme: $theme) as $entry) {
			if ($entry['published'] === true) {
				unset($entry['published']);
				$published[] = $entry;
			}
		}

		return $published;
	}//end publishedEntries()

	/**
	 * A theme's text blocks as `{ heading, body }`, dropping anything else.
	 *
	 * @param mixed $blocks The stored blocks.
	 *
	 * @return list<array{heading: string, body: string}> The blocks.
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-003-a-published-theme-carries-only-published-items
	 */
	public function blocksOf(mixed $blocks): array {
		$clean = [];
		foreach ((array)$blocks as $block) {
			if (is_array($block) === true) {
				$clean[] = ['heading' => (string)($block['heading'] ?? ''), 'body' => (string)($block['body'] ?? '')];
			}
		}

		return $clean;
	}//end blocksOf()

	/**
	 * One entry for a linked object.
	 *
	 * @param array{schema: string, kind: string, date: list<string>, title: list<string>} $link     The link.
	 * @param string                                                                       $objectId The object id.
	 * @param array<string, mixed>                                                         $object   The object data.
	 *
	 * @return array{date: ?string, kind: string, title: string, objectId: string, published: bool, publication: ?string} The entry.
	 */
	private function entry(array $link, string $objectId, array $object): array {
		$meeting = null;
		if ($link['kind'] === 'agendaItem') {
			$meeting = self::idsOf(value: ($object['meeting'] ?? null))[0] ?? null;
		}

		$date = self::firstString(object: $object, keys: $link['date']);
		if ($meeting !== null) {
			$date = self::firstString(object: ($this->load(schema: 'meeting', id: $meeting) ?? []), keys: ['scheduledDate']);
		}

		$publication = $this->publicationOf(kind: $link['kind'], objectId: ($meeting ?? $objectId), object: $object);

		return [
			'date' => $this->dates->sortable(value: $date),
			'kind' => $link['kind'],
			'title' => (string)(self::firstString(object: $object, keys: $link['title']) ?? ''),
			'objectId' => $objectId,
			'published' => ($publication !== null),
			'publication' => $publication,
		];
	}//end entry()

	/**
	 * Where an object is published, or null when it is not public.
	 *
	 * @param string               $kind     The entry kind.
	 * @param string               $objectId The object (for an agenda item, its meeting).
	 * @param array<string, mixed> $object   The object data.
	 *
	 * @return string|null The publication: a payload id, or the object id for a self-published object.
	 */
	private function publicationOf(string $kind, string $objectId, array $object): ?string {
		if ($kind === 'commitment' || $kind === 'plannedAgendaItem') {
			if ($this->dates->isPublic(object: $object) === true) {
				return $objectId;
			}

			return null;
		}

		$sourceType = 'decision';
		if ($kind !== 'decision') {
			$sourceType = 'agenda';
		}

		return $this->publishedPayload(sourceType: $sourceType, sourceId: $objectId);
	}//end publicationOf()

	/**
	 * The payload of a published record for a source, or null when it has none.
	 *
	 * @param string $sourceType The record's source type.
	 * @param string $sourceId   The source object.
	 *
	 * @return string|null The payload id.
	 */
	private function publishedPayload(string $sourceType, string $sourceId): ?string {
		try {
			$this->objectService->setRegister(self::REGISTER);
			$this->objectService->setSchema('publication-record');
			$records = $this->objectService->findAll(
				config: ['filters' => ['sourceObject' => $sourceId, 'sourceType' => $sourceType, 'status' => 'published']],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq theme timeline: publication records could not be read', ['exception' => $e->getMessage()]);
			return null;
		}

		foreach ($records as $record) {
			$data = self::dataOf(entity: $record);
			$payload = (string)($data['payloadObject'] ?? '');
			if (($data['status'] ?? null) === 'published' && ($data['sourceObject'] ?? null) === $sourceId && $payload !== '') {
				return $payload;
			}
		}

		return null;
	}//end publishedPayload()

	/**
	 * A linked object's data, or null when it cannot be read.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object id.
	 *
	 * @return array<string, mixed>|null The data.
	 */
	private function load(string $schema, string $id): ?array {
		try {
			$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq theme timeline: a linked object could not be read',
				['schema' => $schema, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return self::dataOf(entity: $entity);
	}//end load()




	/**
	 * The first non-empty string among the keys.
	 *
	 * @param array<string, mixed> $object The object data.
	 * @param list<string>         $keys   The keys, in order of preference.
	 *
	 * @return string|null The value.
	 */
	private static function firstString(array $object, array $keys): ?string {
		foreach ($keys as $key) {
			if (is_string($object[$key] ?? null) === true && $object[$key] !== '') {
				return $object[$key];
			}
		}

		return null;
	}//end firstString()

	/**
	 * The ids a link value names: one id, a list of ids, or objects with an `id`.
	 *
	 * @param mixed $value The link value.
	 *
	 * @return list<string> The ids.
	 */
	private static function idsOf(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		if (isset($value['id']) === true) {
			$value = [$value];
		}

		$ids = [];
		foreach ($value as $item) {
			$id = $item;
			if (is_array($item) === true) {
				$id = ($item['id'] ?? $item['uuid'] ?? null);
			}

			if (is_string($id) === true && $id !== '') {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}//end idsOf()

	/**
	 * An entity's or array's data.
	 *
	 * @param mixed $entity The entity or array.
	 *
	 * @return array<string, mixed> The data.
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
