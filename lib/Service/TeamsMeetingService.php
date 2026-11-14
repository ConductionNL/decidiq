<?php

/**
 * Decidiq TeamsMeetingService
 *
 * Creates the Microsoft Teams meeting of a digital or hybrid meeting through
 * integriq, and only through integriq. The app declares the `microsoft-365`
 * connection (lib/Settings/connections.json); an administrator links an
 * integriq source to it, and integriq holds the adapter for Microsoft 365.
 * decidiq names the intent: one POST to /online-meetings on the linked source
 * with the meeting's title, start and end. The answer's join address is stored
 * on the meeting as `teamsMeeting`, apart from `location`, so a hybrid
 * meeting keeps the room's address there.
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
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Decidiq\Exception\TeamsMeetingRefusedException;
use OCA\Decidiq\Kernel\Contracts\TeamsMeetingServiceInterface;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The Teams meeting on a meeting: whether one can be created, and creating it.
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 */
class TeamsMeetingService implements TeamsMeetingServiceInterface {
	/**
	 * The connection key decidiq declares.
	 */
	public const CONNECTION_KEY = 'microsoft-365';

	/**
	 * The refusal while no source is linked.
	 */
	public const NOT_CONNECTED = 'No Microsoft 365 service is connected';

	/**
	 * Every Teams join address starts with this; the register declares the same pattern.
	 */
	public const JOIN_PREFIX = 'https://teams.microsoft.com/';

	/**
	 * The length of a meeting without an end, as the calendar feed reads it.
	 */
	private const FALLBACK_MINUTES = 120;

	/**
	 * The meeting schema.
	 */
	private const SCHEMA = 'meeting';

	/**
	 * Finds the linked source and reads call statuses.
	 *
	 * @var IntegriqConnection
	 */
	private readonly IntegriqConnection $connection;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service (meetings, integriq's connection rows and sources).
	 * @param ContainerInterface     $container     DI container (integriq's call service, lazily).
	 * @param SigningAnswer          $answers       Reads the body of an integriq call answer.
	 * @param IUserSession           $userSession   Who creates the Teams meeting.
	 * @param ITimeFactory           $time          When it is created.
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly SigningAnswer $answers,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {
		$this->connection = new IntegriqConnection(objectService: $objectService);
	}//end __construct()

	/**
	 * Whether Microsoft 365 is connected, and the meeting's Teams meeting.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 *
	 * @throws TeamsMeetingRefusedException When the meeting does not exist (404).
	 *
	 * @return array{connected: bool, teamsMeeting: array<string, mixed>|null}
	 */
	public function status(string $meetingId): array {
		$meeting = $this->meeting(meetingId: $meetingId);
		$teams   = ($meeting['teamsMeeting'] ?? null);
		if (is_array($teams) === false) {
			$teams = null;
		}

		return [
			'connected' => $this->connection->isLinked(connectionKey: self::CONNECTION_KEY),
			'teamsMeeting' => $teams,
		];
	}//end status()

	/**
	 * Create the Teams meeting for a digital or hybrid meeting and store it on the meeting.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 *
	 * @throws TeamsMeetingRefusedException When the meeting cannot carry one (404, 409) or the service refuses (502).
	 *
	 * @return array<string, mixed> The stored teamsMeeting.
	 */
	public function create(string $meetingId): array {
		$meeting = $this->meeting(meetingId: $meetingId);
		$this->assertCanCarryTeams(meeting: $meeting);

		[$start, $end] = $this->period(meeting: $meeting);
		$answer        = $this->call(
			body: [
				'subject' => (string)($meeting['title'] ?? ''),
				'startDateTime' => $start,
				'endDateTime' => $end,
			]
		);

		$joinUrl = (string)($answer['joinWebUrl'] ?? ($answer['joinUrl'] ?? ''));
		if (str_starts_with($joinUrl, self::JOIN_PREFIX) === false) {
			throw new TeamsMeetingRefusedException(message: 'Microsoft 365 answered without a Teams join address', status: 502);
		}

		$externalId = null;
		if (isset($answer['id']) === true) {
			$externalId = (string)$answer['id'];
		}

		$teams = [
			'joinUrl' => $joinUrl,
			'externalId' => $externalId,
			'createdBy' => $this->author(),
			'createdAt' => $this->time->getDateTime()->format(DATE_ATOM),
		];

		$meeting['teamsMeeting'] = $teams;
		$this->objectService->saveObject(
			object: $meeting,
			register: 'decidiq',
			schema: self::SCHEMA,
			uuid: $meetingId,
			_rbac: false,
			_multitenancy: false
		);

		return $teams;
	}//end create()

	/**
	 * Refuse a meeting that is not digital or hybrid, or that already has a Teams meeting.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 *
	 * @throws TeamsMeetingRefusedException When the meeting cannot carry one (409).
	 *
	 * @return void
	 */
	private function assertCanCarryTeams(array $meeting): void {
		if (in_array(($meeting['meetingMode'] ?? null), ['digital', 'hybrid'], true) === false) {
			throw new TeamsMeetingRefusedException(message: 'Only a digital or hybrid meeting gets a Teams meeting', status: 409);
		}

		if (is_array($meeting['teamsMeeting'] ?? null) === true && (string)($meeting['teamsMeeting']['joinUrl'] ?? '') !== '') {
			throw new TeamsMeetingRefusedException(message: 'This meeting already has a Teams meeting', status: 409);
		}
	}//end assertCanCarryTeams()

	/**
	 * The meeting, without its id and metadata.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @throws TeamsMeetingRefusedException When it does not exist (404).
	 *
	 * @return array<string, mixed>
	 */
	private function meeting(string $meetingId): array {
		$entity = null;
		if ($meetingId !== '') {
			$entity = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		}

		if ($entity === null) {
			throw new TeamsMeetingRefusedException(message: 'Meeting not found', status: 404);
		}

		$data = $entity->jsonSerialize();
		unset($data['id'], $data['@self']);

		return $data;
	}//end meeting()

	/**
	 * The meeting's start and end, an end without one being two hours after the start.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 *
	 * @throws TeamsMeetingRefusedException When the meeting has no valid start (409).
	 *
	 * @return array{0: string, 1: string} Start and end, ISO 8601.
	 */
	private function period(array $meeting): array {
		$start = $this->dateOf(value: (string)($meeting['scheduledDate'] ?? ''));
		if ($start === null) {
			throw new TeamsMeetingRefusedException(message: 'The meeting has no start date', status: 409);
		}

		$end = $this->dateOf(value: (string)($meeting['endDate'] ?? ''));
		if ($end === null || $end <= $start) {
			$end = $start->add(new DateInterval('PT' . self::FALLBACK_MINUTES . 'M'));
		}

		return [$start->format(DATE_ATOM), $end->format(DATE_ATOM)];
	}//end period()

	/**
	 * A date, or null when it is empty or cannot be read.
	 *
	 * @param string $value The date as stored on the meeting.
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 *
	 * @return DateTimeImmutable|null
	 */
	private function dateOf(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end dateOf()

	/**
	 * Ask the linked source for an online meeting.
	 *
	 * @param array<string, mixed> $body The request body.
	 *
	 * @throws TeamsMeetingRefusedException When no source is linked (409) or it refuses (502).
	 *
	 * @return array<string, mixed> The decoded answer.
	 */
	private function call(array $body): array {
		$source = $this->connection->source(connectionKey: self::CONNECTION_KEY);
		$caller = FleetAppId::getService($this->container, 'integriq', 'Service\CallService');
		if ($source === null || $caller === null) {
			throw new TeamsMeetingRefusedException(message: self::NOT_CONNECTED, status: 409);
		}

		try {
			$response = $caller->call(
				source: $source,
				endpoint: '/online-meetings',
				method: 'POST',
				config: ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]
			);
		} catch (Throwable $e) {
			throw new TeamsMeetingRefusedException(message: 'Microsoft 365 could not be reached: ' . $e->getMessage(), status: 502);
		}

		$decoded = $this->decode(response: $response);
		$status = $this->connection->statusOf(response: $response);
		if ($status >= 400) {
			throw new TeamsMeetingRefusedException(message: (string)($decoded['message'] ?? ('Microsoft 365 answered ' . $status)), status: 502);
		}

		return $decoded;
	}//end call()

	/**
	 * The decoded body of an answer, an empty array when it is not a JSON object or list.
	 *
	 * @param mixed $response The answer of the call service.
	 *
	 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
	 *
	 * @return array<string, mixed>
	 */
	private function decode(mixed $response): array {
		$decoded = json_decode($this->answers->body(response: $response), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end decode()

	/**
	 * The name of who creates the Teams meeting.
	 *
	 * @return string|null The display name, or null without a session.
	 */
	private function author(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$name = $user->getDisplayName();
		if ($name === '') {
			$name = $user->getUID();
		}

		return $name;
	}//end author()
}//end class
