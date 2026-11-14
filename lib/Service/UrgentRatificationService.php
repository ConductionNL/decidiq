<?php

/**
 * Urgent Ratification Service
 *
 * The ratification an urgent decision needs: an ordinary `ratifying` stage
 * appended to its route, assigned to the ratifying body, and an agenda item on
 * that body's next regular meeting. When the body has no such meeting yet the
 * stage records the placement as pending, and the placement is made when a
 * regular meeting of that body is created.
 *
 * Multi-object writes the declarative dialect cannot express (ADR-031 seam B,
 * design.md); the stage itself runs the unchanged DecisionStage lifecycle.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;
use Throwable;

/**
 * Appends and places the ratification of an urgent decision.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
 */
class UrgentRatificationService {

	/**
	 * The register every object lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Meeting lifecycle states a ratification can no longer be placed on.
	 *
	 * @var list<string>
	 */
	private const CLOSED_MEETING_STATES = ['closed', 'cancelled', 'archived', 'completed'];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $timeFactory The current time, for "next" meeting.
	 */
	public function __construct(
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Append the ratifying stage for an urgent decision and place it on the agenda.
	 *
	 * Does nothing when the policy does not require ratification, and nothing
	 * when the decision already has a ratifying stage (a repeated call does not
	 * add a second one).
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $decision      The urgent decision.
	 * @param array<string, mixed> $policy        The body's urgency policy.
	 * @param string|null          $bodyOverride  The ratifying body chosen at trigger time, or null for the policy's.
	 *
	 * @return array{stage: array<string, mixed>, agendaItem: array<string, mixed>|null, meeting: array<string, mixed>|null}|null The stage
	 *         and its placement, or null when no ratification is required.
	 *
	 * @throws RuntimeException When no ratifying body is known or a write fails.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
	 */
	public function appendRatification(object $objectService, array $decision, array $policy, ?string $bodyOverride=null): ?array {
		if (($policy['ratificationRequired'] ?? true) === false) {
			return null;
		}

		$decisionId = self::idOf(row: $decision);
		$body       = trim((string)($bodyOverride ?? ($policy['ratifyingBody'] ?? '')));
		if ($decisionId === '' || $body === '') {
			throw new RuntimeException('The urgency policy names no ratifying body.');
		}

		$stages = $this->rows(
			result: $objectService->findAll(['filters' => ['register' => self::REGISTER, 'schema' => 'decision-stage', 'decision' => $decisionId]])
		);
		$last   = 0;
		foreach ($stages as $stage) {
			if (($stage['stageType'] ?? '') === 'ratifying') {
				return ['stage' => $stage, 'agendaItem' => null, 'meeting' => null];
			}

			$last = max($last, (int)($stage['sequence'] ?? 0));
		}

		$stage = $this->save(objectService: $objectService, schema: 'decision-stage', object: [
			'decision'          => $decisionId,
			'sequence'          => ($last + 1),
			'stageType'         => 'ratifying',
			'status'            => 'pending',
			'mandatory'         => true,
			'decisionMakerType' => 'body',
			'assignedBody'      => $body,
			'label'             => 'Ratification',
			'placementPending'  => true,
		]);

		$meeting = $this->nextRegularMeeting(objectService: $objectService, bodyId: $body);
		if ($meeting === null) {
			return ['stage' => $stage, 'agendaItem' => null, 'meeting' => null];
		}

		return $this->place(objectService: $objectService, stage: $stage, decision: $decision, meeting: $meeting);
	}//end appendRatification()

	/**
	 * Place every pending ratification of a meeting's body on that meeting.
	 *
	 * Called when a meeting is created: a regular, still-open, future meeting
	 * of a body with pending ratifications takes them on its agenda.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $meeting       The meeting just created.
	 *
	 * @return int The number of ratifications placed.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
	 */
	public function placePending(object $objectService, array $meeting): int {
		$body = self::refOf(value: ($meeting['governanceBody'] ?? null));
		if ($body === '' || $this->qualifies(meeting: $meeting) === false) {
			return 0;
		}

		$pending = $this->rows(result: $objectService->findAll(['filters' => [
			'register'     => self::REGISTER,
			'schema'       => 'decision-stage',
			'stageType'    => 'ratifying',
			'assignedBody' => $body,
		]]));

		$placed = 0;
		foreach ($pending as $stage) {
			if (($stage['placementPending'] ?? false) !== true || ($stage['status'] ?? 'pending') !== 'pending') {
				continue;
			}

			$decisionId = self::refOf(value: ($stage['decision'] ?? null));
			$decision   = null;
			if ($decisionId !== '') {
				$decision = $this->find(objectService: $objectService, schema: 'decision', id: $decisionId);
			}

			if ($decision === null) {
				continue;
			}

			$this->place(objectService: $objectService, stage: $stage, decision: $decision, meeting: $meeting);
			$placed++;
		}

		return $placed;
	}//end placePending()

	/**
	 * The ratifying body's next regular meeting that is still open, or null.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $bodyId        The ratifying body.
	 *
	 * @return array<string, mixed>|null The meeting.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
	 */
	public function nextRegularMeeting(object $objectService, string $bodyId): ?array {
		$meetings = $this->rows(result: $objectService->findAll(['filters' => [
			'register'       => self::REGISTER,
			'schema'         => 'meeting',
			'governanceBody' => $bodyId,
			'meetingType'    => 'regular',
		]]));

		$next = null;
		foreach ($meetings as $meeting) {
			if ($this->qualifies(meeting: $meeting) === false) {
				continue;
			}

			if ($next === null || strtotime((string)$meeting['scheduledDate']) < strtotime((string)$next['scheduledDate'])) {
				$next = $meeting;
			}
		}

		return $next;
	}//end nextRegularMeeting()

	/**
	 * Whether a meeting can take a ratification: regular, in the future, not closed.
	 *
	 * Dates are compared as UTC timestamps so offsets do not reorder them.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 *
	 * @return bool
	 */
	private function qualifies(array $meeting): bool {
		if (($meeting['meetingType'] ?? '') !== 'regular'
			|| in_array((string)($meeting['lifecycle'] ?? ''), self::CLOSED_MEETING_STATES, true) === true
		) {
			return false;
		}

		if ((string)($meeting['scheduledDate'] ?? '') === '') {
			return false;
		}

		try {
			$at = new DateTimeImmutable((string)$meeting['scheduledDate']);
		} catch (Throwable) {
			return false;
		}

		return $at > $this->timeFactory->getDateTime();
	}//end qualifies()

	/**
	 * Put the ratification on a meeting's agenda and mark the stage placed.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $stage         The ratifying stage.
	 * @param array<string, mixed> $decision      The urgent decision.
	 * @param array<string, mixed> $meeting       The meeting.
	 *
	 * @return array{stage: array<string, mixed>, agendaItem: array<string, mixed>, meeting: array<string, mixed>}
	 */
	private function place(object $objectService, array $stage, array $decision, array $meeting): array {
		$meetingId  = self::idOf(row: $meeting);
		$decisionId = self::idOf(row: $decision);
		$items      = $this->rows(
			result: $objectService->findAll(['filters' => ['register' => self::REGISTER, 'schema' => 'agenda-item', 'meeting' => $meetingId]])
		);
		$order      = 0;
		foreach ($items as $item) {
			$order = max($order, (int)($item['orderNumber'] ?? 0));
		}

		$agendaItem = $this->save(objectService: $objectService, schema: 'agenda-item', object: [
			'title'            => 'Ratification: ' . (string)($decision['title'] ?? ''),
			'itemType'         => 'decision',
			'orderNumber'      => ($order + 1),
			'meeting'          => $meetingId,
			'ratifiesDecision' => $decisionId,
			'description'      => (string)($decision['urgencyReason'] ?? ''),
		]);

		$placed                     = $this->withoutMeta(row: $stage);
		$placed['agendaItem']       = self::idOf(row: $agendaItem);
		$placed['placementPending'] = false;
		$placed                     = $this->save(objectService: $objectService, schema: 'decision-stage', object: $placed, uuid: self::idOf(row: $stage));

		return ['stage' => $placed, 'agendaItem' => $agendaItem, 'meeting' => $meeting];
	}//end place()

	/**
	 * Save an object and answer it as an array.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $schema        The schema slug.
	 * @param array<string, mixed> $object        The object.
	 * @param string|null          $uuid          The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @throws RuntimeException When the save fails.
	 */
	private function save(object $objectService, string $schema, array $object, ?string $uuid=null): array {
		try {
			$saved = $objectService->saveObject(object: $object, register: self::REGISTER, schema: $schema, uuid: $uuid);
		} catch (Throwable $e) {
			throw new RuntimeException('The ' . $schema . ' of the ratification could not be saved: ' . $e->getMessage(), 0, $e);
		}

		return $this->rowOf(value: $saved);
	}//end save()

	/**
	 * Find one object as an array, or null.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $schema        The schema slug.
	 * @param string $id            The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(object $objectService, string $schema, string $id): ?array {
		try {
			$found = $objectService->find(id: $id, register: self::REGISTER, schema: $schema);
		} catch (Throwable) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		return $this->rowOf(value: $found);
	}//end find()

	/**
	 * A findAll result as a list of arrays.
	 *
	 * @param mixed $result The result: a list, or an envelope with `results`.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(mixed $result): array {
		if (is_array($result) === true && isset($result['results']) === true && is_array($result['results']) === true) {
			$result = $result['results'];
		}

		$rows = [];
		foreach ((array)$result as $item) {
			$rows[] = $this->rowOf(value: $item);
		}

		return $rows;
	}//end rows()

	/**
	 * An object entity or array as an array.
	 *
	 * @param mixed $value The entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private function rowOf(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		return (array)$value;
	}//end rowOf()

	/**
	 * An object without its `@self` metadata, ready to save back.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutMeta(array $row): array {
		unset($row['@self']);
		return $row;
	}//end withoutMeta()

	/**
	 * An object's uuid: `@self.id`, else `id`, else `uuid`.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended
	 */
	public static function idOf(array $row): string {
		foreach ([($row['@self']['id'] ?? null), ($row['id'] ?? null), ($row['uuid'] ?? null)] as $candidate) {
			if (is_scalar($candidate) === true && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * A relation value (uuid or `{id}`) as a uuid string.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string
	 */
	private static function refOf(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? null);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end refOf()
}//end class
