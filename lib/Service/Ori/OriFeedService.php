<?php

/**
 * The public ORI feeds: meetings, agenda items and documents as RSS.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Ori
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Ori;

use OCA\Decidiq\Kernel\Contracts\OriFeedServiceInterface;
use OCA\Decidiq\Service\Feed\RssDocument;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Serves only what is public: a meeting marked public, the agenda items of a
 * public meeting, and the documents attached to those agenda items.
 *
 * The feeds are read by anonymous callers, so the objects are read without a
 * user's scope and the visibility rule is applied here, before anything is
 * rendered. Each item's description is the object in the ORI wire shape.
 *
 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
 */
class OriFeedService implements OriFeedServiceInterface {

	/**
	 * Confidentiality restriction states that keep their target out of public view.
	 *
	 * @var list<string>
	 */
	private const RESTRICTION_ACTIVE = ['imposed', 'ratified'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads decidiq's objects.
	 * @param OriExportMapper        $mapper        Renders them in the ORI shape.
	 * @param RssDocument            $rss           Renders the feed.
	 *
	 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly OriExportMapper $mapper,
		private readonly RssDocument $rss,
	) {
	}//end __construct()

	/**
	 * The public meetings.
	 *
	 * @return string RSS XML.
	 *
	 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
	 */
	public function meetings(): string {
		$items = [];
		foreach ($this->publicMeetings() as $meeting) {
			$wire = $this->mapper->meeting(meeting: $meeting);
			$items[] = [
				'title' => (string)($meeting['title'] ?? ''),
				'guid' => (string)$meeting['id'],
				'pubDate' => ($meeting['scheduledDate'] ?? null),
				'category' => ($wire['status'] ?? null),
				'description' => self::json(record: $wire),
			];
		}

		return $this->rss->render(title: 'Meetings', description: 'Public meetings', items: $items);
	}//end meetings()

	/**
	 * The agenda items of the public meetings.
	 *
	 * @return string RSS XML.
	 *
	 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
	 */
	public function agendaItems(): string {
		$items = [];
		foreach ($this->publicAgendaItems() as $item) {
			$items[] = [
				'title' => (string)($item['title'] ?? ''),
				'guid' => (string)$item['id'],
				'description' => self::json(record: $this->mapper->agendaItem(item: $item)),
			];
		}

		return $this->rss->render(title: 'Agenda items', description: 'Agenda items of public meetings', items: $items);
	}//end agendaItems()

	/**
	 * The documents attached to the agenda items of the public meetings.
	 *
	 * @return string RSS XML.
	 *
	 * @spec openspec/changes/archive/2026-11-25-ori-adoption/specs/ori-adoption/spec.md
	 */
	public function documents(): string {
		$attached = [];
		foreach ($this->publicAgendaItems() as $item) {
			foreach ((array)($item['documents'] ?? []) as $uuid) {
				$attached[(string)$uuid] = true;
			}
		}

		$withheld = $this->withheldDocuments();

		$items = [];
		foreach ($this->objects(schema: 'digital-document') as $document) {
			if (isset($attached[(string)$document['id']]) === false || $this->isWithheld(document: $document, withheld: $withheld) === true) {
				continue;
			}

			$wire = $this->mapper->document(document: $document);
			$items[] = [
				'title' => (string)($document['name'] ?? ''),
				'guid' => (string)$document['id'],
				'category' => ($wire['type'] ?? null),
				'description' => self::json(record: $wire),
			];
		}

		return $this->rss->render(title: 'Documents', description: 'Documents of public meetings', items: $items);
	}//end documents()

	/**
	 * The documents an imposed or ratified confidentiality restriction keeps out:
	 * its own target, and the papers of a restricted agenda item.
	 *
	 * Read on every render, so a restriction imposed a minute ago already holds.
	 * A read that fails throws, and the controller then answers 500 with an
	 * empty body: the feed fails closed, it never lists a document unchecked.
	 *
	 * @return array<string, true> The withheld document ids.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 */
	private function withheldDocuments(): array {
		$documents = [];
		$items     = [];
		foreach ($this->objects(schema: 'confidentiality-restriction') as $restriction) {
			if (in_array((string)($restriction['lifecycle'] ?? ''), self::RESTRICTION_ACTIVE, true) === false) {
				continue;
			}

			$document = (string)($restriction['targetDocument'] ?? '');
			if ($document !== '') {
				$documents[$document] = true;
			}

			$item = (string)($restriction['targetAgendaItem'] ?? '');
			if ($item !== '') {
				$items[$item] = true;
			}
		}

		if ($items === []) {
			return $documents;
		}

		foreach ($this->objects(schema: 'agenda-item') as $item) {
			if (isset($items[(string)$item['id']]) === false) {
				continue;
			}

			foreach ((array)($item['documents'] ?? []) as $uuid) {
				$documents[(string)$uuid] = true;
			}
		}

		return $documents;
	}//end withheldDocuments()

	/**
	 * Whether a document stays out of the public feed: under a restriction, or under an embargo not yet released.
	 *
	 * @param array<string,mixed> $document The stored DigitalDocument.
	 * @param array<string, true> $withheld The document ids the restrictions keep out.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 */
	private function isWithheld(array $document, array $withheld): bool {
		if (isset($withheld[(string)$document['id']]) === true) {
			return true;
		}

		// An embargoed document is for its audience only until the release job clears the flag.
		return in_array(($document['embargoActive'] ?? false), [true, 'true', 1, '1'], true);
	}//end isWithheld()

	/**
	 * Meetings marked public.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function publicMeetings(): array {
		return array_values(
			array_filter(
				$this->objects(schema: 'meeting'),
				static fn (array $meeting): bool => ($meeting['isPublic'] ?? false) === true
			)
		);
	}//end publicMeetings()

	/**
	 * Agenda items of a meeting marked public.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function publicAgendaItems(): array {
		$meetings = array_fill_keys(array_column($this->publicMeetings(), 'id'), true);

		return array_values(
			array_filter(
				$this->objects(schema: 'agenda-item'),
				static fn (array $item): bool => isset($meetings[(string)($item['meeting'] ?? '')]) === true
			)
		);
	}//end publicAgendaItems()

	/**
	 * Every object of one decidiq schema, as arrays with `id`.
	 *
	 * @param string $schema The schema's slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function objects(string $schema): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => OriImportService::TARGET_REGISTER, 'schema' => $schema]],
			_rbac: false,
			_multitenancy: false
		);

		$objects = [];
		foreach ($rows as $row) {
			if ($row instanceof ObjectEntityInterface) {
				$row = ['id' => (string)$row->getUuid()] + $row->getObject();
			}

			$row = (array)$row;
			$row['id'] = (string)($row['id'] ?? ($row['uuid'] ?? ((array)($row['@self'] ?? []))['id'] ?? ''));
			$objects[] = $row;
		}

		return $objects;
	}//end objects()

	/**
	 * A record as JSON.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string
	 */
	private static function json(array $record): string {
		return (string)json_encode($record, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end json()
}//end class
