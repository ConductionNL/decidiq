<?php

/**
 * Lapses a confidentiality restriction the ratifying body did not ratify.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A restriction still `imposed` on a ground that needs ratification lapses
 * when the meeting carrying its ratification item closes and the item records
 * neither the ratification nor that it was postponed, or as soon as a clerk
 * records "not ratified" on the item (design D2).
 *
 * The lapse is a state, not a lift: `lapsed` is terminal, and
 * ConfidentialityRestrictions keeps blocking publication only for imposed and
 * ratified, so the target goes through the normal publication check. The
 * write names the meeting (`lapsedAtMeeting`) beside the item
 * (`ratificationAgendaItem`), and the object's audit trail records it. A
 * restriction or ground that cannot be read is left as it is: a lapse is
 * never guessed.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
 */
class ConfidentialityLapseListener implements IEventListener {

	/**
	 * The register everything lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The schema slug of a meeting.
	 *
	 * @var string
	 */
	public const SCHEMA_MEETING = 'meeting';

	/**
	 * The schema slug of an agenda item.
	 *
	 * @var string
	 */
	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	/**
	 * The schema slug of a restriction.
	 *
	 * @var string
	 */
	private const SCHEMA_RESTRICTION = 'confidentiality-restriction';

	/**
	 * The outcomes on an item that keep a restriction from lapsing at meeting close.
	 *
	 * @var list<string>
	 */
	private const KEEPS = ['ratified', 'postponed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Lapse what a closing meeting or a "not ratified" outcome ends.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false || $event->getNewObject() === null) {
			return;
		}

		$new = (array)$event->getNewObject()->getObject();
		$old = (array)($event->getOldObject()?->getObject() ?? []);
		$now = new DateTimeImmutable();

		if ($this->justClosed(new: $new, old: $old) === true) {
			$this->lapseAtClose(meetingId: $this->idOf(row: $new, entity: $event->getNewObject()), now: $now);
			return;
		}

		if ($this->justNotRatified(new: $new, old: $old) === true) {
			$itemId = $this->idOf(row: $new, entity: $event->getNewObject());
			$moment = new LapseMoment(meetingId: (string)($new['meeting'] ?? ''), now: $now, atClose: false);
			$this->lapseForItem(item: $new + ['id' => $itemId], moment: $moment);
		}
	}//end handle()

	/**
	 * Whether a meeting just moved to closed.
	 *
	 * @param array<string, mixed> $new The meeting after the save.
	 * @param array<string, mixed> $old The meeting before it.
	 *
	 * @return bool
	 */
	private function justClosed(array $new, array $old): bool {
		return ($new['lifecycle'] ?? null) === 'closed'
			&& ($old['lifecycle'] ?? null) !== 'closed';
	}//end justClosed()

	/**
	 * Whether a clerk just recorded "not ratified" on an agenda item.
	 *
	 * @param array<string, mixed> $new The item after the save.
	 * @param array<string, mixed> $old The item before it.
	 *
	 * @return bool
	 */
	private function justNotRatified(array $new, array $old): bool {
		return ($new['ratificationOutcome'] ?? null) === 'not-ratified'
			&& ($old['ratificationOutcome'] ?? null) !== 'not-ratified';
	}//end justNotRatified()

	/**
	 * Lapse every restriction whose ratification item sat on the closed meeting.
	 *
	 * @param string            $meetingId The meeting.
	 * @param DateTimeImmutable $now       When it closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function lapseAtClose(string $meetingId, DateTimeImmutable $now): void {
		if ($meetingId === '') {
			return;
		}

		foreach ($this->rows(filters: ['schema' => self::SCHEMA_AGENDA_ITEM, 'meeting' => $meetingId]) as $item) {
			if ((string)($item['meeting'] ?? '') === $meetingId) {
				$this->lapseForItem(item: $item, moment: new LapseMoment(meetingId: $meetingId, now: $now, atClose: true));
			}
		}
	}//end lapseAtClose()

	/**
	 * Lapse the restrictions put to the body with this item, where they lapse.
	 *
	 * @param array<string, mixed> $item   The agenda item.
	 * @param LapseMoment          $moment The meeting it sits on, the moment of the lapse, and whether the meeting closed (else a clerk recorded it).
	 *
	 * @return void
	 */
	private function lapseForItem(array $item, LapseMoment $moment): void {
		$itemId = (string)($item['id'] ?? '');
		if ($itemId === '') {
			return;
		}

		$restrictions = $this->rows(filters: ['schema' => self::SCHEMA_RESTRICTION, 'ratificationAgendaItem' => $itemId, 'lifecycle' => 'imposed']);
		foreach ($restrictions as $restriction) {
			if ((string)($restriction['ratificationAgendaItem'] ?? '') !== $itemId) {
				continue;
			}

			$ground = $this->ground(groundId: (string)($restriction['ground'] ?? ''));
			if ($this->lapses(restriction: $restriction, ground: $ground, item: $item, atClose: $moment->atClose) === true) {
				$this->lapse(restriction: $restriction, moment: $moment->forItem(itemId: $itemId));
			}
		}
	}//end lapseForItem()

	/**
	 * Whether a restriction lapses, given its ground and its ratification item.
	 *
	 * It lapses only while it is still imposed with no ratification decision,
	 * on a ground that needs ratification. At meeting close an item marked
	 * ratified or postponed keeps it; a clerk's "not ratified" lapses it at once.
	 *
	 * @param array<string, mixed>      $restriction The restriction.
	 * @param array<string, mixed>|null $ground      Its ground, null when unreadable.
	 * @param array<string, mixed>      $item        Its ratification agenda item.
	 * @param bool                      $atClose     Whether the meeting closed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function lapses(array $restriction, ?array $ground, array $item, bool $atClose): bool {
		if (($restriction['lifecycle'] ?? null) !== 'imposed'
			|| trim((string)($restriction['ratificationDecision'] ?? '')) !== ''
			|| ($ground['requiresRatification'] ?? false) !== true
		) {
			return false;
		}

		$outcome = ($item['ratificationOutcome'] ?? null);
		if ($atClose === false) {
			return $outcome === 'not-ratified';
		}

		return in_array($outcome, self::KEEPS, true) === false;
	}//end lapses()

	/**
	 * Write the lapse onto the restriction.
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 * @param LapseMoment          $moment      The meeting, the ratification item and the moment of the lapse.
	 *
	 * @return void
	 */
	private function lapse(array $restriction, LapseMoment $moment): void {
		$meetingId = $moment->meetingId;
		$itemId    = $moment->itemId;
		$id = (string)($restriction['id'] ?? ($restriction['@self']['id'] ?? ''));
		unset($restriction['@self']);
		$restriction['lifecycle'] = 'lapsed';
		$restriction['lapsedAt']  = $moment->now->format(DateTimeInterface::ATOM);
		if ($meetingId !== '') {
			$restriction['lapsedAtMeeting'] = $meetingId;
		}

		try {
			$this->objectService->saveObject(
				object: $restriction,
				register: self::REGISTER,
				schema: self::SCHEMA_RESTRICTION,
				uuid: $id
			);
			$this->logger->info(
				'Decidiq: a confidentiality lapsed because the body did not ratify it',
				['restriction' => $id, 'meeting' => $meetingId, 'agendaItem' => $itemId]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: a confidentiality that should lapse could not be saved',
				['restriction' => $id, 'meeting' => $meetingId, 'agendaItem' => $itemId, 'error' => $e->getMessage()]
			);
		}
	}//end lapse()

	/**
	 * The ground of a restriction, null when it cannot be read.
	 *
	 * @param string $groundId The ground.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ground(string $groundId): ?array {
		if ($groundId === '') {
			return null;
		}

		try {
			$ground = $this->objectService->find(id: $groundId, register: self::REGISTER, schema: 'confidentiality-ground', _rbac: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: a confidentiality ground could not be read; nothing lapses',
				['ground' => $groundId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($ground === null) {
			return null;
		}

		return (array)$ground->jsonSerialize();
	}//end ground()

	/**
	 * The objects matching a filter, as arrays; none when they cannot be read.
	 *
	 * @param array<string, string> $filters The filters, schema included.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $filters): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER] + $filters],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: objects could not be read; nothing lapses', ['filters' => $filters, 'error' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * The id of the saved object.
	 *
	 * @param array<string, mixed> $row    Its data.
	 * @param object               $entity The entity.
	 *
	 * @return string
	 */
	private function idOf(array $row, object $entity): string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($id === '' && method_exists($entity, 'getUuid') === true) {
			$id = (string)$entity->getUuid();
		}

		return $id;
	}//end idOf()
}//end class
