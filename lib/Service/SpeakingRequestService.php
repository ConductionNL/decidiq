<?php

/**
 * Registration and moderation of requests to speak at a meeting.
 *
 * The states, the frozen citizen fields after approval and the required
 * rejection reason are declared on the speaking-request schema. What a
 * declaration cannot say lives here: whether the body offers speaking rights
 * at all, whether the request names an agenda item when it must (and not when
 * it must not), and whether the registration deadline (meeting start minus the
 * policy's hours) has passed, which compares across objects (design D3).
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
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use Exception;
use OCA\Decidiq\Exception\SpeakingRequestRefusedException;
use OCA\Decidiq\Kernel\Contracts\SpeakingRequestServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Checks a request against the body's speaking policy and moderates it.
 */
class SpeakingRequestService implements SpeakingRequestServiceInterface {

	private const REGISTER = 'decidiq';

	private const SCHEMA = 'speaking-request';

	private const POLICY = 'speaking-policy';

	private const TOPIC_KEYS = ['speakerName', 'organisation', 'topicText', 'requestedMinutes'];

	private const CONTACT_KEYS = ['name', 'email', 'phone', 'address'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade.
	 * @param ITimeFactory           $time          The clock.
	 * @param SpeakingSlots|null     $slots         Builds the agenda, public and queue views.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $time,
		private readonly ?SpeakingSlots $slots = null,
	) {
	}//end __construct()

	/**
	 * The internal agenda view of a meeting's speaking requests: per agenda
	 * item and for the meeting block, the approved slots in order and the
	 * pending requests.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws SpeakingRequestRefusedException 404 when the meeting is unknown.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
	 */
	public function agenda(string $meetingId): array {
		$this->requireMeeting(meetingId: $meetingId);
		return $this->slots()->internal(requests: $this->requestsOf(meetingId: $meetingId));
	}//end agenda()

	/**
	 * The public view of a meeting's speakers, as the body's policy allows.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return array{display: string, agenda: array<string, array<string, mixed>>}
	 *
	 * @throws SpeakingRequestRefusedException 404 when the meeting is unknown.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-008-anonymised-public-agenda-view-per-policy
	 */
	public function publicAgenda(string $meetingId): array {
		$meeting = $this->requireMeeting(meetingId: $meetingId);
		$policy  = $this->findPolicy(bodyId: $this->bodyOf(meeting: $meeting, supplied: ''));
		$display = (string)($policy['publicDisplay'] ?? 'count');
		if (in_array($display, SpeakingSlots::DISPLAYS, true) === false) {
			$display = 'count';
		}

		return [
			'display' => $display,
			'agenda'  => $this->slots()->public(requests: $this->requestsOf(meetingId: $meetingId), display: $display),
		];
	}//end publicAgenda()

	/**
	 * The approved speakers of an agenda item as speaker queue entries, in
	 * order, to put ahead of the entries the chair adds.
	 *
	 * @param string $meetingId    The meeting.
	 * @param string $agendaItemId The agenda item, or '' for the meeting block.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws SpeakingRequestRefusedException 404 when the meeting is unknown.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
	 */
	public function queue(string $meetingId, string $agendaItemId): array {
		$this->requireMeeting(meetingId: $meetingId);
		return $this->slots()->queue(requests: $this->requestsOf(meetingId: $meetingId), agendaItemId: trim($agendaItemId));
	}//end queue()

	/**
	 * Record that an approved speaker spoke or did not show up.
	 *
	 * The whole request is saved again with only its status changed, so
	 * every other field (the contribution, the transcript segment) survives.
	 *
	 * @param string $requestId The speaking request.
	 * @param string $outcome   spoke or no-show.
	 *
	 * @return array<string, mixed> The saved request.
	 *
	 * @throws SpeakingRequestRefusedException 400 for another outcome, 404 for an unknown request,
	 *                                         409 when the request is not approved.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
	 */
	public function markOutcome(string $requestId, string $outcome): array {
		if (in_array($outcome, ['spoke', 'no-show'], true) === false) {
			throw new SpeakingRequestRefusedException(message: 'The outcome is spoke or no-show.', status: 400);
		}

		$request = $this->read(schema: self::SCHEMA, id: $requestId);
		if ($request === null) {
			throw new SpeakingRequestRefusedException(message: 'Speaking request not found.', status: 404);
		}

		if (($request['lifecycle'] ?? '') !== 'approved') {
			throw new SpeakingRequestRefusedException(message: 'Only an approved speaking request can be marked as spoken or no-show.', status: 409);
		}

		$request['lifecycle'] = $outcome;
		return $this->write(request: ['id' => $requestId] + $request);
	}//end markOutcome()

	/**
	 * The speaking requests of a meeting, each with its id.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function requestsOf(string $meetingId): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'meeting' => $meetingId]],
			_rbac: false,
			_multitenancy: false
		);

		$requests = [];
		foreach ($rows as $row) {
			$request = $row->jsonSerialize();
			if ((string)($request['meeting'] ?? '') !== $meetingId) {
				// The filter may match loosely; only this meeting's requests count.
				continue;
			}

			$request['id'] = (string)$row->getUuid();
			$requests[]    = $request;
		}

		return $requests;
	}//end requestsOf()

	/**
	 * The slot builder.
	 *
	 * @return SpeakingSlots
	 */
	private function slots(): SpeakingSlots {
		return $this->slots ?? new SpeakingSlots();
	}//end slots()

	/**
	 * The meeting a speaking request is for, or an empty string when it does not exist.
	 *
	 * @param string $requestId The speaking request.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
	 */
	public function meetingOf(string $requestId): string {
		return (string)($this->read(schema: self::SCHEMA, id: $requestId)['meeting'] ?? '');
	}//end meetingOf()

	/**
	 * Register a request to speak, checked against the body's speaking policy.
	 *
	 * @param array<string, mixed> $input    meeting, agendaItem, governanceBody, topic and contactDetails.
	 * @param bool                 $override Whether the secretariat registers past the deadline.
	 *
	 * @return array<string, mixed> The stored request, with its id.
	 *
	 * @throws SpeakingRequestRefusedException When the policy refuses it.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline
	 */
	public function register(array $input, bool $override=false): array {
		$meetingId = trim((string)($input['meeting'] ?? ''));
		$meeting   = $this->requireMeeting(meetingId: $meetingId);
		$bodyId    = $this->bodyOf(meeting: $meeting, supplied: trim((string)($input['governanceBody'] ?? '')));
		$policy    = $this->policyOf(bodyId: $bodyId);
		$itemId    = $this->checkAgendaItem(policy: $policy, meetingId: $meetingId, itemId: trim((string)($input['agendaItem'] ?? '')));

		$topic = $this->pick(value: ($input['topic'] ?? []), keys: self::TOPIC_KEYS);
		if (trim((string)($topic['speakerName'] ?? '')) === '' || trim((string)($topic['topicText'] ?? '')) === '') {
			throw new SpeakingRequestRefusedException(message: 'A speaking request needs the speaker\'s name and the topic.', status: 400);
		}

		$topic['requestedMinutes'] = (int)($topic['requestedMinutes'] ?? $this->defaultMinutes(policy: $policy));

		$late = $this->deadlinePassed(meeting: $meeting, policy: $policy);
		if ($late === true && $override === false) {
			throw new SpeakingRequestRefusedException(
				message: sprintf('Registration for this meeting closed %d hours before it starts.', $this->deadlineHours(policy: $policy)),
				status: 409
			);
		}

		$request = [
			'meeting'        => $meetingId,
			'governanceBody' => $bodyId,
			'topic'          => $topic,
			'contactDetails' => $this->pick(value: ($input['contactDetails'] ?? []), keys: self::CONTACT_KEYS),
			'lifecycle'      => 'registered',
		];
		if ($itemId !== null) {
			$request['agendaItem'] = $itemId;
		}

		if ($late === true) {
			$request['registeredAfterDeadline'] = true;
		}

		return $this->write(request: $request);
	}//end register()

	/**
	 * Approve a registered request with a speaking time and a place in the order.
	 *
	 * @param string   $requestId The speaking request.
	 * @param int|null $minutes   The speaking time, or null for the policy's default.
	 * @param int|null $position  The order, or null for the next free place.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When it is not registered.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
	 */
	public function approve(string $requestId, ?int $minutes=null, ?int $position=null): array {
		$request = $this->requireRegistered(requestId: $requestId);
		if (($minutes !== null && $minutes < 1) || ($position !== null && $position < 1)) {
			throw new SpeakingRequestRefusedException(message: 'Speaking time and order must be at least 1.', status: 400);
		}

		$policy = $this->findPolicy(bodyId: (string)($request['governanceBody'] ?? ''));

		$request['lifecycle']       = 'approved';
		$request['allottedMinutes'] = ($minutes ?? $this->defaultMinutes(policy: $policy));
		$request['position']        = ($position ?? $this->nextPosition(request: $request));

		return $this->write(request: $request);
	}//end approve()

	/**
	 * Reject a registered request with a reason.
	 *
	 * @param string $requestId The speaking request.
	 * @param string $reason    Why it is rejected.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When the reason is empty or it is not registered.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
	 */
	public function reject(string $requestId, string $reason): array {
		if (trim($reason) === '') {
			throw new SpeakingRequestRefusedException(message: 'A rejection needs a reason.', status: 400);
		}

		$request = $this->requireRegistered(requestId: $requestId);
		$request['lifecycle']       = 'rejected';
		$request['rejectionReason'] = trim($reason);

		return $this->write(request: $request);
	}//end reject()

	/**
	 * Move a registered request to another meeting and agenda item; the status stays.
	 *
	 * @param string      $requestId    The speaking request.
	 * @param string      $meetingId    The new meeting.
	 * @param string|null $agendaItemId The new agenda item, or null for the meeting as a whole.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When it is not registered or the target is not usable.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
	 */
	public function refer(string $requestId, string $meetingId, ?string $agendaItemId=null): array {
		$request   = $this->requireRegistered(requestId: $requestId);
		$meetingId = trim($meetingId);
		$meeting   = $this->requireMeeting(meetingId: $meetingId);
		$bodyId    = $this->bodyOf(meeting: $meeting, supplied: (string)($request['governanceBody'] ?? ''));
		$policy    = $this->policyOf(bodyId: $bodyId);
		$itemId    = $this->checkAgendaItem(policy: $policy, meetingId: $meetingId, itemId: trim((string)$agendaItemId));

		$request['meeting']        = $meetingId;
		$request['governanceBody'] = $bodyId;
		unset($request['agendaItem']);
		if ($itemId !== null) {
			$request['agendaItem'] = $itemId;
		}

		return $this->write(request: $request);
	}//end refer()

	/**
	 * The meeting, or a 404.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When it does not exist.
	 */
	private function requireMeeting(string $meetingId): array {
		$meeting = $this->read(schema: 'meeting', id: $meetingId);
		if ($meeting === null) {
			throw new SpeakingRequestRefusedException(message: 'Meeting not found.', status: 404);
		}

		return $meeting;
	}//end requireMeeting()

	/**
	 * The body whose policy applies: the meeting's own, never one the caller names instead.
	 *
	 * @param array<string, mixed> $meeting  The meeting.
	 * @param string               $supplied The body the caller named, if any.
	 *
	 * @return string
	 *
	 * @throws SpeakingRequestRefusedException When no body is known or the caller names another.
	 */
	private function bodyOf(array $meeting, string $supplied): string {
		$own = trim((string)($meeting['governanceBody'] ?? ''));
		if ($own !== '' && $supplied !== '' && $own !== $supplied) {
			throw new SpeakingRequestRefusedException(message: 'The meeting belongs to another body.', status: 400);
		}

		$body = $supplied;
		if ($own !== '') {
			$body = $own;
		}

		if ($body === '') {
			throw new SpeakingRequestRefusedException(message: 'A speaking request needs the body whose meeting it is.', status: 400);
		}

		return $body;
	}//end bodyOf()

	/**
	 * The body's speaking policy when it offers speaking rights.
	 *
	 * @param string $bodyId The body.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When the body has no policy or does not offer speaking rights.
	 */
	private function policyOf(string $bodyId): array {
		$policy = $this->findPolicy(bodyId: $bodyId);
		if ($policy === null || ($policy['enabled'] ?? false) !== true) {
			throw new SpeakingRequestRefusedException(
				message: 'This body does not offer the public a chance to speak at its meetings.',
				status: 409
			);
		}

		return $policy;
	}//end policyOf()

	/**
	 * The body's speaking policy, enabled or not.
	 *
	 * @param string $bodyId The body.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findPolicy(string $bodyId): ?array {
		if ($bodyId === '') {
			return null;
		}

		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => self::POLICY, 'governanceBody' => $bodyId], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		if ($rows === []) {
			return null;
		}

		$policy = $rows[0]->jsonSerialize();
		unset($policy['@self']);
		return $policy;
	}//end findPolicy()

	/**
	 * The agenda item the request names, checked against the policy's level.
	 *
	 * @param array<string, mixed> $policy    The speaking policy.
	 * @param string               $meetingId The meeting.
	 * @param string               $itemId    The agenda item, or ''.
	 *
	 * @return string|null The agenda item, or null for meeting-level speaking.
	 *
	 * @throws SpeakingRequestRefusedException When the item is missing, not allowed or of another meeting.
	 */
	private function checkAgendaItem(array $policy, string $meetingId, string $itemId): ?string {
		if (($policy['level'] ?? 'agenda-item') === 'meeting') {
			if ($itemId !== '') {
				throw new SpeakingRequestRefusedException(
					message: 'This body takes requests to speak at the meeting as a whole, not on one agenda item.',
					status: 400
				);
			}

			return null;
		}

		if ($itemId === '') {
			throw new SpeakingRequestRefusedException(message: 'This body takes requests to speak on one agenda item; name the item.', status: 400);
		}

		$item = $this->read(schema: 'agenda-item', id: $itemId);
		if ($item === null || (string)($item['meeting'] ?? '') !== $meetingId) {
			throw new SpeakingRequestRefusedException(message: 'The agenda item is not on this meeting\'s agenda.', status: 400);
		}

		return $itemId;
	}//end checkAgendaItem()

	/**
	 * Whether the registration deadline (meeting start minus the policy's hours) has passed.
	 *
	 * A meeting without a start date has no deadline yet.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 * @param array<string, mixed> $policy  The speaking policy.
	 *
	 * @return bool
	 */
	private function deadlinePassed(array $meeting, array $policy): bool {
		$start = trim((string)($meeting['scheduledDate'] ?? ''));
		if ($start === '') {
			return false;
		}

		try {
			$deadline = (new DateTimeImmutable($start))->modify(sprintf('-%d hours', $this->deadlineHours(policy: $policy)));
		} catch (Exception $e) {
			return false;
		}

		return $this->time->getTime() > $deadline->getTimestamp();
	}//end deadlinePassed()

	/**
	 * The policy's registration deadline in hours before the meeting.
	 *
	 * @param array<string, mixed> $policy The speaking policy.
	 *
	 * @return int
	 */
	private function deadlineHours(array $policy): int {
		return max(0, (int)($policy['registrationDeadlineHours'] ?? 24));
	}//end deadlineHours()

	/**
	 * The policy's default speaking time.
	 *
	 * @param array<string, mixed>|null $policy The speaking policy.
	 *
	 * @return int
	 */
	private function defaultMinutes(?array $policy): int {
		return max(1, (int)(($policy ?? [])['defaultSpeakingMinutes'] ?? 5));
	}//end defaultMinutes()

	/**
	 * The next free place among the approved speakers on the same item, or meeting.
	 *
	 * @param array<string, mixed> $request The request being approved.
	 *
	 * @return int
	 */
	private function nextPosition(array $request): int {
		$filters = ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'meeting' => (string)$request['meeting']];
		if (isset($request['agendaItem']) === true && $request['agendaItem'] !== '') {
			$filters['agendaItem'] = (string)$request['agendaItem'];
		}

		$highest = 0;
		foreach ($this->objectService->findAll(config: ['filters' => $filters], _rbac: false, _multitenancy: false) as $row) {
			$other = $row->jsonSerialize();
			if (in_array(($other['lifecycle'] ?? ''), ['approved', 'spoke', 'no-show'], true) === true) {
				$highest = max($highest, (int)($other['position'] ?? 0));
			}
		}

		return $highest + 1;
	}//end nextPosition()

	/**
	 * A registered request, or a refusal naming why it cannot be moderated.
	 *
	 * @param string $requestId The speaking request.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When it does not exist or is no longer registered.
	 */
	private function requireRegistered(string $requestId): array {
		$request = $this->read(schema: self::SCHEMA, id: $requestId);
		if ($request === null) {
			throw new SpeakingRequestRefusedException(message: 'Speaking request not found.', status: 404);
		}

		if (($request['lifecycle'] ?? '') !== 'registered') {
			throw new SpeakingRequestRefusedException(message: 'Only a registered speaking request can be approved, rejected or referred.', status: 409);
		}

		return ['id' => $requestId] + $request;
	}//end requireRegistered()

	/**
	 * Only the listed keys of an object-shaped input.
	 *
	 * @param mixed              $value The input.
	 * @param array<int, string> $keys  The keys to keep.
	 *
	 * @return array<string, mixed>
	 */
	private function pick(mixed $value, array $keys): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_intersect_key($value, array_flip($keys));
	}//end pick()

	/**
	 * Read one object in system context.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();
		unset($data['id'], $data['@self']);
		return $data;
	}//end read()

	/**
	 * Write the whole request in system context, so every field is carried forward.
	 *
	 * @param array<string, mixed> $request The complete request, with its id when it exists.
	 *
	 * @return array<string, mixed> The stored request, with its id.
	 */
	private function write(array $request): array {
		$uuid = null;
		if (isset($request['id']) === true && $request['id'] !== '') {
			$uuid = (string)$request['id'];
		}

		unset($request['id'], $request['@self']);
		$saved = $this->objectService->saveObject(
			object: $request,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end write()
}//end class
