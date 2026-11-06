<?php

/**
 * Decidiq Meeting Defaults Listener
 *
 * Fills the fields a new meeting was created without from its meeting type,
 * then from its governance body (change meeting-rules-from-body-and-type).
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
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pre-save hook on OpenRegister's ObjectCreatingEvent for meetings.
 *
 * A meeting created with a `type` (MeetingType) gets, for each field left
 * empty: the type's governance body, `quorumRequired` from the type's
 * `defaultQuorum` or else the body's `quorum`, an `endDate` of
 * `scheduledDate` plus the type's `defaultDurationMinutes`, and the type's
 * `initialLifecycle`. OpenRegister applies schema defaults before this hook
 * runs, so `lifecycle` arrives as `draft` and `isPublic` as `false`: a type's
 * initial stage replaces `draft`, and a type's `isPublic` applies only when
 * the meeting carries no value at all. The fill goes back through
 * setModifiedData(), which OpenRegister merges before the insert.
 *
 * Fails soft: a lookup error leaves the meeting as entered.
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
 *
 * @implements IEventListener<Event>
 */
class MeetingDefaultsListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface        $logger        Logger
	 * @param ObjectServiceInterface $objectService Reads the meeting type and body
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Fill the new meeting's empty fields.
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false) {
			return;
		}

		try {
			$meeting = (array)$event->getObject()->getObject();
			if (strtolower((string)($meeting['_schemaSlug'] ?? 'meeting')) !== 'meeting') {
				return;
			}

			$type = $this->load(id: $this->referenceId(value: ($meeting['type'] ?? null)), schema: 'meeting-type');
			if ($type === null) {
				return;
			}

			$patch = $this->defaultsFor(meeting: $meeting, type: $type);
			if ($patch !== []) {
				$event->setModifiedData(array_merge($event->getModifiedData(), $patch));
			}
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: meeting defaults skipped', ['exception' => $e->getMessage()]);
		}

	}//end handle()

	/**
	 * The fields to fill, from the type and then the body.
	 *
	 * @param array<string, mixed> $meeting The meeting as it will be saved
	 * @param array<string, mixed> $type    Its meeting type
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	private function defaultsFor(array $meeting, array $type): array {
		$patch = [];

		$bodyId = $this->referenceId(value: ($meeting['governanceBody'] ?? null));
		if ($bodyId === null) {
			$bodyId = $this->referenceId(value: ($type['governanceBody'] ?? null));
			if ($bodyId !== null) {
				$patch['governanceBody'] = $bodyId;
			}
		}

		$quorum = $this->quorum(meeting: $meeting, type: $type, bodyId: $bodyId);
		if ($quorum !== null) {
			$patch['quorumRequired'] = $quorum;
		}

		$endDate = $this->endDate(meeting: $meeting, minutes: ($type['defaultDurationMinutes'] ?? null));
		if ($endDate !== null) {
			$patch['endDate'] = $endDate;
		}

		return array_merge($patch, $this->stageDefaults(meeting: $meeting, type: $type));

	}//end defaultsFor()

	/**
	 * The quorum for a meeting entered without one: the type's, else the body's.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param array<string, mixed> $type    Its meeting type
	 * @param string|null          $bodyId  Its governance body
	 *
	 * @return int|null Null when nothing is to be filled
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	private function quorum(array $meeting, array $type, ?string $bodyId): ?int {
		if ($this->isEmpty(value: ($meeting['quorumRequired'] ?? null)) === false) {
			return null;
		}

		$quorum = ($type['defaultQuorum'] ?? ($this->load(id: $bodyId, schema: 'governance-body')['quorum'] ?? null));
		if (is_int($quorum) === false || $quorum <= 0) {
			return null;
		}

		return $quorum;

	}//end quorum()

	/**
	 * The type's initial stage (replacing the schema default `draft`) and its
	 * public flag (only when the meeting has none).
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param array<string, mixed> $type    Its meeting type
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	private function stageDefaults(array $meeting, array $type): array {
		$patch = [];

		$initial = (string)($type['initialLifecycle'] ?? '');
		if ($initial !== '' && $initial !== 'draft' && in_array(($meeting['lifecycle'] ?? null), [null, '', 'draft'], true) === true) {
			$patch['lifecycle'] = $initial;
		}

		if (($meeting['isPublic'] ?? null) === null && is_bool($type['isPublic'] ?? null) === true) {
			$patch['isPublic'] = $type['isPublic'];
		}

		return $patch;

	}//end stageDefaults()

	/**
	 * The end of the meeting: its start plus the type's planned length, when
	 * no end was entered.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param mixed                $minutes The type's defaultDurationMinutes
	 *
	 * @return string|null ISO 8601, or null when nothing is to be filled
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	private function endDate(array $meeting, mixed $minutes): ?string {
		$start = (string)($meeting['scheduledDate'] ?? '');
		if ($this->isEmpty(value: ($meeting['endDate'] ?? null)) === false || $start === '' || is_int($minutes) === false || $minutes <= 0) {
			return null;
		}

		return (new DateTimeImmutable($start))->add(new DateInterval('PT' . $minutes . 'M'))->format(DateTimeInterface::ATOM);

	}//end endDate()

	/**
	 * Load one object as an array, or null.
	 *
	 * @param string|null $id     The object id
	 * @param string      $schema The schema slug
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	private function load(?string $id, string $schema): ?array {
		if ($id === null) {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema);
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->getObject();

	}//end load()

	/**
	 * The id a reference holds: a uuid string or an expanded object.
	 *
	 * @param mixed $value The stored reference
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	private function referenceId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;

	}//end referenceId()

	/**
	 * Whether a field counts as not entered.
	 *
	 * @param mixed $value The value
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/meeting-management/spec.md#requirement-req-mrb-001-a-new-meeting-takes-its-type-and-body-defaults
	 */
	private function isEmpty(mixed $value): bool {
		return $value === null || $value === '' || $value === 0;

	}//end isEmpty()
}//end class
