<?php

/**
 * Decidiq Minutes Generation Service
 *
 * Service for generating initial minutes drafts from linked meeting data.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Exception\MissingRelationException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stateless service that generates an initial Dutch minutes draft from linked meeting data.
 *
 * Fetches the linked Meeting's AgendaItems, Motions, VotingRounds, and Decisions via
 * OpenRegister ObjectService and renders them into a structured Dutch text template.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MinutesGenerationService {

	/**
	 * Allowed lifecycle transitions: current state → next state.
	 *
	 * Only sequential single-step transitions are permitted to ensure
	 * proper workflow enforcement (OWASP A04 — Insecure Design).
	 *
	 * @var array<string,string>
	 */
	private const LIFECYCLE_TRANSITIONS = [
		'draft' => 'review',
		'review' => 'approved',
		'approved' => 'signed',
		'signed' => 'published',
	];

	/**
	 * The page size of a related-object fetch.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 100;

	/**
	 * The related objects a draft gathers, in fetch order: part => [schema, extra filters].
	 * ADR-005: motions are `decision` objects selected by the decisionType
	 * discriminator; the `motion` schema no longer exists.
	 *
	 * @var array<string,array{0: string, 1: array<string,string>}>
	 */
	private const RELATED_PARTS = [
		'agendaItems' => ['agenda-item', []],
		'motions' => ['decision', ['decisionType' => 'motion']],
		'votingRounds' => ['voting-round', []],
		'decisions' => ['decision', []],
	];

	/**
	 * Loads the minutes and their linked meeting under the caller's ACL.
	 *
	 * @var MinutesRecordReader
	 */
	private readonly MinutesRecordReader $reader;

	/**
	 * Constructor for MinutesGenerationService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param MinutesDraftRenderer $renderer Renders the gathered data into the Dutch template
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	public function __construct(
		private LoggerInterface $logger,
		private MinutesDraftRenderer $renderer,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->reader = new MinutesRecordReader(objectService: $objectService, logger: $logger);
	}//end __construct()

	/**
	 * Generate an initial minutes draft for the given minutes record.
	 *
	 * Fetches the Minutes object, resolves its linked Meeting, and retrieves the
	 * Meeting's AgendaItems (sorted by orderNumber), Motions, VotingRounds, and
	 * Decisions. Renders these into a structured Dutch text template.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 *
	 * @throws InvalidArgumentException When the Minutes object cannot be found
	 * @throws RuntimeException When OpenRegister is not available
	 *
	 * @return string The generated Dutch minutes text
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	public function generateDraft(string $minutesId): string {
		// The reader applies OpenRegister's ACL: a caller without read access gets null.
		$minutes = $this->reader->find(minutesId: $minutesId);
		if ($minutes === null) {
			throw new InvalidArgumentException(
				sprintf('Minutes object with id "%s" not found.', $minutesId)
			);
		}

		$meeting = $this->requireLinkedMeeting(minutesId: $minutesId, minutes: $minutes);

		// The getObject() data carries the properties only, so the id falls back to the minutes' reference.
		$meetingId = (string)($meeting['id'] ?? $this->meetingRefId(minutes: $minutes));

		return $this->renderer->render(
			minutes: $minutes,
			parts: (['meeting' => $meeting] + $this->meetingParts(meetingId: $meetingId))
		);

	}//end generateDraft()

	/**
	 * The meeting the minutes link to.
	 *
	 * @param string              $minutesId UUID of the Minutes object
	 * @param array<string,mixed> $minutes   The minutes data
	 *
	 * @throws MissingRelationException When the minutes link no meeting that can be read
	 *
	 * @return array<string,mixed> The meeting data
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function requireLinkedMeeting(string $minutesId, array $minutes): array {
		$meeting = $this->reader->linkedMeeting(
			minutes: $minutes,
			failureLog: 'Decidiq: Failed to fetch linked Meeting for minutes draft generation'
		);

		if ($meeting === null) {
			throw new MissingRelationException(
				message: sprintf('No linked Meeting found for Minutes "%s". Please link a Meeting before generating a draft.', $minutesId)
			);
		}

		return $meeting;

	}//end requireLinkedMeeting()

	/**
	 * The meeting's agenda items (sorted by orderNumber), motions, voting
	 * rounds, decisions and attendance, as the renderer takes them.
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function meetingParts(string $meetingId): array {
		$parts = [];
		foreach (self::RELATED_PARTS as $part => $query) {
			$parts[$part] = $this->fetchRelatedObjects(meetingId: $meetingId, query: $query);
		}

		usort(
			$parts['agendaItems'],
			static function (array $a, array $b): int {
				return ($a['orderNumber'] ?? 0) <=> ($b['orderNumber'] ?? 0);
			}
		);

		$parts['attendance'] = $this->attendance(meetingId: $meetingId);
		return $parts;

	}//end meetingParts()

	/**
	 * The meeting's attendance as names with status, from its
	 * meeting-attendance records (meeting-attendance-per-meeting).
	 *
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<int,array{name:string,status:string}>
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
	 */
	private function attendance(string $meetingId): array {
		$statuses = (new MeetingAttendanceReader(objectService: $this->objectService, logger: $this->logger))->statusesFor(meetingId: $meetingId);
		if ($statuses === []) {
			return [];
		}

		return self::attendanceRows(statuses: $statuses, names: $this->participantNames());

	}//end attendance()

	/**
	 * One row per recorded status: the participant's name, or its id when it has none.
	 *
	 * @param array<string,string> $statuses The status per participant id
	 * @param array<string,string> $names    The display name per participant id
	 *
	 * @return array<int,array{name:string,status:string}>
	 */
	private static function attendanceRows(array $statuses, array $names): array {
		$rows = [];
		foreach ($statuses as $participantId => $status) {
			$name = ($names[$participantId] ?? '');
			if ($name === '') {
				$name = $participantId;
			}

			$rows[] = ['name' => $name, 'status' => $status];
		}

		return $rows;
	}//end attendanceRows()

	/**
	 * Every participant's display name by id; none when they cannot be read.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
	 */
	private function participantNames(): array {
		try {
			$participants = $this->objectService->findAll(['filters' => ['register' => 'decidiq', 'schema' => 'participant'], 'limit' => 1000]);
		} catch (Throwable) {
			$participants = [];
		}

		$names = [];
		foreach ($participants as $participant) {
			$data = (array)$participant->jsonSerialize();
			$names[(string)($data['id'] ?? '')] = (string)($data['displayName'] ?? '');
		}

		return $names;

	}//end participantNames()

	/**
	 * The meeting id the minutes reference, bare or expanded.
	 *
	 * @param array<string,mixed> $minutes The minutes data
	 *
	 * @return string
	 */
	private function meetingRefId(array $minutes): string {
		$ref = ($minutes['meeting'] ?? $minutes['relations']['meeting'] ?? '');
		if (is_array($ref) === true) {
			$ref = ($ref['id'] ?? $ref['uuid'] ?? '');
		}

		return (string)$ref;

	}//end meetingRefId()

	/**
	 * Transition a Minutes object to the next lifecycle state (server-side enforcement).
	 *
	 * Validates that the requested transition follows the allowed sequence
	 * (draft → review → approved → signed → published). Populates server-side
	 * fields: approvedAt for the "approved" transition and signedBy (with the
	 * authenticated user's display name) for the "approved" and "signed" transitions.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 * @param string $newLifecycle The target lifecycle state
	 * @param string $displayName Display name of the authenticated user (from server session)
	 *
	 * @throws InvalidArgumentException When the Minutes object is not found or the transition is invalid
	 * @throws RuntimeException When OpenRegister is not available
	 *
	 * @return array<string,mixed> The updated Minutes object data
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	public function transition(string $minutesId, string $newLifecycle, string $displayName): array {
		$objectService = $this->getObjectService();

		// Callers without read access to this object get MissingObjectException (OWASP A01).
		$minutes = $this->reader->require(minutesId: $minutesId);

		$currentLifecycle = $minutes['lifecycle'] ?? 'draft';

		if ((self::LIFECYCLE_TRANSITIONS[$currentLifecycle] ?? null) !== $newLifecycle) {
			throw new InvalidArgumentException(
				sprintf(
					'Invalid lifecycle transition: "%s" → "%s". Expected next state: "%s".',
					$currentLifecycle,
					$newLifecycle,
					self::LIFECYCLE_TRANSITIONS[$currentLifecycle] ?? 'none'
				),
				422
			);
		}

		$updated = array_merge($minutes, ['lifecycle' => $newLifecycle]);

		if ($newLifecycle === 'approved') {
			$updated['approvedAt'] = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
		}

		if (in_array($newLifecycle, ['approved', 'signed'], true) === true) {
			$updated['signedBy'] = $this->withSigner(minutes: $minutes, displayName: $displayName);
		}

		// Named arguments: the positional form ($object, 'decidesk', 'minutes', $id)
		// silently bound the register string to ObjectService::saveObject()'s second
		// parameter ($extend) — a latent pre-existing defect fixed here.
		$saved = $objectService->saveObject(object: $updated, register: 'decidiq', schema: 'minutes', uuid: $minutesId);

		return $this->normaliseSaved(saved: $saved, fallback: $updated);
	}//end transition()

	/**
	 * Append the signing user to the Minutes signedBy list, without duplicates.
	 *
	 * @param array<string,mixed> $minutes The current Minutes object data
	 * @param string $displayName Display name of the authenticated user
	 *
	 * @return array<int,string> The signedBy list including the signer
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function withSigner(array $minutes, string $displayName): array {
		$signers = [];
		if (is_array($minutes['signedBy'] ?? null) === true) {
			$signers = $minutes['signedBy'];
		}

		if (in_array($displayName, $signers, true) === false) {
			$signers[] = $displayName;
		}

		return $signers;
	}//end withSigner()

	/**
	 * Normalise whatever OpenRegister returned from saveObject() to an array.
	 *
	 * @param mixed $saved The saveObject() return value
	 * @param array<string,mixed> $fallback The locally-updated payload to fall back on
	 *
	 * @return array<string,mixed> The persisted object data
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function normaliseSaved(mixed $saved, array $fallback): array {
		if ($saved instanceof \stdClass === true || is_array($saved) === true) {
			return (array)$saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
			return (array)$saved->getObject();
		}

		return $fallback;
	}//end normaliseSaved()

	/**
	 * Reject Minutes in review back to draft with a mandatory comment.
	 *
	 * The forward LIFECYCLE_TRANSITIONS map stays intact (signing/eIDAS flows
	 * depend on it); rejection is the single guarded backward step the spec's
	 * review loop requires: only `review` → `draft` is possible, the comment is
	 * mandatory, and the rejection is recorded in `reviewComments` with
	 * server-side author attribution so client-side forgery is impossible.
	 *
	 * @param string $minutesId UUID of the Minutes object
	 * @param string $comment The mandatory rejection comment
	 * @param string $userId Nextcloud UID of the rejecting user (from server session)
	 *
	 * @throws InvalidArgumentException When the Minutes object is not found (MissingObjectException), the comment is empty or lifecycle is not review
	 * @throws RuntimeException When OpenRegister is not available
	 *
	 * @return array<string,mixed> The updated Minutes object data
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function reject(string $minutesId, string $comment, string $userId): array {
		if (trim($comment) === '') {
			throw new InvalidArgumentException('A rejection comment is required.', 422);
		}

		$objectService = $this->getObjectService();

		// Callers without read access to this object get MissingObjectException (OWASP A01).
		$minutes = $this->reader->require(minutesId: $minutesId);

		if (($minutes['lifecycle'] ?? 'draft') !== 'review') {
			throw new InvalidArgumentException(
				'Only minutes in the "review" state can be rejected.',
				422
			);
		}

		$reviewComments = [];
		if (is_array($minutes['reviewComments'] ?? null) === true) {
			$reviewComments = $minutes['reviewComments'];
		}

		$reviewComments[] = [
			'action' => 'rejected',
			'comment' => trim($comment),
			'author' => $userId,
			'createdAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];

		$updated = array_merge(
			$minutes,
			[
				'lifecycle' => 'draft',
				'reviewComments' => $reviewComments,
			]
		);

		$saved = $objectService->saveObject(object: $updated, register: 'decidiq', schema: 'minutes', uuid: $minutesId);

		return $this->normaliseSaved(saved: $saved, fallback: $updated);
	}//end reject()

	/**
	 * Fetch ALL objects of a given type linked to a meeting using offset-based pagination.
	 *
	 * Iterates pages of 100 items until a short page is returned, preventing silent
	 * truncation for governance bodies with more than 100 items per meeting.
	 *
	 * @param string                                 $meetingId The meeting UUID for filtering
	 * @param array{0: string, 1: array<string,mixed>} $query   The schema slug and any additional filters
	 *
	 * @return array<int,array<string,mixed>> Array of object data arrays
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function fetchRelatedObjects(string $meetingId, array $query): array {
		if ($meetingId === '') {
			return [];
		}

		$filters = $this->relatedFilters(meetingId: $meetingId, query: $query);
		$result = [];
		do {
			// Only full pages continue the loop, so the next offset is the count read so far.
			$page = $this->fetchPage(filters: $filters, offset: count($result));
			$result = array_merge($result, ($page ?? []));
			$full = (is_array($page) === true && count($page) === self::PAGE_SIZE);
		} while ($full === true);

		return $result;
	}//end fetchRelatedObjects()

	/**
	 * Point the object service at a related schema and build its filters.
	 *
	 * @param string                                 $meetingId The meeting UUID for filtering
	 * @param array{0: string, 1: array<string,mixed>} $query   The schema slug and any additional filters
	 *
	 * @return array<string,mixed> The findAll filters
	 */
	private function relatedFilters(string $meetingId, array $query): array {
		[$schema, $extraFilters] = $query;
		$this->objectService->setRegister('decidiq');
		$this->objectService->setSchema($schema);

		return array_merge($extraFilters, ['register' => 'decidiq', 'schema' => $schema, '_relations.meeting' => $meetingId]);
	}//end relatedFilters()

	/**
	 * One page of related objects, as data arrays.
	 *
	 * @param array<string,mixed> $filters The findAll filters
	 * @param int                 $offset  The page offset
	 *
	 * @return array<int,array<string,mixed>>|null The page, or null when it cannot be read
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function fetchPage(array $filters, int $offset): ?array {
		try {
			$entities = $this->objectService->findAll(['filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => $offset]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: Failed to fetch related objects for minutes draft',
				['schema' => $filters['schema'], 'meetingId' => $filters['_relations.meeting'], 'offset' => $offset, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return self::pageObjects(entities: $entities);
	}//end fetchPage()

	/**
	 * The data of every entity or data array on a page; anything else is skipped.
	 *
	 * @param iterable<mixed> $entities The findAll() results
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function pageObjects(iterable $entities): array {
		$page = [];
		foreach ($entities as $entity) {
			if (method_exists($entity, 'getObject') === true) {
				$page[] = $entity->getObject();
			} elseif (is_array($entity) === true) {
				$page[] = $entity;
			}
		}

		return $page;
	}//end pageObjects()

	/**
	 * Lazy-load the OpenRegister ObjectService from the container.
	 *
	 * @return object The OpenRegister ObjectService instance
	 *
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable.
		return $this->objectService;
	}//end getObjectService()
}//end class
