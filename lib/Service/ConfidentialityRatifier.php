<?php

/**
 * Decidiq ConfidentialityRatifier
 *
 * The ratification of a confidentiality restriction whose ground requires
 * it: puts the restriction on the next meeting of the ratifying body with a
 * deadline on that meeting's date, and records the ratifying decision.
 * Nothing changes the legal state on the deadline alone: an overdue
 * restriction stays imposed and its target stays closed.
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Listener\EntityPayloadReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Places a restriction for ratification and records the ratification.
 *
 * Access control (OWASP A01): placing runs only after ConfidentialityImposer
 * has authorised the caller. Ratifying reads the restriction with the
 * caller's rights (404), then needs the authority of the imposing body or of
 * the body whose meeting holds the ratification item (403). The writes then
 * run as the system: the register lets only administrators update a
 * restriction, and the ratifying body's agenda is not the caller's to write.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
 */
class ConfidentialityRatifier {

	/**
	 * The schema of a restriction.
	 *
	 * @var string
	 */
	private const SCHEMA = 'confidentiality-restriction';

	/**
	 * Meeting states that still take agenda items.
	 *
	 * @var list<string>
	 */
	private const OPEN_MEETING_STATES = ['draft', 'scheduled'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param ConfidentialityImposer $imposer       Who holds a body's authority.
	 * @param ITimeFactory           $time          The clock.
	 * @param LoggerInterface        $logger        Logs a placement that failed.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ConfidentialityImposer $imposer,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Put a newly imposed restriction on the ratifying body's next meeting, when its ground requires ratification.
	 *
	 * The ratifying body is `ratifyingBody`, else the imposing body. The
	 * deadline is `ratificationDeadline` when given, else the meeting's date.
	 * A failure is reported, not raised: the restriction is already in force.
	 *
	 * @param array<string, mixed> $restriction The stored restriction, with its id.
	 * @param array<string, mixed> $params      `ratifyingBody` and `ratificationDeadline`, both optional.
	 *
	 * @return array{status: string, restriction: array<string, mixed>} `not-needed`,
	 *     `placed`, `no-meeting` or `not-placed`, and the restriction as it now is.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-geheimhouding-lands-on-the-confirming-bodys-next-agenda
	 */
	public function place(array $restriction, array $params): array {
		try {
			if ($this->needsRatification(restriction: $restriction) === false) {
				return ['status' => 'not-needed', 'restriction' => $restriction];
			}

			$body    = self::text(value: ($params['ratifyingBody'] ?? null)) ?? (string)($restriction['imposedByBody'] ?? '');
			$meeting = $this->nextMeeting(bodyId: $body);
			$changes = [];
			$status  = 'no-meeting';
			if ($meeting !== null) {
				$changes = [
					'ratificationAgendaItem' => $this->createItem(meeting: $meeting, restriction: $restriction),
					'ratificationDeadline'   => substr((string)$meeting['scheduledDate'], 0, 10),
				];
				$status  = 'placed';
			}

			$deadline = self::date(value: ($params['ratificationDeadline'] ?? null));
			if ($deadline !== null) {
				$changes['ratificationDeadline'] = $deadline;
			}

			if ($changes !== []) {
				$restriction = $this->save(restriction: array_merge($restriction, $changes));
			}
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: a confidentiality restriction could not be put on the agenda for ratification', ['error' => $e->getMessage()]);
			return ['status' => 'not-placed', 'restriction' => $restriction];
		}

		return ['status' => $status, 'restriction' => $restriction];
	}//end place()

	/**
	 * Record the ratifying decision: the restriction moves to ratified.
	 *
	 * @param string               $userId        The caller.
	 * @param string               $restrictionId The restriction.
	 * @param array<string, mixed> $params        `ratificationDecision`, required.
	 *
	 * @return array<string, mixed> The stored restriction.
	 *
	 * @throws ConfidentialityImposeRefusedException On a refusal (400, 403, 404, 409 or 422).
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-bekrachtigingsbesluit-recorded
	 */
	public function ratify(string $userId, string $restrictionId, array $params): array {
		$restriction = $this->readable(id: $restrictionId);

		if ($this->mayRatify(userId: $userId, restriction: $restriction) === false) {
			throw new ConfidentialityImposeRefusedException(
				message: 'Only the chair, vice-chair or secretary of the imposing or ratifying body, the secretariat or an administrator '
					. 'may record the ratification.',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$decision = self::text(value: ($params['ratificationDecision'] ?? null));
		if ($decision === null) {
			throw new ConfidentialityImposeRefusedException(
				message: 'A ratification is recorded on the ratifying decision. Name the decision.',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		if (($restriction['lifecycle'] ?? null) !== 'imposed') {
			throw new ConfidentialityImposeRefusedException(message: 'Only an imposed restriction can be ratified.', status: Http::STATUS_CONFLICT);
		}

		if ($this->needsRatification(restriction: $restriction) === false) {
			throw new ConfidentialityImposeRefusedException(message: 'The ground of this restriction needs no ratification.', status: Http::STATUS_CONFLICT);
		}

		if ($this->load(id: $decision, schema: 'decision', asCaller: true) === null) {
			throw new ConfidentialityImposeRefusedException(message: 'The ratifying decision was not found.');
		}

		return $this->save(
			restriction: array_merge(
				$restriction,
				[
					'lifecycle'            => 'ratified',
					'ratificationDecision' => $decision,
					'ratificationDate'     => $this->time->getDateTime()->format(DATE_ATOM),
				]
			)
		);
	}//end ratify()

	/**
	 * Whether the restriction's ground requires ratification. A ground that cannot be read counts as requiring it.
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
	 */
	private function needsRatification(array $restriction): bool {
		$ground = $this->load(id: (string)($restriction['ground'] ?? ''), schema: 'confidentiality-ground', asCaller: false);

		return $ground === null || ($ground['requiresRatification'] ?? true) !== false;
	}//end needsRatification()

	/**
	 * Whether the caller holds the authority of the imposing body or of the body that holds the ratification item.
	 *
	 * @param string               $userId      The caller.
	 * @param array<string, mixed> $restriction The restriction.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-bekrachtigingsbesluit-recorded
	 */
	private function mayRatify(string $userId, array $restriction): bool {
		if ($this->imposer->mayImpose(userId: $userId, bodyId: (string)($restriction['imposedByBody'] ?? '')) === true) {
			return true;
		}

		$item    = $this->load(id: (string)($restriction['ratificationAgendaItem'] ?? ''), schema: 'agenda-item', asCaller: false);
		$meeting = $this->load(id: (string)($item['meeting'] ?? ''), schema: 'meeting', asCaller: false);
		$body    = (string)($meeting['governanceBody'] ?? '');

		return $body !== '' && $this->imposer->mayImpose(userId: $userId, bodyId: $body) === true;
	}//end mayRatify()

	/**
	 * The body's first meeting from today that still takes agenda items.
	 *
	 * @param string $bodyId The body.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-geheimhouding-lands-on-the-confirming-bodys-next-agenda
	 */
	private function nextMeeting(string $bodyId): ?array {
		if ($bodyId === '') {
			return null;
		}

		$today = $this->time->getDateTime()->format('Y-m-d');
		$next  = null;
		foreach ($this->rows(filters: ['schema' => 'meeting', 'governanceBody' => $bodyId]) as $meeting) {
			$date = substr((string)($meeting['scheduledDate'] ?? ''), 0, 10);
			if (($meeting['governanceBody'] ?? null) !== $bodyId
				|| in_array(($meeting['lifecycle'] ?? 'draft'), self::OPEN_MEETING_STATES, true) === false
				|| $date === '' || $date < $today
			) {
				continue;
			}

			if ($next === null || $date < substr((string)$next['scheduledDate'], 0, 10)) {
				$next = $meeting;
			}
		}

		return $next;
	}//end nextMeeting()

	/**
	 * Create the closed ratification item at the end of the meeting's agenda.
	 *
	 * @param array<string, mixed> $meeting     The meeting.
	 * @param array<string, mixed> $restriction The restriction.
	 *
	 * @return string The item's id.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-geheimhouding-lands-on-the-confirming-bodys-next-agenda
	 */
	private function createItem(array $meeting, array $restriction): string {
		$meetingId = (string)($meeting['id'] ?? '');
		$last      = 0;
		foreach ($this->rows(filters: ['schema' => 'agenda-item', 'meeting' => $meetingId]) as $item) {
			$last = max($last, (int)($item['orderNumber'] ?? 0));
		}

		// The item is about confidential matter, so it is closed from the start, like its papers.
		$item = $this->objectService->saveObject(
			object: [
				'meeting'         => $meetingId,
				'title'           => 'Bekrachtigen geheimhouding ' . $this->subjectOf(restriction: $restriction),
				'itemType'        => 'decision',
				'orderNumber'     => ($last + 1),
				'confidentiality' => 'confidential',
				'public'          => false,
			],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'agenda-item')),
			_rbac: false,
			_multitenancy: false
		);

		return (string)((new EntityPayloadReader())->payloadOf(entity: $item)['id'] ?? '');
	}//end createItem()

	/**
	 * What the restriction covers, in words: its target's title or name, else "stukken".
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-geheimhouding-lands-on-the-confirming-bodys-next-agenda
	 */
	private function subjectOf(array $restriction): string {
		$target = ConfidentialityImposer::TARGETS[(string)($restriction['scope'] ?? '')] ?? null;
		if ($target !== null) {
			$row  = $this->load(id: (string)($restriction[$target['field']] ?? ''), schema: $target['schema'], asCaller: false);
			$name = trim((string)($row['title'] ?? ($row['name'] ?? '')));
			if ($name !== '') {
				return (string)preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', $name);
			}
		}

		return 'stukken';
	}//end subjectOf()

	/**
	 * The restriction, read with the caller's rights.
	 *
	 * @param string $id The restriction.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ConfidentialityImposeRefusedException With 404.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-bekrachtigingsbesluit-recorded
	 */
	private function readable(string $id): array {
		try {
			$restriction = $this->load(id: $id, schema: self::SCHEMA, asCaller: true);
		} catch (Throwable) {
			// OpenRegister answers an object the caller may not read with an exception or with null.
			$restriction = null;
		}

		if ($restriction === null) {
			throw new ConfidentialityImposeRefusedException(message: 'The restriction was not found.', status: Http::STATUS_NOT_FOUND);
		}

		return $restriction;
	}//end readable()

	/**
	 * Save a restriction as the system.
	 *
	 * @param array<string, mixed> $restriction The restriction, with its id.
	 *
	 * @return array<string, mixed> The stored restriction.
	 */
	private function save(array $restriction): array {
		$saved = $this->objectService->saveObject(
			object: $restriction,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: self::SCHEMA), uuid: (string)($restriction['id'] ?? '')),
			_rbac: false,
			_multitenancy: false
		);

		return (new EntityPayloadReader())->payloadOf(entity: $saved);
	}//end save()

	/**
	 * The rows matching the filters, read as the system.
	 *
	 * @param array<string, string> $filters The filters, with `schema`.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => 'decidiq'], $filters)],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			$rows[] = (new EntityPayloadReader())->payloadOf(entity: $row);
		}

		return $rows;
	}//end rows()

	/**
	 * An object's data, or null when it does not exist (or, as the caller, cannot be read).
	 *
	 * @param string $id       The object id.
	 * @param string $schema   The schema slug.
	 * @param bool   $asCaller True to read with the caller's rights, false as the system.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load(string $id, string $schema, bool $asCaller): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: 'decidiq', schema: $schema),
				_rbac: $asCaller,
				_multitenancy: $asCaller
			);
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (new EntityPayloadReader())->payloadOf(entity: $entity);
	}//end load()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()

	/**
	 * A `Y-m-d` date, or null when the value is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function date(mixed $value): ?string {
		$text = self::text(value: $value);
		if ($text === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
			return null;
		}

		return $text;
	}//end date()
}//end class
