<?php

/**
 * Unit tests for the meeting stage buttons and the cost stamped on close.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Lifecycle\MeetingTransitionGuard;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\MeetingCostService;
use OCA\Decidiq\Service\MeetingService;
use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Service\WorkflowService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The chair moves a meeting through its stages; closing records its cost.
 *
 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
 */
class MeetingStageButtonsTest extends TestCase {

	/**
	 * The governance body of the closed meeting.
	 *
	 * @var string
	 */
	private const BODY = '0b6c1d2e-3f40-4a51-8b62-7c83d94ea5f6';

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Workflow rules double.
	 *
	 * @var WorkflowService&MockObject
	 */
	private WorkflowService&MockObject $workflowService;

	/**
	 * Chair scope double.
	 *
	 * @var GovernanceScopeGuard&MockObject
	 */
	private GovernanceScopeGuard&MockObject $scopeGuard;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->workflowService = $this->createMock(originalClassName: WorkflowService::class);
		$this->scopeGuard = $this->createMock(originalClassName: GovernanceScopeGuard::class);

	}//end setUp()

	/**
	 * Build the service with the real MeetingCostService.
	 *
	 * @return MeetingService
	 */
	private function service(): MeetingService {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new MeetingService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $logger,
			workflowService: $this->workflowService,
			transitionGuard: $this->createMock(originalClassName: MeetingTransitionGuard::class),
			meetingCostService: new MeetingCostService(logger: $logger, objectService: $this->objectService),
			scopeGuard: $this->scopeGuard,
			objectService: $this->objectService,
		);

	}//end service()

	/**
	 * An entity double answering with the given object.
	 *
	 * @param array<string, mixed> $data The object
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;

	}//end entity()

	/**
	 * The chair of a scheduled meeting is offered open and close.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
	 */
	public function testTheChairOfAScheduledMeetingMayOpenOrCloseIt(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['lifecycle' => 'scheduled', 'domain' => 'legislative', 'governanceBody' => 'body-1'])
		);
		$this->workflowService->method('isTransitionAllowed')->willReturn(true);
		$this->workflowService->method('requiresChairAuthorization')->willReturn(true);
		$this->scopeGuard->method('isInBodyScope')
			->with('chair-uid', 'body-1', GovernanceScopeGuard::SCOPE_CHAIR)
			->willReturn(true);

		$answer = $this->service()->availableActionsFor(meetingId: 'm-1', userId: 'chair-uid');

		self::assertSame(['lifecycle' => 'scheduled', 'actions' => ['open', 'close']], $answer);

	}//end testTheChairOfAScheduledMeetingMayOpenOrCloseIt()

	/**
	 * A chair-only step is left out for a caller outside the body's chair scope,
	 * and a step the domain forbids is left out for everyone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
	 */
	public function testChairOnlyAndForbiddenStepsAreLeftOut(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['lifecycle' => 'opened', 'domain' => 'corporate', 'governanceBody' => 'body-1'])
		);
		$this->workflowService->method('isTransitionAllowed')->willReturnCallback(
			static fn (string $domain, string $fromState, string $toState): bool => $toState !== 'paused'
		);
		$this->workflowService->method('requiresChairAuthorization')->willReturnCallback(
			static fn (string $domain, string $from, string $to): bool => $to === 'closed'
		);
		$this->scopeGuard->method('isInBodyScope')->willReturn(false);

		$answer = $this->service()->availableActionsFor(meetingId: 'm-1', userId: 'secretary-uid');

		self::assertSame(['lifecycle' => 'opened', 'actions' => ['adjourn']], $answer);

	}//end testChairOnlyAndForbiddenStepsAreLeftOut()

	/**
	 * A meeting the caller cannot read answers null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
	 */
	public function testAnUnknownMeetingAnswersNull(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('gone'));

		self::assertNull($this->service()->availableActionsFor(meetingId: 'm-x', userId: 'chair-uid'));

	}//end testAnUnknownMeetingAnswersNull()

	/**
	 * A new meeting starts as draft: Meeting.lifecycle carries that default in
	 * the descriptor OpenRegister imports, so a create form without the field
	 * still yields a valid meeting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
	 */
	public function testANewMeetingStartsAsDraft(): void {
		$meeting = SettingsService::shippedRegisterDescriptor()['components']['schemas']['Meeting'];

		self::assertSame('draft', ($meeting['properties']['lifecycle']['default'] ?? null));

	}//end testANewMeetingStartsAsDraft()

	/**
	 * Scenario: an opened meeting with 10 attendees ran 2 hours and the body
	 * has an hourly rate; the chair closes it and its cost is recorded.
	 *
	 * The real MeetingCostService runs. Participant declares no `meeting`
	 * property, so the attendee count is the body's participants marked
	 * present; the double ignores a filter on an undeclared property and
	 * honours one on `governanceBody`, which Participant declares.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 */
	public function testClosingATwoHourMeetingOfTenAttendeesRecordsItsCost(): void {
		$openedAt = (new DateTimeImmutable('-2 hours'))->format(DateTimeInterface::ATOM);
		$meeting = [
			'title' => 'Raadsvergadering',
			'meetingType' => 'regular',
			'scheduledDate' => '2026-09-29T19:30:00+02:00',
			'meetingMode' => 'in-person',
			'lifecycle' => 'opened',
			'governanceBody' => self::BODY,
			'openedAt' => $openedAt,
		];

		$participants = [];
		for ($i = 0; $i < 12; $i++) {
			$participants[] = $this->entity([
				'displayName' => 'Raadslid ' . $i,
				'governanceBody' => self::BODY,
				'attendanceStatus' => ($i < 10 ? 'present' : 'absent'),
			]);
		}

		for ($i = 0; $i < 3; $i++) {
			$participants[] = $this->entity([
				'displayName' => 'Commissielid ' . $i,
				'governanceBody' => 'body-2',
				'attendanceStatus' => 'present',
			]);
		}

		$this->objectService->method('find')->willReturnCallback(
			fn (string|int $id): ObjectEntity => match ((string)$id) {
				'm-1' => $this->entity($meeting),
				self::BODY => $this->entity(['name' => 'Gemeenteraad', 'hourlyRate' => 50]),
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($participants): array {
				$body = ($config['filters']['governanceBody'] ?? null);
				if ($body === null) {
					return $participants;
				}

				return array_values(
					array_filter(
						$participants,
						static fn ($p): bool => $p->getObject()['governanceBody'] === $body
					)
				);
			}
		);
		$this->workflowService->method('isTransitionAllowed')->willReturn(true);
		$this->workflowService->method('requiresChairAuthorization')->willReturn(false);

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				return $this->entity($object);
			}
		);

		$result = $this->service()->transition(meetingId: 'm-1', action: 'close', currentUserId: 'chair-uid');

		self::assertTrue($result['success'], $result['message']);
		self::assertSame('closed', $saved['lifecycle']);
		// 2 hours x 10 present attendees x EUR 50 = EUR 1000 (a second of drift allowed).
		self::assertEqualsWithDelta(1000.0, $saved['meetingCost'], 0.5);
		self::assertEqualsWithDelta(1000.0, $result['meeting']['meetingCost'], 0.5);
		$this->assertValidMeeting($saved);

	}//end testClosingATwoHourMeetingOfTenAttendeesRecordsItsCost()

	/**
	 * When nobody's attendance was taken, the body's roster is the attendance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-002-closing-a-meeting-records-its-cost
	 */
	public function testWithoutAttendanceTheRosterCounts(): void {
		$participants = [];
		for ($i = 0; $i < 4; $i++) {
			$participants[] = $this->entity(['governanceBody' => 'body-1']);
		}

		$this->objectService->method('find')->willReturn($this->entity(['hourlyRate' => 100]));
		$this->objectService->method('findAll')->willReturn($participants);

		$cost = (new MeetingCostService(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $this->objectService
		))->calculateForMeeting(
			meetingId: 'm-1',
			meeting: [
				'governanceBody' => 'body-1',
				'openedAt' => '2026-09-29T19:00:00+00:00',
				'closedAt' => '2026-09-29T20:30:00+00:00',
			]
		);

		// 1.5 hours x 4 members x EUR 100.
		self::assertSame(600.0, $cost);

	}//end testWithoutAttendanceTheRosterCounts()

	/**
	 * The saved meeting validates against the merged Meeting schema.
	 *
	 * @param array<string, mixed> $data The saved object
	 *
	 * @return void
	 */
	private function assertValidMeeting(array $data): void {
		$schema = SettingsService::shippedRegisterDescriptor()['components']['schemas']['Meeting'];
		// OpenRegister resolves a `$ref` to another schema as an object relation;
		// the stored value is the uuid string the property's own type declares.
		$properties = array_map(
			static function (array $property): array {
				unset($property['$ref']);
				return $property;
			},
			$schema['properties']
		);

		$result = (new \Opis\JsonSchema\Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode(
				(string)json_encode([
					'type' => 'object',
					'properties' => $properties,
					'required' => $schema['required'],
					'additionalProperties' => false,
				])
			)
		);
		$this->assertTrue($result->isValid(), 'The saved meeting must validate: ' . json_encode($result->error()?->args()));

	}//end assertValidMeeting()
}//end class
