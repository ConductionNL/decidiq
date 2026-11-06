<?php

/**
 * Decidiq Publication Event Recorder
 *
 * Records one PublicationEvent each time an agenda is published or changed,
 * a paper is added to a meeting whose agenda is published, or a decision,
 * agenda or minutes are published to the public (change
 * publication-subscriptions-and-daily-digest). The digest job groups these
 * into one message per subscriber.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes PublicationEvent objects, fail-soft: recording never breaks the flow that called it.
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
 */
class PublicationEventRecorder {

	/**
	 * The schema of an object a published source type lives in.
	 *
	 * @var array<string, string>
	 */
	private const SOURCE_SCHEMAS = [
		'agenda'   => 'meeting',
		'decision' => 'decision',
		'minutes'  => 'minutes',
	];

	/**
	 * Construct the recorder.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param LoggerInterface        $logger        PSR-3 logger
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * An agenda was published to the members, for the first time or after a revision.
	 *
	 * Members hear of it; residents do not until the agenda is published to the
	 * public, which published() records.
	 *
	 * @param string              $meetingId The meeting
	 * @param array<string,mixed> $meeting   The meeting as read before the save
	 * @param bool                $revised   Whether this is a republish after a revision
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function agendaPublished(string $meetingId, array $meeting, bool $revised): void {
		$summary = 'Agenda published';
		if ($revised === true) {
			$summary = 'Revised agenda published';
		}

		$this->record(
			event: [
				'kind'           => 'agenda',
				'governanceBody' => self::relationId(value: ($meeting['governanceBody'] ?? null)),
				'meeting'        => $meetingId,
				'objectType'     => 'meeting',
				'objectId'       => $meetingId,
				'title'          => (string)($meeting['title'] ?? ''),
				'summary'        => $summary,
				'isPublished'    => false,
			]
		);
	}//end agendaPublished()

	/**
	 * The agenda of a published meeting changed.
	 *
	 * The summary compares the item snapshot of the new agenda version with the one before it.
	 *
	 * @param string                         $meetingId The meeting
	 * @param array<string,mixed>            $meeting   The meeting as read before the save
	 * @param array<int,array<string,mixed>> $versions  The agenda versions, the new one last
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function agendaChanged(string $meetingId, array $meeting, array $versions): void {
		$versions = array_values($versions);
		$after    = (array)(end($versions)['items'] ?? []);
		$before   = [];
		if (count($versions) > 1) {
			$before = (array)($versions[(count($versions) - 2)]['items'] ?? []);
		}

		$this->record(
			event: [
				'kind'           => 'agenda',
				'governanceBody' => self::relationId(value: ($meeting['governanceBody'] ?? null)),
				'meeting'        => $meetingId,
				'objectType'     => 'meeting',
				'objectId'       => $meetingId,
				'title'          => (string)($meeting['title'] ?? ''),
				'summary'        => self::changeSummary(before: $before, after: $after),
				'isPublished'    => false,
			]
		);
	}//end agendaChanged()

	/**
	 * A decision, agenda or minutes was published to the public.
	 *
	 * @param string              $sourceType decision, agenda or minutes
	 * @param string              $sourceId   The published object
	 * @param array<string,mixed> $source     The published object's data
	 * @param string|null         $bodyId     The body, as the publication resolved it
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function published(string $sourceType, string $sourceId, array $source, ?string $bodyId): void {
		if (isset(self::SOURCE_SCHEMAS[$sourceType]) === false) {
			return;
		}

		$meeting = self::relationId(value: ($source['meeting'] ?? null));
		if ($sourceType === 'agenda') {
			$meeting = $sourceId;
		}

		$summaries = [
			'agenda'   => 'Agenda published',
			'decision' => 'Decision published',
			'minutes'  => 'Minutes published',
		];

		$this->record(
			event: [
				'kind'           => $sourceType,
				'governanceBody' => $bodyId,
				'meeting'        => $meeting,
				'objectType'     => self::SOURCE_SCHEMAS[$sourceType],
				'objectId'       => $sourceId,
				'title'          => (string)($source['title'] ?? ''),
				'summary'        => $summaries[$sourceType],
				'isPublished'    => true,
			]
		);
	}//end published()

	/**
	 * A paper was added to a meeting or agenda item whose meeting has a published agenda.
	 *
	 * Before the agenda is published nothing is recorded: the publish itself
	 * is the news then. Residents do not hear of a paper here; the public
	 * agenda publication carries its public papers.
	 *
	 * @param string $schema   meeting or agenda-item, the object whose folder holds the paper
	 * @param string $objectId That object
	 * @param string $fileName The paper's file name
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public function paperAdded(string $schema, string $objectId, string $fileName): void {
		try {
			$meetingId = $objectId;
			if ($schema === 'agenda-item') {
				$item      = $this->read(schema: 'agenda-item', id: $objectId);
				$meetingId = (string)self::relationId(value: ($item['meeting'] ?? null));
			}

			$meeting = [];
			if ($meetingId !== '') {
				$meeting = $this->read(schema: 'meeting', id: $meetingId);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: no publication event for a paper, its meeting could not be read',
				['objectId' => $objectId, 'error' => $e->getMessage()]
			);
			return;
		}

		if (empty($meeting['agendaPublishedAt']) === true) {
			return;
		}

		$this->record(
			event: [
				'kind'           => 'paper',
				'governanceBody' => self::relationId(value: ($meeting['governanceBody'] ?? null)),
				'meeting'        => $meetingId,
				'objectType'     => $schema,
				'objectId'       => $objectId,
				'title'          => (string)($meeting['title'] ?? ''),
				'summary'        => 'Paper added: ' . $fileName,
				'isPublished'    => false,
			]
		);
	}//end paperAdded()

	/**
	 * One line on what changed between two agenda snapshots.
	 *
	 * @param array<int,array<string,mixed>> $before The earlier items (id, title, orderNumber)
	 * @param array<int,array<string,mixed>> $after  The new items
	 *
	 * @return string Such as "Agenda changed: item 4 added"
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-002-agendas-papers-decisions-and-minutes-are-recorded-as-events
	 */
	public static function changeSummary(array $before, array $after): string {
		$beforeIds = array_column(self::inAgendaOrder(items: $before), null, 'id');
		$afterIds  = array_column(self::inAgendaOrder(items: $after), null, 'id');

		$parts = [];
		foreach ($afterIds as $id => $item) {
			if (isset($beforeIds[$id]) === false) {
				$parts[] = 'item ' . self::itemLabel(item: $item) . ' added';
			}
		}

		foreach ($beforeIds as $id => $item) {
			if (isset($afterIds[$id]) === false) {
				$parts[] = 'item ' . self::itemLabel(item: $item) . ' withdrawn';
			}
		}

		if ($parts === []) {
			$parts[] = 'items edited or moved';
		}

		return 'Agenda changed: ' . implode(', ', $parts);
	}//end changeSummary()

	/**
	 * The items sorted by their number; items without one keep their place at the end.
	 *
	 * @param array<int,array<string,mixed>> $items Snapshot items
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function inAgendaOrder(array $items): array {
		usort(
			$items,
			static fn(array $a, array $b): int => ((int)($a['orderNumber'] ?? PHP_INT_MAX) <=> (int)($b['orderNumber'] ?? PHP_INT_MAX))
		);

		return $items;
	}//end inAgendaOrder()

	/**
	 * An item's number, or its title when it has none.
	 *
	 * @param array<string,mixed> $item A snapshot item
	 *
	 * @return string
	 */
	private static function itemLabel(array $item): string {
		$number = ($item['orderNumber'] ?? null);
		if ($number !== null && $number !== '') {
			return (string)$number;
		}

		return (string)($item['title'] ?? '');
	}//end itemLabel()

	/**
	 * The id of a relation, whether stored as a uuid, a list or an object.
	 *
	 * @param mixed $value The stored relation
	 *
	 * @return string|null
	 */
	private static function relationId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value[0] ?? null));
		}

		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end relationId()

	/**
	 * Read an object as a plain array, in system context.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The object
	 *
	 * @return array<string,mixed>
	 */
	private function read(string $schema, string $id): array {
		$found = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		if ($found === null) {
			return [];
		}

		return $found->getObject();
	}//end read()

	/**
	 * Store one event, never throwing.
	 *
	 * @param array<string,mixed> $event The event without its time
	 *
	 * @return void
	 */
	private function record(array $event): void {
		$event['occurredAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$event = array_filter($event, static fn(mixed $value): bool => $value !== null);

		try {
			$this->objectService->saveObject(
				object: $event,
				register: 'decidiq',
				schema: 'publication-event',
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: publication event not recorded', ['kind' => $event['kind'], 'error' => $e->getMessage()]);
		}
	}//end record()
}//end class
