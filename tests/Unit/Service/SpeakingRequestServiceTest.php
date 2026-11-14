<?php

/**
 * A speaking request is checked against the body's speaking policy, closes at
 * the deadline unless the secretariat overrides it, and is approved, rejected
 * or referred only while it is registered (inspreekrecht-plenair task 4).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\SpeakingRequestRefusedException;
use OCA\Decidiq\Service\SpeakingRequestService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\SpeakingRequestService
 * @uses   \OCA\Decidiq\Exception\SpeakingRequestRefusedException
 */
final class SpeakingRequestServiceTest extends TestCase {

	/**
	 * Stored objects by uuid, each with its schema under `_schema`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = [];

	/**
	 * Every saveObject call: [schema, uuid, object].
	 *
	 * @var list<array{0: string, 1: string|null, 2: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * Now: 2026-11-10 12:00 UTC.
	 *
	 * @var integer
	 */
	private int $now = 1794312000;

	private SpeakingRequestService $service;

	/**
	 * A council with a per-item policy (24 h, 5 min) and a meeting three days out with one item.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->put('policy-council', 'speaking-policy', ['governanceBody' => 'council', 'enabled' => true, 'registrationDeadlineHours' => 24, 'defaultSpeakingMinutes' => 5, 'level' => 'agenda-item']);
		$this->put('meeting-1', 'meeting', ['governanceBody' => 'council', 'scheduledDate' => '2026-11-13T19:30:00+00:00']);
		$this->put('item-1', 'agenda-item', ['meeting' => 'meeting-1']);
		$this->put('item-other', 'agenda-item', ['meeting' => 'meeting-2']);
		$this->put('meeting-2', 'meeting', ['governanceBody' => 'council', 'scheduledDate' => '2026-11-20T19:30:00+00:00']);
		$this->put('item-2', 'agenda-item', ['meeting' => 'meeting-2']);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id, mixed ...$rest): ?ObjectEntity => (isset($this->objects[(string)$id]) === true) ? $this->entity((string)$id) : null
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[]): array {
				$filters = $config['filters'];
				$hits    = [];
				foreach ($this->objects as $id => $object) {
					if ($object['_schema'] !== $filters['schema']) {
						continue;
					}

					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($object[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$hits[] = $this->entity($id);
				}

				return $hits;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				$this->saves[] = [(string)$schema, $uuid, $object];
				$id = ($uuid ?? 'request-' . count($this->saves));
				$this->put($id, (string)$schema, $object);
				return $this->entity($id);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->service = new SpeakingRequestService($objects, $time);
	}//end setUp()

	/**
	 * Store an object.
	 *
	 * @param string               $id     The uuid.
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return void
	 */
	private function put(string $id, string $schema, array $data): void {
		$this->objects[$id] = ['_schema' => $schema] + $data;
	}//end put()

	/**
	 * A stored object as OpenRegister hands it back.
	 *
	 * @param string $id The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id): ObjectEntity {
		$data = $this->objects[$id];
		unset($data['_schema']);
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getUuid')->willReturn($id);
		$entity->method('jsonSerialize')->willReturn($data + ['@self' => ['id' => $id]]);
		return $entity;
	}//end entity()

	/**
	 * A complete registration for meeting-1, item-1.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function input(array $overrides=[]): array {
		return array_replace(
			[
				'meeting'        => 'meeting-1',
				'agendaItem'     => 'item-1',
				'topic'          => ['speakerName' => 'Mw. Jansen', 'topicText' => 'Dorpsplein', 'internal' => 'dropped'],
				'contactDetails' => ['name' => 'Marieke Jansen', 'email' => 'm@example.org', 'role' => 'dropped'],
			],
			$overrides
		);
	}//end input()

	/**
	 * Assert the call is refused with this status and message, and nothing is saved.
	 *
	 * @param callable $call    The call.
	 * @param int      $status  The expected status.
	 * @param string   $message A fragment of the expected message.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, int $status, string $message): void {
		try {
			$call();
			self::fail('Expected a refusal');
		} catch (SpeakingRequestRefusedException $e) {
			self::assertSame($status, $e->getStatus());
			self::assertStringContainsString($message, $e->getMessage());
		}

		self::assertSame([], $this->saves, 'a refused call saves nothing');
	}//end assertRefused()

	/**
	 * A registration within the policy is stored as registered with the meeting's body.
	 *
	 * @return void
	 */
	public function testARegistrationWithinThePolicyIsStored(): void {
		$stored = $this->service->register(input: $this->input());

		self::assertSame('request-1', $stored['id']);
		[$schema, $uuid, $object] = $this->saves[0];
		self::assertSame('speaking-request', $schema);
		self::assertNull($uuid);
		self::assertSame(
			[
				'meeting'        => 'meeting-1',
				'governanceBody' => 'council',
				'topic'          => ['speakerName' => 'Mw. Jansen', 'topicText' => 'Dorpsplein', 'requestedMinutes' => 5],
				'contactDetails' => ['name' => 'Marieke Jansen', 'email' => 'm@example.org'],
				'lifecycle'      => 'registered',
				'agendaItem'     => 'item-1',
			],
			$object
		);
	}//end testARegistrationWithinThePolicyIsStored()

	/**
	 * A body without a policy, or with speaking switched off, refuses.
	 *
	 * @return void
	 */
	public function testABodyWithoutSpeakingRightsRefuses(): void {
		$this->put('meeting-x', 'meeting', ['governanceBody' => 'board', 'scheduledDate' => '2026-12-01T10:00:00+00:00']);
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['meeting' => 'meeting-x', 'agendaItem' => ''])), 409, 'does not offer the public');

		$this->put('policy-board', 'speaking-policy', ['governanceBody' => 'board', 'enabled' => false]);
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['meeting' => 'meeting-x', 'agendaItem' => ''])), 409, 'does not offer the public');
	}//end testABodyWithoutSpeakingRightsRefuses()

	/**
	 * Per-item speaking needs an item of this meeting; meeting-level speaking refuses one.
	 *
	 * @return void
	 */
	public function testTheAgendaItemFollowsThePolicyLevel(): void {
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['agendaItem' => ''])), 400, 'name the item');
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['agendaItem' => 'item-other'])), 400, 'not on this meeting');

		$this->objects['policy-council']['level'] = 'meeting';
		$this->assertRefused(fn () => $this->service->register(input: $this->input()), 400, 'as a whole');

		$stored = $this->service->register(input: $this->input(['agendaItem' => '']));
		self::assertArrayNotHasKey('agendaItem', $stored);
	}//end testTheAgendaItemFollowsThePolicyLevel()

	/**
	 * Registration closes at meeting start minus the deadline hours.
	 *
	 * @return void
	 */
	public function testRegistrationClosesAtTheDeadline(): void {
		// The meeting starts 2026-11-13 19:30; 24 hours earlier is 2026-11-12 19:30.
		$this->now = strtotime('2026-11-12T19:30:00+00:00');
		$this->service->register(input: $this->input());
		$this->saves = [];

		$this->now = strtotime('2026-11-12T19:31:00+00:00');
		$this->assertRefused(fn () => $this->service->register(input: $this->input()), 409, 'closed 24 hours before');
	}//end testRegistrationClosesAtTheDeadline()

	/**
	 * The secretariat's override registers past the deadline and says so on the request.
	 *
	 * @return void
	 */
	public function testTheOverrideRegistersLateAndRecordsIt(): void {
		$this->now = strtotime('2026-11-13T16:30:00+00:00');

		$stored = $this->service->register(input: $this->input(), override: true);

		self::assertTrue($stored['registeredAfterDeadline']);
		self::assertSame('registered', $stored['lifecycle']);

		$this->now = strtotime('2026-11-10T16:30:00+00:00');
		self::assertArrayNotHasKey('registeredAfterDeadline', $this->service->register(input: $this->input(), override: true), 'an override in time is not late');
	}//end testTheOverrideRegistersLateAndRecordsIt()

	/**
	 * The meeting's own body wins; naming another body is refused.
	 *
	 * @return void
	 */
	public function testTheMeetingsOwnBodyApplies(): void {
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['governanceBody' => 'board'])), 400, 'another body');
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['meeting' => 'missing'])), 404, 'Meeting not found');
	}//end testTheMeetingsOwnBodyApplies()

	/**
	 * A request without the speaker's name or topic is refused.
	 *
	 * @return void
	 */
	public function testANameAndTopicAreRequired(): void {
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['topic' => ['speakerName' => ' ', 'topicText' => 'x']])), 400, 'speaker\'s name');
		$this->assertRefused(fn () => $this->service->register(input: $this->input(['topic' => 'not an object'])), 400, 'speaker\'s name');
	}//end testANameAndTopicAreRequired()

	/**
	 * Approval defaults the time from the policy and takes the next place, carrying every field.
	 *
	 * @return void
	 */
	public function testApprovalDefaultsTimeAndOrderAndCarriesEveryField(): void {
		$this->put('other', 'speaking-request', ['meeting' => 'meeting-1', 'agendaItem' => 'item-1', 'lifecycle' => 'approved', 'position' => 3]);
		$this->put('r1', 'speaking-request', ['meeting' => 'meeting-1', 'agendaItem' => 'item-1', 'governanceBody' => 'council', 'lifecycle' => 'registered', 'contribution' => 'kept', 'topic' => ['speakerName' => 'A']]);

		$approved = $this->service->approve(requestId: 'r1');

		self::assertSame(['approved', 5, 4], [$approved['lifecycle'], $approved['allottedMinutes'], $approved['position']]);
		[, $uuid, $object] = $this->saves[0];
		self::assertSame('r1', $uuid);
		self::assertSame('kept', $object['contribution'], 'an unrelated field survives the save');
		self::assertSame(['speakerName' => 'A'], $object['topic']);

		$this->put('r2', 'speaking-request', ['meeting' => 'meeting-1', 'governanceBody' => 'council', 'lifecycle' => 'registered']);
		$given = $this->service->approve(requestId: 'r2', minutes: 3, position: 1);
		self::assertSame([3, 1], [$given['allottedMinutes'], $given['position']]);
	}//end testApprovalDefaultsTimeAndOrderAndCarriesEveryField()

	/**
	 * A rejection needs a reason, and only a registered request is moderated.
	 *
	 * @return void
	 */
	public function testRejectionNeedsAReasonAndARegisteredRequest(): void {
		$this->put('r1', 'speaking-request', ['meeting' => 'meeting-1', 'lifecycle' => 'registered']);
		$this->assertRefused(fn () => $this->service->reject(requestId: 'r1', reason: '  '), 400, 'needs a reason');

		$rejected = $this->service->reject(requestId: 'r1', reason: ' Niet op de agenda ');
		self::assertSame(['rejected', 'Niet op de agenda'], [$rejected['lifecycle'], $rejected['rejectionReason']]);

		$this->saves = [];
		$this->assertRefused(fn () => $this->service->approve(requestId: 'r1'), 409, 'Only a registered');
		$this->assertRefused(fn () => $this->service->reject(requestId: 'missing', reason: 'x'), 404, 'not found');
		$this->assertRefused(fn () => $this->service->approve(requestId: 'r1', minutes: 0), 409, 'Only a registered');
	}//end testRejectionNeedsAReasonAndARegisteredRequest()

	/**
	 * Speaking time and order below one are refused.
	 *
	 * @return void
	 */
	public function testApprovalRefusesAZeroTime(): void {
		$this->put('r1', 'speaking-request', ['meeting' => 'meeting-1', 'lifecycle' => 'registered']);
		$this->assertRefused(fn () => $this->service->approve(requestId: 'r1', minutes: 0), 400, 'at least 1');
		$this->assertRefused(fn () => $this->service->approve(requestId: 'r1', position: 0), 400, 'at least 1');
	}//end testApprovalRefusesAZeroTime()

	/**
	 * A referral retargets the meeting and item and keeps the status.
	 *
	 * @return void
	 */
	public function testAReferralRetargetsAndKeepsTheStatus(): void {
		$this->put('r1', 'speaking-request', ['meeting' => 'meeting-1', 'agendaItem' => 'item-1', 'governanceBody' => 'council', 'lifecycle' => 'registered', 'topic' => ['speakerName' => 'A']]);

		$referred = $this->service->refer(requestId: 'r1', meetingId: 'meeting-2', agendaItemId: 'item-2');

		self::assertSame(['meeting-2', 'item-2', 'registered'], [$referred['meeting'], $referred['agendaItem'], $referred['lifecycle']]);
		self::assertSame(['speakerName' => 'A'], $referred['topic']);

		$this->saves = [];
		$this->assertRefused(fn () => $this->service->refer(requestId: 'r1', meetingId: 'meeting-2', agendaItemId: 'item-1'), 400, 'not on this meeting');
	}//end testAReferralRetargetsAndKeepsTheStatus()

	/**
	 * The meeting of a request, or an empty string when there is none.
	 *
	 * @return void
	 */
	public function testMeetingOf(): void {
		$this->put('r1', 'speaking-request', ['meeting' => 'meeting-1']);
		self::assertSame('meeting-1', $this->service->meetingOf(requestId: 'r1'));
		self::assertSame('', $this->service->meetingOf(requestId: 'missing'));
		self::assertSame('', $this->service->meetingOf(requestId: ''));
	}//end testMeetingOf()

	/**
	 * Speakers of meeting-1: two approved on item-1 (positions 2 and 1), one
	 * registered and one rejected there, one approved at meeting level.
	 *
	 * @return void
	 */
	private function seedSpeakers(): void {
		$contact = ['name' => 'Anna Jansen', 'email' => 'anna@example.org'];
		$this->put('s-jansen', 'speaking-request', ['meeting' => 'meeting-1', 'agendaItem' => 'item-1', 'lifecycle' => 'approved', 'position' => 2, 'allottedMinutes' => 5, 'topic' => ['speakerName' => 'Anna Jansen', 'organisation' => 'Buurtvereniging', 'topicText' => 'Parkeren'], 'contactDetails' => $contact]);
		$this->put('s-boer', 'speaking-request', ['meeting' => 'meeting-1', 'agendaItem' => 'item-1', 'lifecycle' => 'approved', 'position' => 1, 'allottedMinutes' => 3, 'topic' => ['speakerName' => 'Kees de Boer', 'topicText' => 'Bomen'], 'contactDetails' => $contact]);
		$this->put('s-wacht', 'speaking-request', ['meeting' => 'meeting-1', 'agendaItem' => 'item-1', 'lifecycle' => 'registered', 'topic' => ['speakerName' => 'Wachtend', 'topicText' => 'X', 'requestedMinutes' => 4], 'contactDetails' => $contact]);
		$this->put('s-nee', 'speaking-request', ['meeting' => 'meeting-1', 'agendaItem' => 'item-1', 'lifecycle' => 'rejected', 'rejectionReason' => 'Buiten de orde', 'topic' => ['speakerName' => 'Afgewezen', 'topicText' => 'Y'], 'contactDetails' => $contact]);
		$this->put('s-alv', 'speaking-request', ['meeting' => 'meeting-1', 'lifecycle' => 'approved', 'position' => 1, 'allottedMinutes' => 4, 'topic' => ['speakerName' => 'Lid Een', 'topicText' => 'Begroting'], 'contactDetails' => $contact]);
		$this->put('s-elders', 'speaking-request', ['meeting' => 'meeting-2', 'agendaItem' => 'item-2', 'lifecycle' => 'approved', 'position' => 1, 'topic' => ['speakerName' => 'Elders', 'topicText' => 'Z']]);
	}//end seedSpeakers()

	/**
	 * The internal agenda shows approved slots in order and pending requests,
	 * per item and for the meeting block; rejected requests and other
	 * meetings' requests are absent.
	 *
	 * @return void
	 */
	public function testTheInternalAgendaShowsSlotsInOrderAndPendingApart(): void {
		$this->seedSpeakers();

		$agenda = $this->service->agenda(meetingId: 'meeting-1');

		$this->assertSame(['item-1', 'meeting'], array_keys($agenda));
		$this->assertSame(['Kees de Boer', 'Anna Jansen'], array_column($agenda['item-1']['slots'], 'speakerName'));
		$this->assertSame([3, 5], array_column($agenda['item-1']['slots'], 'minutes'));
		$this->assertSame([['s-wacht', 'registered', 4]], array_map(static fn (array $row): array => [$row['id'], $row['status'], $row['minutes']], $agenda['item-1']['pending']));
		$this->assertSame(['Lid Een'], array_column($agenda['meeting']['slots'], 'speakerName'));
		$this->assertStringNotContainsString('Afgewezen', json_encode($agenda));
		$this->assertStringNotContainsString('anna@example.org', json_encode($agenda));
	}//end testTheInternalAgendaShowsSlotsInOrderAndPendingApart()

	/**
	 * The public agenda follows the body's display setting.
	 *
	 * @return void
	 */
	public function testThePublicAgendaFollowsTheBodysDisplaySetting(): void {
		$this->seedSpeakers();

		$count = $this->service->publicAgenda(meetingId: 'meeting-1');
		$this->assertSame(['display' => 'count', 'agenda' => ['item-1' => ['count' => 2], 'meeting' => ['count' => 1]]], $count);

		$this->objects['policy-council']['publicDisplay'] = 'first-name';
		$this->assertSame(
			['count' => 2, 'speakers' => [['firstName' => 'Kees'], ['firstName' => 'Anna']]],
			$this->service->publicAgenda(meetingId: 'meeting-1')['agenda']['item-1']
		);

		$this->objects['policy-council']['publicDisplay'] = 'speaker-name';
		$this->assertSame(
			['speakerName' => 'Anna Jansen', 'organisation' => 'Buurtvereniging', 'topicText' => 'Parkeren'],
			$this->service->publicAgenda(meetingId: 'meeting-1')['agenda']['item-1']['speakers'][1]
		);
	}//end testThePublicAgendaFollowsTheBodysDisplaySetting()

	/**
	 * The queue holds an item's approved speakers in order with their minutes.
	 *
	 * @return void
	 */
	public function testTheQueuePreloadsAnItemsApprovedSpeakers(): void {
		$this->seedSpeakers();

		$this->assertSame(
			[
				['requestId' => 's-boer', 'kind' => 'public-speaker', 'name' => 'Kees de Boer', 'minutes' => 3, 'position' => 1],
				['requestId' => 's-jansen', 'kind' => 'public-speaker', 'name' => 'Anna Jansen', 'minutes' => 5, 'position' => 2],
			],
			$this->service->queue(meetingId: 'meeting-1', agendaItemId: 'item-1')
		);
		$this->assertSame(['s-alv'], array_column($this->service->queue(meetingId: 'meeting-1', agendaItemId: ''), 'requestId'));
	}//end testTheQueuePreloadsAnItemsApprovedSpeakers()

	/**
	 * Marking an approved speaker as spoken saves the whole request with
	 * only the status changed, so the contribution survives.
	 *
	 * @return void
	 */
	public function testMarkingSpokenKeepsEveryOtherField(): void {
		$this->seedSpeakers();
		$this->objects['s-jansen']['contribution'] = 'Mijn schriftelijke bijdrage';

		$saved = $this->service->markOutcome(requestId: 's-jansen', outcome: 'spoke');

		$this->assertSame('spoke', $saved['lifecycle']);
		[$schema, $uuid, $object] = $this->saves[0];
		$this->assertSame(['speaking-request', 's-jansen'], [$schema, $uuid]);
		$this->assertSame('Mijn schriftelijke bijdrage', $object['contribution']);
		$this->assertSame(['name' => 'Anna Jansen', 'email' => 'anna@example.org'], $object['contactDetails']);
		$this->assertSame(5, $object['allottedMinutes']);
	}//end testMarkingSpokenKeepsEveryOtherField()

	/**
	 * Only an approved request takes an outcome, and only spoke or no-show.
	 *
	 * @return void
	 */
	public function testAnOutcomeNeedsAnApprovedRequestAndAKnownValue(): void {
		$this->seedSpeakers();
		foreach ([['s-wacht', 'spoke', 409], ['s-jansen', 'gone', 400], ['nope', 'no-show', 404]] as [$id, $outcome, $status]) {
			try {
				$this->service->markOutcome(requestId: $id, outcome: $outcome);
				$this->fail('Accepted '.$id.' '.$outcome);
			} catch (SpeakingRequestRefusedException $e) {
				$this->assertSame($status, $e->getStatus());
			}
		}

		$this->assertSame([], $this->saves);
	}//end testAnOutcomeNeedsAnApprovedRequestAndAKnownValue()
}//end class
