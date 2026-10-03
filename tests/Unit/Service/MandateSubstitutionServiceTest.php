<?php

/**
 * Starting, ending and reading a mandate swap (bodies-substitute-mandate-swap
 * task 2): one case per refusal of REQ-MSW-003, the stored record validated
 * against the real register fragment, and the end that keeps the record.
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
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\MandateSubstitutionController;
use OCA\Decidiq\Exception\SubstitutionRefusedException;
use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MandateSubstitutionService;
use OCA\Decidiq\Service\MeetingRoleGate;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\SubstitutionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The audit committee of 4 March: Mr Bos (seat 3) leaves, Mrs De Wit takes his seat.
 *
 * @covers \OCA\Decidiq\Service\MandateSubstitutionService
 * @covers \OCA\Decidiq\Controller\MandateSubstitutionController
 * @covers \OCA\Decidiq\Exception\SubstitutionRefusedException
 * @uses   \OCA\Decidiq\Service\SubstitutionResolver
 * @uses   \OCA\Decidiq\Service\MeetingRoleGate
 */
class MandateSubstitutionServiceTest extends TestCase {

	private const MEETING = 'm-audit';

	/**
	 * The meeting's participants.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $participants = [];

	/**
	 * Stored substitutions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $substitutions = [];

	/**
	 * Stored voting rounds.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rounds = [];

	/**
	 * What saveObject received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The Nextcloud accounts that preside.
	 *
	 * @var list<string>
	 */
	private array $presiding = ['griffier1'];

	/**
	 * Build the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->participants = [
			['id' => 'p-chair', 'displayName' => 'R. Visser', 'role' => 'chair', 'party' => 'CDA', 'seatNumber' => 1, 'votingWeight' => 1],
			['id' => 'p-bos', 'displayName' => 'H. Bos', 'role' => 'member', 'party' => 'VVD', 'seatNumber' => 3, 'votingWeight' => 1],
			['id' => 'p-kaya', 'displayName' => 'S. Kaya', 'role' => 'member', 'party' => 'D66', 'seatNumber' => 4, 'votingWeight' => 1],
			['id' => 'p-dewit', 'displayName' => 'A. de Wit', 'role' => 'observer', 'party' => 'VVD'],
			['id' => 'p-smit', 'displayName' => 'J. Smit', 'role' => 'observer', 'party' => 'PvdA'],
		];
		$this->substitutions = [];
		$this->rounds = [];
		$this->saved = [];
		$this->presiding = ['griffier1'];
	}//end setUp()

	/**
	 * Wrap an array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getUuid')->willReturn(($data['id'] ?? null));
		return $entity;
	}//end entity()

	/**
	 * The service over doubles of OpenRegister, the participants, the meeting of a round and the group manager.
	 *
	 * @return MandateSubstitutionService
	 */
	private function service(): MandateSubstitutionService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => match ($config['filters']['schema'] ?? '') {
				'mandate-substitution' => array_map(fn (array $row): ObjectEntity => $this->entity($row), array_values($this->substitutions)),
				'voting-round' => array_map(fn (array $row): ObjectEntity => $this->entity($row), $this->rounds),
				default => [],
			}
		);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id, mixed ...$rest): ?ObjectEntity => isset($this->substitutions[$id]) === true ? $this->entity($this->substitutions[$id]) : null
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, mixed ...$rest): ObjectEntity {
				$this->assertFalse($rest['_rbac'] ?? true, 'A substitution is written in system context');
				$id = ($rest['uuid'] ?? null) ?? 'sub-' . (count($this->substitutions) + 1);
				$this->saved[] = $object;
				$this->substitutions[$id] = ['id' => $id] + $object;
				return $this->entity($this->substitutions[$id]);
			}
		);

		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('resolveMeetingParticipants')->willReturnCallback(fn (): array => $this->participants);
		$participants->method('hasRole')->willReturnCallback(
			fn (string $meetingId, string $nextcloudUid, array $roles): bool => in_array($nextcloudUid, $this->presiding, true)
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		$order = $this->createMock(AmendmentOrderService::class);
		$order->method('resolveMeetingIdForRound')->willReturnCallback(fn (array $round): ?string => ($round['meetingOf'] ?? null));

		return new MandateSubstitutionService(
			objectService: $objectService,
			participantResolver: $participants,
			roleGate: new MeetingRoleGate(groupManager: $groups, participantResolver: $participants),
			amendmentOrder: $order,
		);
	}//end service()

	/**
	 * The merged schema of the substitution, as Opis validates it.
	 *
	 * @return object
	 */
	private function substitutionSchema(): object {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$schema = [];
		foreach (array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: [])) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === 'mandate-substitution') {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		$properties = $schema['properties'];
		foreach ($properties as $key => $property) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable'], $properties[$key]['format']);
		}

		$properties['startedAt']['format'] = 'date-time';
		$properties['endedAt']['format'] = 'date-time';
		return json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties, 'additionalProperties' => false]));
	}//end substitutionSchema()

	/**
	 * Assert a refusal and its status.
	 *
	 * @param int      $status The expected status
	 * @param callable $call   The call that must be refused
	 *
	 * @return void
	 */
	private function assertRefused(int $status, callable $call): void {
		try {
			$call();
			self::fail('The swap was not refused');
		} catch (SubstitutionRefusedException $e) {
			self::assertSame($status, $e->getStatus(), $e->getMessage());
		}

		self::assertSame([], $this->saved, 'A refused swap stores nothing');
	}//end assertRefused()

	/**
	 * Scenario "A committee member leaves and her substitute takes the seat":
	 * the record copies seat, party, role and weight, names who recorded it,
	 * and validates against the real fragment.
	 *
	 * @return void
	 */
	public function testTheSecretarySwapsAMemberForAnObserver(): void {
		$record = $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-bos', incomingId: 'p-dewit', reason: 'Mr Bos left for another appointment', userId: 'griffier1');

		self::assertSame('sub-1', $record['id']);
		self::assertSame([self::MEETING, 'p-bos', 'p-dewit', 3, 'VVD', 'member', 1, 'griffier1'], [$record['meeting'], $record['outgoingParticipant'], $record['incomingParticipant'], $record['seatNumber'], $record['party'], $record['role'], $record['votingWeight'], $record['recordedBy']]);
		self::assertArrayNotHasKey('endedAt', $record);

		$result = (new Validator())->validate(json_decode((string)json_encode($this->saved[0])), $this->substitutionSchema());
		self::assertTrue($result->isValid(), 'The stored substitution validates against fragment 118');

		$seats = $this->service()->seats(meetingId: self::MEETING, userId: 'griffier1');
		self::assertTrue($seats['canSubstitute']);
		self::assertSame(['p-chair', 'p-bos', 'p-kaya', 'p-dewit', 'p-smit'], array_column($seats['participants'], 'id'), 'Seats in seat order, unseated last by name');
		self::assertSame(['p-dewit'], array_column($seats['substitutions'], 'incomingParticipant'));
		self::assertFalse($this->service()->seats(meetingId: self::MEETING, userId: 'member1')['canSubstitute']);
	}//end testTheSecretarySwapsAMemberForAnObserver()

	/**
	 * Scenario "A member without a presiding role": 403.
	 *
	 * @return void
	 */
	public function testAMemberWithoutAPresidingRoleIsRefused(): void {
		$this->assertRefused(403, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-bos', incomingId: 'p-dewit', reason: '', userId: 'member1'));
	}//end testAMemberWithoutAPresidingRoleIsRefused()

	/**
	 * Scenario "Not during a vote": 409 while a round of this meeting is open;
	 * a closed round or a round of another meeting does not block.
	 *
	 * @return void
	 */
	public function testNotDuringAVote(): void {
		$this->rounds = [
			['id' => 'r-other', 'openedAt' => '2026-03-04T20:00:00+01:00', 'meetingOf' => 'm-other'],
			['id' => 'r-closed', 'openedAt' => '2026-03-04T19:00:00+01:00', 'closedAt' => '2026-03-04T19:10:00+01:00', 'meetingOf' => self::MEETING],
		];
		$this->service()->start(meetingId: self::MEETING, outgoingId: 'p-kaya', incomingId: 'p-smit', reason: '', userId: 'griffier1');
		$this->saved = [];
		$this->substitutions = [];

		$this->rounds[] = ['id' => 'r-open', 'openedAt' => '2026-03-04T20:30:00+01:00', 'meetingOf' => self::MEETING];
		$this->assertRefused(409, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-kaya', incomingId: 'p-smit', reason: '', userId: 'griffier1'));
	}//end testNotDuringAVote()

	/**
	 * Scenario "The chair is not swapped", and the other seat-plan refusals.
	 *
	 * @return void
	 */
	public function testTheSeatPlanRefusals(): void {
		$this->assertRefused(400, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-chair', incomingId: 'p-dewit', reason: '', userId: 'griffier1'));
		$this->assertRefused(400, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-bos', incomingId: 'p-bos', reason: '', userId: 'griffier1'));
		$this->assertRefused(400, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-bos', incomingId: 'p-stranger', reason: '', userId: 'griffier1'));
		$this->assertRefused(400, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-bos', incomingId: 'p-kaya', reason: '', userId: 'griffier1'));

		$this->substitutions['sub-9'] = ['id' => 'sub-9', 'meeting' => self::MEETING, 'outgoingParticipant' => 'p-bos', 'incomingParticipant' => 'p-dewit', 'startedAt' => '2026-03-04T20:15:00+01:00'];
		$this->assertRefused(409, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-bos', incomingId: 'p-smit', reason: '', userId: 'griffier1'));
		$this->assertRefused(409, fn () => $this->service()->start(meetingId: self::MEETING, outgoingId: 'p-kaya', incomingId: 'p-dewit', reason: '', userId: 'griffier1'));
	}//end testTheSeatPlanRefusals()

	/**
	 * Scenario "Mr Bos returns": the end sets endedAt and keeps the record;
	 * it is refused during a vote, for another meeting, and a second time.
	 *
	 * @return void
	 */
	public function testEndingASubstitutionKeepsTheRecord(): void {
		$this->substitutions['sub-9'] = ['id' => 'sub-9', 'meeting' => self::MEETING, 'outgoingParticipant' => 'p-bos', 'incomingParticipant' => 'p-dewit', 'seatNumber' => 3, 'startedAt' => '2026-03-04T20:15:00+01:00'];

		$this->rounds = [['id' => 'r-open', 'openedAt' => '2026-03-04T20:30:00+01:00', 'meetingOf' => self::MEETING]];
		$this->assertRefused(409, fn () => $this->service()->end(meetingId: self::MEETING, substitutionId: 'sub-9', userId: 'griffier1'));
		$this->rounds = [];

		$this->assertRefused(404, fn () => $this->service()->end(meetingId: 'm-other', substitutionId: 'sub-9', userId: 'griffier1'));
		$this->assertRefused(403, fn () => $this->service()->end(meetingId: self::MEETING, substitutionId: 'sub-9', userId: 'member1'));

		$ended = $this->service()->end(meetingId: self::MEETING, substitutionId: 'sub-9', userId: 'griffier1');
		self::assertSame('sub-9', $ended['id']);
		self::assertNotSame('', (string)($ended['endedAt'] ?? ''));
		self::assertSame('2026-03-04T20:15:00+01:00', $ended['startedAt']);
		$result = (new Validator())->validate(json_decode((string)json_encode($this->saved[0])), $this->substitutionSchema());
		self::assertTrue($result->isValid(), 'The ended substitution validates against fragment 118');
		self::assertSame([], (new SubstitutionResolver(objectService: $this->objectServiceOver()))->activeFor(meetingId: self::MEETING));

		$this->saved = [];
		$this->assertRefused(409, fn () => $this->service()->end(meetingId: self::MEETING, substitutionId: 'sub-9', userId: 'griffier1'));
	}//end testEndingASubstitutionKeepsTheRecord()

	/**
	 * The controller answers each refusal with its status, and 401 when anonymous.
	 *
	 * @return void
	 */
	public function testTheControllerAnswersTheRefusalsStatus(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['outgoingParticipantId', '', 'p-chair'],
			['incomingParticipantId', '', 'p-dewit'],
			['reason', '', ' left '],
		]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('griffier1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$controller = new MandateSubstitutionController(request: $request, substitutions: $this->service(), userSession: $session);
		$response = $controller->substitute(meetingId: self::MEETING);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(400, $response->getStatus());
		self::assertSame(200, $controller->seats(meetingId: self::MEETING)->getStatus());
		self::assertSame(404, $controller->endSubstitution(meetingId: self::MEETING, id: 'nope')->getStatus());

		$anonymous = $this->createMock(IUserSession::class);
		$anonymous->method('getUser')->willReturn(null);
		$controller = new MandateSubstitutionController(request: $request, substitutions: $this->service(), userSession: $anonymous);
		self::assertSame(401, $controller->seats(meetingId: self::MEETING)->getStatus());
		self::assertSame(401, $controller->substitute(meetingId: self::MEETING)->getStatus());
		self::assertSame(401, $controller->endSubstitution(meetingId: self::MEETING, id: 'sub-9')->getStatus());
	}//end testTheControllerAnswersTheRefusalsStatus()

	/**
	 * An OpenRegister double over the stored substitutions only.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectServiceOver(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $row): ObjectEntity => $this->entity($row), array_values($this->substitutions))
		);
		return $objectService;
	}//end objectServiceOver()
}//end class
