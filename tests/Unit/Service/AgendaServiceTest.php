<?php

/**
 * Unit tests for AgendaService.
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
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.5
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AgendaService.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.5
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
 */
class AgendaServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AgendaService
	 */
	private AgendaService $service;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock CalendarEventService.
	 *
	 * @var CalendarEventService&MockObject
	 */
	private CalendarEventService&MockObject $calendarEventService;

	/**
	 * Mock NotificationPreferenceService: the one place a member's delivery
	 * choice is applied (agenda-change-notices-reach-members).
	 *
	 * @var NotificationPreferenceService&MockObject
	 */
	private NotificationPreferenceService&MockObject $preferences;

	/**
	 * Every dispatch() call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $dispatched = [];

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Translations (English, %s filled).
	 *
	 * @var IFactory&MockObject
	 */
	private IFactory&MockObject $l10nFactory;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Set up mocks and service instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->calendarEventService = $this->createMock(CalendarEventService::class);
		$this->preferences = $this->createMock(NotificationPreferenceService::class);
		$this->dispatched = [];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, array|string $params = []): string => vsprintf($text, (array)$params)
		);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->l10nFactory->method('get')->willReturn($l10n);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolver::class);

		$this->service = new AgendaService(
			objectService: $this->objectService,
			calendarEventService: $this->calendarEventService,
			preferences: $this->preferences,
			logger: $this->logger,
			participantResolver: $this->participantResolver,
			l10nFactory: $this->l10nFactory,
		);

	}//end setUp()

	// -----------------------------------------------------------------------
	// publishAgenda tests
	// -----------------------------------------------------------------------

	/**
	 * publishAgenda throws InvalidArgumentException when no items exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testPublishAgendaThrowsWhenNoItems(): void {
		$this->objectService
			->method('findAll')
			->willReturnCallback(function (array $config) {
				if (($config['filters']['schema'] ?? '') === 'agenda-item') {
					return [];
				}

				return [];
			});

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/no agenda items/i');

		$this->service->publishAgenda('meeting-uuid-1');

	}//end testPublishAgendaThrowsWhenNoItems()

	/**
	 * publishAgenda sends notifications only to active participants (leftAt === null).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testPublishAgendaNotifiesOnlyActiveParticipants(): void {
		$meetingId = 'meeting-uuid-1';

		$participants = [
			['displayName' => 'Alice', 'leftAt' => null, 'owner' => 'alice'],
			['displayName' => 'Bob',   'leftAt' => '2025-01-01T00:00:00Z', 'owner' => 'bob'],
			['displayName' => 'Carol', 'leftAt' => null, 'owner' => 'carol'],
		];

		// Meeting entity stub for the #315 full-object read in publishAgenda.
		$meetingEntity = $this->createMock(ObjectEntity::class);
		$meetingEntity->method('jsonSerialize')->willReturn(
			['id' => $meetingId, 'title' => 'Test Meeting', 'lifecycle' => 'scheduled']
		);

		$this->objectService
			->method('find')
			->willReturn($meetingEntity);

		// Agenda-item query returns one item so the not-empty check passes.
		$this->objectService
			->method('findAll')
			->willReturnCallback(function (array $config) {
				if (($config['filters']['schema'] ?? '') === 'agenda-item') {
					return [['id' => 'item-1', 'title' => 'Item 1']];
				}

				return [];
			});

		// Participants now come from ParticipantResolver (canonical path).
		$this->participantResolver
			->method('resolveMeetingParticipants')
			->with($meetingId)
			->willReturn($participants);

		// Only 2 active participants: dispatch() is called exactly 2 times.
		$this->preferences
			->expects($this->exactly(2))
			->method('dispatch')
			->willReturn(1);

		$this->objectService
			->expects($this->atLeastOnce())
			->method('saveObject');

		$this->service->publishAgenda($meetingId);

	}//end testPublishAgendaNotifiesOnlyActiveParticipants()

	// -----------------------------------------------------------------------
	// advanceBobPhase tests
	// -----------------------------------------------------------------------

	/**
	 * advanceBobPhase correctly cycles through the BOB phase sequence.
	 *
	 * Each step must be written through patchObject() with a status-only
	 * payload: a uuid-bearing saveObject() is a full replace that OpenRegister
	 * validates whole, so a partial save 400s on the required fields it omits
	 * (#1104). The assertion therefore pins BOTH the next phase and the patch
	 * path, and saveObject() must never be reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseCyclesThroughPhases(): void {
		$transitions = [
			['from' => 'voorstel',        'to' => 'beeldvorming'],
			['from' => 'beeldvorming',    'to' => 'oordeelsvorming'],
			['from' => 'oordeelsvorming', 'to' => 'besluitvorming'],
			['from' => 'besluitvorming',  'to' => 'completed'],
		];

		foreach ($transitions as $t) {
			$itemId = 'item-' . $t['from'];
			$itemData = ['id' => $itemId, 'itemType' => 'decision', 'status' => $t['from']];

			// A fresh double per iteration so expectations do not accumulate.
			$objectService = $this->createMock(ObjectServiceInterface::class);
			$objectService->method('find')->willReturn($this->entity($itemData));
			$objectService->expects($this->never())->method('saveObject');

			$patches = [];
			$objectService
				->expects($this->once())
				->method('patchObject')
				->willReturnCallback(
					function (string $objectId, array $data) use (&$patches): ObjectEntity {
						$patches[] = ['id' => $objectId, 'data' => $data];
						return $this->entity($data);
					}
				);

			$freshService = new AgendaService(
				objectService: $objectService,
				calendarEventService: $this->calendarEventService,
				preferences: $this->preferences,
				logger: $this->logger,
				participantResolver: $this->participantResolver,
				l10nFactory: $this->l10nFactory,
			);

			$freshService->advanceBobPhase($itemId);

			$this->assertSame(
				[['id' => $itemId, 'data' => ['status' => $t['to']]]],
				$patches,
				"'{$t['from']}' must advance to '{$t['to']}' with a status-only patch"
			);
		}//end foreach

	}//end testAdvanceBobPhaseCyclesThroughPhases()

	/**
	 * advanceBobPhase throws NotFoundException when the item does not exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseThrowsWhenItemNotFound(): void {
		$this->objectService
			->method('find')
			->willReturn(null);

		$this->expectException(\OCA\Decidiq\Exception\NotFoundException::class);

		$this->service->advanceBobPhase('missing-uuid');

	}//end testAdvanceBobPhaseThrowsWhenItemNotFound()

	/**
	 * advanceBobPhase throws for informational items, and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseThrowsForInformationalItem(): void {
		$itemId = 'item-info';
		$itemData = ['id' => $itemId, 'itemType' => 'informational', 'status' => 'beeldvorming'];

		$this->objectService
			->method('find')
			->willReturn($this->entity($itemData));
		$this->objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/informational/i');

		$this->service->advanceBobPhase($itemId);

	}//end testAdvanceBobPhaseThrowsForInformationalItem()

	/**
	 * advanceBobPhase throws when item is already at final phase 'completed'.
	 *
	 * `completed` is the English data value the Dutch `afgerond` was renamed to
	 * (lib/Repair/RenameDutchDecidiqValues.php, `status` map).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testAdvanceBobPhaseThrowsAtFinalPhase(): void {
		$itemId = 'item-final';
		$itemData = ['id' => $itemId, 'itemType' => 'decision', 'status' => 'completed'];

		$this->objectService
			->method('find')
			->willReturn($this->entity($itemData));
		$this->objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/final phase/i');

		$this->service->advanceBobPhase($itemId);

	}//end testAdvanceBobPhaseThrowsAtFinalPhase()

	// -----------------------------------------------------------------------
	// processHamerstukken tests
	// -----------------------------------------------------------------------

	/**
	 * The chair adopts the formalities: every item marked as a formality
	 * (and one still carrying the older `hamerstuk` tag) records "adopted
	 * without debate" and the time. Items that are not formalities, and a
	 * formality already adopted, are left alone. It used to write
	 * `status: completed`, a field AgendaItem does not declare.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 */
	public function testFormalitiesAreAdoptedWithoutDebate(): void {
		$items = [
			['id' => 'item-3', 'title' => 'Item 3', 'isFormality' => true],
			['id' => 'item-2', 'title' => 'Item 2', 'isFormality' => false],
			['id' => 'item-4', 'title' => 'Item 4', 'tags' => ['hamerstuk']],
			['id' => 'item-7', 'title' => 'Item 7', 'isFormality' => true],
			['id' => 'item-8', 'title' => 'Item 8', 'isFormality' => true, 'formalityOutcome' => 'adopted-without-debate'],
		];

		$this->objectService
			->method('findAll')
			->willReturn(array_map(fn (array $item): ObjectEntity => $this->entity($item), $items));
		$this->objectService->expects($this->never())->method('saveObject');

		$patches = $this->capturePatches();

		$adopted = $this->service->processHamerstukken('meeting-uuid-1');

		$this->assertSame(3, $adopted);
		$this->assertSame(['item-3', 'item-4', 'item-7'], array_column($patches->getArrayCopy(), 'id'));
		foreach ($patches as $patch) {
			$this->assertSame('adopted-without-debate', $patch['data']['formalityOutcome']);
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $patch['data']['adoptedAt']);
			$this->assertValidAgendaItemFields($patch['data']);
		}

	}//end testFormalitiesAreAdoptedWithoutDebate()

	/**
	 * The chair or secretary marks an item of the meeting as a formality.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 */
	public function testAnItemOfTheMeetingIsMarkedAsAFormality(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'item-4', 'meeting' => 'meeting-uuid-1']));
		$patches = $this->capturePatches();

		$this->service->setFormality(meetingId: 'meeting-uuid-1', itemId: 'item-4', isFormality: true);

		$this->assertSame([['id' => 'item-4', 'data' => ['isFormality' => true]]], $patches->getArrayCopy());
		$this->assertValidAgendaItemFields($patches[0]['data']);

	}//end testAnItemOfTheMeetingIsMarkedAsAFormality()

	/**
	 * An item of another meeting cannot be marked through this meeting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 */
	public function testAnItemOfAnotherMeetingIsRefused(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'item-4', 'meeting' => 'other-meeting']));
		$this->objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setFormality(meetingId: 'meeting-uuid-1', itemId: 'item-4', isFormality: true);

	}//end testAnItemOfAnotherMeetingIsRefused()

	/**
	 * An adopted formality stays one: it cannot be taken back off.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
	 */
	public function testAnAdoptedFormalityCannotBeUnmarked(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['id' => 'item-4', 'meeting' => 'meeting-uuid-1', 'isFormality' => true, 'formalityOutcome' => 'adopted-without-debate'])
		);
		$this->objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setFormality(meetingId: 'meeting-uuid-1', itemId: 'item-4', isFormality: false);

	}//end testAnAdoptedFormalityCannotBeUnmarked()

	/**
	 * Every key of a patch is declared by the merged AgendaItem schema (base
	 * register plus every register.d fragment) and carries a value it
	 * accepts, checked with the opis validator.
	 *
	 * @param array<string, mixed> $data The patch
	 *
	 * @return void
	 */
	private function assertValidAgendaItemFields(array $data): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		$properties = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $schema) {
				if (($schema['slug'] ?? $name) === 'agenda-item' || $name === 'AgendaItem') {
					$properties = array_replace_recursive($properties, ($schema['properties'] ?? []));
				}
			}
		}

		$result = (new \Opis\JsonSchema\Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'additionalProperties' => false]))
		);
		$this->assertTrue($result->isValid(), 'The patch must validate against AgendaItem: ' . json_encode($result->error()?->args()));

	}//end assertValidAgendaItemFields()

	// -----------------------------------------------------------------------
	// reorderItems tests
	// -----------------------------------------------------------------------

	/**
	 * reorderItems assigns sequential orderNumber values 1..n.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-9.1
	 */
	public function testReorderItemsAssignsSequentialNumbers(): void {
		$meetingId = 'meeting-uuid-1';
		$orderedIds = ['item-c', 'item-a', 'item-b'];

		// Return the meeting's items so all IDs pass the ownership check.
		$this->objectService
			->method('findAll')
			->willReturn(
				[
					$this->entity(['id' => 'item-a']),
					$this->entity(['id' => 'item-b']),
					$this->entity(['id' => 'item-c']),
				]
			);
		$this->objectService->expects($this->never())->method('saveObject');

		$patches = $this->capturePatches();

		$this->service->reorderItems($meetingId, $orderedIds);

		$this->assertSame(
			[
				['id' => 'item-c', 'data' => ['orderNumber' => 1]],
				['id' => 'item-a', 'data' => ['orderNumber' => 2]],
				['id' => 'item-b', 'data' => ['orderNumber' => 3]],
			],
			$patches->getArrayCopy()
		);

	}//end testReorderItemsAssignsSequentialNumbers()

	// -----------------------------------------------------------------------
	// agenda publication state and change notices (#1396)
	// -----------------------------------------------------------------------

	/**
	 * Publishing records version 1 and a snapshot, notifies, and leaves the
	 * meeting lifecycle alone: a published agenda is not a meeting in session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function testPublishAgendaRecordsAVersionAndLeavesTheLifecycleAlone(): void {
		$saved = $this->wirePublishedMeeting(meeting: ['lifecycle' => 'scheduled']);
		$subjects = $this->captureNotifications();

		$this->service->publishAgenda('meeting-uuid-1');

		self::assertCount(1, $saved);
		self::assertSame('scheduled', $saved[0]['lifecycle'], 'Publishing an agenda MUST NOT open the meeting');
		self::assertSame(1, $saved[0]['agendaVersion']);
		self::assertNotEmpty($saved[0]['agendaPublishedAt']);
		self::assertFalse($saved[0]['agendaUnderRevision']);
		self::assertCount(1, $saved[0]['agendaVersions']);
		self::assertSame(['item-1', 'item-2'], array_column($saved[0]['agendaVersions'][0]['items'], 'id'));
		self::assertSame(['alice' => 'agenda_published'], $subjects->getArrayCopy());

	}//end testPublishAgendaRecordsAVersionAndLeavesTheLifecycleAlone()

	/**
	 * Publishing again after a revision tells members the agenda was revised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function testRepublishAfterRevisionNotifiesARevisedAgenda(): void {
		$saved = $this->wirePublishedMeeting(
			meeting: [
				'lifecycle' => 'scheduled',
				'agendaPublishedAt' => '2026-09-01T10:00:00+00:00',
				'agendaVersion' => 1,
				'agendaUnderRevision' => true,
				'agendaVersions' => [['version' => 1, 'publishedAt' => '2026-09-01T10:00:00+00:00', 'items' => []]],
			]
		);
		$subjects = $this->captureNotifications();

		$this->service->publishAgenda('meeting-uuid-1');

		self::assertSame(2, $saved[0]['agendaVersion']);
		self::assertFalse($saved[0]['agendaUnderRevision']);
		self::assertCount(2, $saved[0]['agendaVersions'], 'The earlier version MUST be kept');
		self::assertSame(['alice' => 'agenda_revised'], $subjects->getArrayCopy());

	}//end testRepublishAfterRevisionNotifiesARevisedAgenda()

	/**
	 * Revising tells members and never moves a running meeting back to scheduled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function testReviseAgendaNotifiesAndKeepsTheLifecycle(): void {
		$saved = $this->wirePublishedMeeting(
			meeting: ['lifecycle' => 'opened', 'agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaVersion' => 1]
		);
		$subjects = $this->captureNotifications();

		$this->service->reviseAgenda('meeting-uuid-1');

		self::assertCount(1, $saved);
		self::assertSame('opened', $saved[0]['lifecycle'], 'Revising MUST NOT move a running meeting back to scheduled');
		self::assertTrue($saved[0]['agendaUnderRevision']);
		self::assertSame(['alice' => 'agenda_revision_started'], $subjects->getArrayCopy());

	}//end testReviseAgendaNotifiesAndKeepsTheLifecycle()

	/**
	 * A change to the agenda of a published meeting records a version and notifies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function testAgendaChangeOnAPublishedMeetingNotifiesAndRecordsAVersion(): void {
		$saved = $this->wirePublishedMeeting(
			meeting: ['lifecycle' => 'scheduled', 'agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaVersion' => 1]
		);
		$subjects = $this->captureNotifications();

		$this->service->notifyAgendaChanged('meeting-uuid-1');

		self::assertCount(1, $saved);
		self::assertSame(2, $saved[0]['agendaVersion']);
		self::assertSame('scheduled', $saved[0]['lifecycle']);
		self::assertSame(['alice' => 'agenda_changed'], $subjects->getArrayCopy());

	}//end testAgendaChangeOnAPublishedMeetingNotifiesAndRecordsAVersion()

	/**
	 * Before publication, or while a revision is open, an agenda edit is silent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function testAgendaChangeIsSilentWhenUnpublishedOrUnderRevision(): void {
		foreach ([['lifecycle' => 'scheduled'], ['agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaUnderRevision' => true]] as $meeting) {
			$this->setUp();
			$saved = $this->wirePublishedMeeting(meeting: $meeting);
			$subjects = $this->captureNotifications();

			$this->service->notifyAgendaChanged('meeting-uuid-1');

			self::assertCount(0, $saved);
			self::assertCount(0, $subjects);
		}

	}//end testAgendaChangeIsSilentWhenUnpublishedOrUnderRevision()

	/**
	 * Reordering a published agenda sends one notice per member, not one per item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
	 */
	public function testReorderOnAPublishedAgendaNotifiesOnce(): void {
		$saved = $this->wirePublishedMeeting(
			meeting: ['agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaVersion' => 3]
		);
		$subjects = $this->captureNotifications();
		$this->capturePatches();

		$this->service->reorderItems('meeting-uuid-1', ['item-2', 'item-1']);

		self::assertTrue($this->service->isSuppressingItemNotices() === false);
		self::assertSame(['alice' => 'agenda_changed'], $subjects->getArrayCopy());
		self::assertSame(4, $saved[0]['agendaVersion']);

	}//end testReorderOnAPublishedAgendaNotifiesOnce()

	/**
	 * Wire a meeting, two agenda items and two participants (one left).
	 *
	 * @param array<string, mixed> $meeting Meeting fields over the defaults.
	 *
	 * @return \ArrayObject<int, array<string, mixed>> Every meeting object handed to saveObject().
	 */
	private function wirePublishedMeeting(array $meeting): \ArrayObject {
		$meetingData = array_merge(['id' => 'meeting-uuid-1', 'title' => 'Council', 'lifecycle' => 'scheduled'], $meeting);
		$this->objectService->method('find')->willReturn($this->entity($meetingData));
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				if (($config['filters']['schema'] ?? '') === 'agenda-item') {
					return [
						$this->entity(['id' => 'item-1', 'title' => 'Opening', 'orderNumber' => 1]),
						$this->entity(['id' => 'item-2', 'title' => 'Budget', 'orderNumber' => 2]),
					];
				}

				return [];
			}
		);
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn(
			[
				['owner' => 'alice', 'leftAt' => null],
				['owner' => 'bob', 'leftAt' => '2025-01-01T00:00:00Z'],
			]
		);

		$saved = new \ArrayObject();
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($saved): ObjectEntity {
				$saved->append($object);
				return $this->entity($object);
			}
		);

		return $saved;

	}//end wirePublishedMeeting()

	/**
	 * Record the subject of every notification sent, keyed by user.
	 *
	 * @return \ArrayObject<string, string> user => subject
	 */
	private function captureNotifications(): \ArrayObject {
		$subjects = new \ArrayObject();
		$this->preferences->method('dispatch')->willReturnCallback(
			function (string $personId, string $eventType, string $title, string $message, string $deepLink='', ?array $inApp=null) use ($subjects): int {
				$subjects[$personId] = (string)($inApp['subject'] ?? '');
				$this->dispatched[] = compact('personId', 'eventType', 'title', 'message', 'deepLink', 'inApp');
				return 1;
			}
		);

		return $subjects;

	}//end captureNotifications()

	// -----------------------------------------------------------------------
	// helpers
	// -----------------------------------------------------------------------

	/**
	 * Record every patchObject() call on the shared object-service double.
	 *
	 * @return \ArrayObject<int, array{id: string, data: array<string, mixed>}> The live capture
	 */
	private function capturePatches(): \ArrayObject {
		$patches = new \ArrayObject();
		$this->objectService
			->method('patchObject')
			->willReturnCallback(
				function (string $objectId, array $data) use ($patches): ObjectEntity {
					$patches->append(['id' => $objectId, 'data' => $data]);
					return $this->entity($data);
				}
			);

		return $patches;

	}//end capturePatches()

	/**
	 * Wrap a payload in an ObjectEntity double that serialises to it verbatim.
	 *
	 * The same double VotingServiceCastAsTest uses: OpenRegister returns
	 * entities, never arrays, from find(), findAll() and the write methods.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * Agenda notices go through the member's preferences as agendaChanged, with
	 * the agenda subject for the bell and the meeting's title and link.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 *
	 * @return void
	 */
	public function testAgendaNoticeGoesThroughThePreferences(): void {
		$this->wirePublishedMeeting(meeting: ['agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaVersion' => 1]);
		$this->captureNotifications();

		$this->service->notifyAgendaChanged('meeting-uuid-1');

		self::assertCount(1, $this->dispatched);
		$call = $this->dispatched[0];
		self::assertSame('agendaChanged', $call['eventType']);
		self::assertSame('The agenda of Council changed', $call['title']);
		self::assertSame('/meetings/meeting-uuid-1', $call['deepLink']);
		self::assertSame('agenda_changed', $call['inApp']['subject']);
		self::assertSame(['meetingId' => 'meeting-uuid-1', 'meetingTitle' => 'Council'], $call['inApp']['parameters']);
		self::assertSame(['meeting', 'meeting-uuid-1'], [$call['inApp']['objectType'], $call['inApp']['objectId']]);

	}//end testAgendaNoticeGoesThroughThePreferences()

	/**
	 * The recipient is the participant's linked Nextcloud user, not whoever
	 * created the participant record.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-003-agenda-notices-follow-the-members-delivery-choice
	 *
	 * @return void
	 */
	public function testTheLinkedNextcloudUserIsTheRecipient(): void {
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn(
			[
				['nextcloudUserId' => 'pieter', 'owner' => 'admin', 'leftAt' => null],
				['owner' => 'legacy-owner', 'leftAt' => null],
			]
		);
		$this->wirePublishedMeeting(meeting: ['agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaVersion' => 1]);
		$subjects = $this->captureNotifications();

		$this->service->notifyAgendaChanged('meeting-uuid-1');

		self::assertSame(['pieter' => 'agenda_changed', 'legacy-owner' => 'agenda_changed'], $subjects->getArrayCopy());

	}//end testTheLinkedNextcloudUserIsTheRecipient()

	/**
	 * A burst of edits records a version each time but tells a member once in
	 * five minutes; after that window the next edit notifies again.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-004-a-burst-of-agenda-edits-sends-one-notice
	 *
	 * @return void
	 */
	public function testABurstOfEditsSendsOneNotice(): void {
		$recent = (new \DateTimeImmutable('-2 minutes'))->format(DATE_ATOM);
		$saved = $this->wirePublishedMeeting(
			meeting: ['agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaVersion' => 3, 'agendaNoticeSentAt' => ['alice' => $recent]]
		);
		$subjects = $this->captureNotifications();

		$this->service->notifyAgendaChanged('meeting-uuid-1');

		self::assertCount(1, $saved, 'The version is recorded even without a notice');
		self::assertSame(4, $saved[0]['agendaVersion']);
		self::assertCount(0, $subjects, 'alice was told two minutes ago');
		self::assertSame($recent, $saved[0]['agendaNoticeSentAt']['alice']);

		$this->setUp();
		$old = (new \DateTimeImmutable('-6 minutes'))->format(DATE_ATOM);
		$saved = $this->wirePublishedMeeting(
			meeting: ['agendaPublishedAt' => '2026-09-01T10:00:00+00:00', 'agendaVersion' => 4, 'agendaNoticeSentAt' => ['alice' => $old]]
		);
		$subjects = $this->captureNotifications();

		$this->service->notifyAgendaChanged('meeting-uuid-1');

		self::assertSame(['alice' => 'agenda_changed'], $subjects->getArrayCopy());
		self::assertGreaterThan(strtotime($old), strtotime($saved[0]['agendaNoticeSentAt']['alice']));

	}//end testABurstOfEditsSendsOneNotice()

	/**
	 * Making an item current saves it on the meeting, and only that.
	 *
	 * @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
	 *
	 * @return void
	 */
	public function testMakingAnItemCurrentSavesItOnTheMeeting(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'item-5', 'meeting' => 'meeting-uuid-1']));
		$patches = $this->capturePatches();

		$this->service->setCurrentItem(meetingId: 'meeting-uuid-1', itemId: 'item-5');

		$this->assertSame([['id' => 'meeting-uuid-1', 'data' => ['currentAgendaItem' => 'item-5']]], $patches->getArrayCopy());

	}//end testMakingAnItemCurrentSavesItOnTheMeeting()

	/**
	 * An item of another meeting is not made current.
	 *
	 * @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
	 *
	 * @return void
	 */
	public function testAnItemOfAnotherMeetingIsNotMadeCurrent(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'item-9', 'meeting' => 'other-meeting']));
		$this->objectService->expects($this->never())->method('patchObject');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setCurrentItem(meetingId: 'meeting-uuid-1', itemId: 'item-9');

	}//end testAnItemOfAnotherMeetingIsNotMadeCurrent()

}//end class
