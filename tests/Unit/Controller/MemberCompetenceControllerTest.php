<?php

/**
 * The competence routes call the guard: a member confirming their own
 * competence gets 403 from the controller, a signatory gets 200
 * (bodies-board-composition-skills-and-diversity task 2; no orphan guard).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\MemberCompetenceController;
use OCA\Decidiq\Service\CompetenceConfirmationGuard;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\MemberCompetenceService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Controller\MemberCompetenceController
 * @uses   \OCA\Decidiq\Service\CompetenceConfirmationGuard
 * @uses   \OCA\Decidiq\Service\GovernanceScopeGuard
 */
class MemberCompetenceControllerTest extends TestCase {

	private const BOARD = '6a1c0000-0000-4000-8000-000000000001';
	private const MARK_SEAT = '6a1c0000-0000-4000-8000-000000000011';
	private const MARK = '6a1c0000-0000-4000-8000-000000000021';
	private const WATER = '6a1c0000-0000-4000-8000-000000000031';
	private const MARKS_WATER = '6a1c0000-0000-4000-8000-000000000041';

	/**
	 * Objects by schema slug and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * How many saves reached OpenRegister.
	 *
	 * @var integer
	 */
	private int $saves = 0;

	/**
	 * Whether OpenRegister fails on save.
	 *
	 * @var boolean
	 */
	private bool $broken = false;

	/**
	 * Seed the board.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'governance-body' => [self::BOARD => ['name' => 'RvC']],
			'person' => [self::MARK => ['name' => 'Mark', 'nextcloudUserId' => 'mark']],
			'membership' => [self::MARK_SEAT => ['person' => self::MARK, 'governanceBody' => self::BOARD]],
			'board-competence' => [self::WATER => ['governanceBody' => self::BOARD, 'name' => 'Water management']],
			'member-competence' => [self::MARKS_WATER => ['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'expert']],
		];
		$this->saves = 0;
		$this->broken = false;
	}//end setUp()

	/**
	 * The controller, wired to the real service and guards.
	 *
	 * @param string|null          $uid    The signed-in account, null when signed out
	 * @param array<string, mixed> $params The request parameters
	 *
	 * @return MemberCompetenceController
	 */
	private function controller(?string $uid, array $params = []): MemberCompetenceController {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($data);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				if ($this->broken === true) {
					throw new RuntimeException('database down');
				}

				$this->saves++;
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? '6a1c0000-0000-4000-8000-000000000999');
				$entity->setObject($object);
				return $entity;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $gid): bool => $who === 'janneke' && $gid === 'decidesk:body:' . self::BOARD . ':signatory');

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$scope = new GovernanceScopeGuard(groupManager: $groups, logger: new NullLogger(), objectService: $objects);
		$service = new MemberCompetenceService(
			objectService: $objects,
			guard: new CompetenceConfirmationGuard(scopeGuard: $scope, groupManager: $groups),
			userSession: $session
		);

		return new MemberCompetenceController(request: $request, competences: $service, userSession: $session, logger: new NullLogger());
	}//end controller()

	/**
	 * The member confirming their own competence: 403 and nothing saved.
	 *
	 * @return void
	 */
	public function testTheMemberConfirmingTheirOwnGets403(): void {
		$response = $this->controller(uid: 'mark')->confirm(id: self::MARKS_WATER);
		self::assertSame(403, $response->getStatus());
		self::assertSame(0, $this->saves);
	}//end testTheMemberConfirmingTheirOwnGets403()

	/**
	 * The body's signatory confirming: 200 with the confirmed competence.
	 *
	 * @return void
	 */
	public function testTheSignatoryConfirms(): void {
		$response = $this->controller(uid: 'janneke')->confirm(id: self::MARKS_WATER);
		self::assertSame(200, $response->getStatus());
		self::assertSame('janneke', $response->getData()['memberCompetence']['confirmedBy']);
		self::assertSame(1, $this->saves);
	}//end testTheSignatoryConfirms()

	/**
	 * Signed out is 401, unknown is 404, a refused payload is 422 and an
	 * OpenRegister failure is 500.
	 *
	 * @return void
	 */
	public function testTheOtherOutcomesMapToTheirStatus(): void {
		self::assertSame(401, $this->controller(uid: null)->confirm(id: self::MARKS_WATER)->getStatus());
		self::assertSame(404, $this->controller(uid: 'janneke')->confirm(id: '6a1c0000-0000-4000-8000-000000000099')->getStatus());

		$refused = $this->controller(uid: 'mark', params: ['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'guru', '_route' => 'x'])->record();
		self::assertSame(422, $refused->getStatus());

		$this->broken = true;
		self::assertSame(500, $this->controller(uid: 'janneke')->confirm(id: self::MARKS_WATER)->getStatus());
	}//end testTheOtherOutcomesMapToTheirStatus()

	/**
	 * Recording and the body's profile go through the same guard: a member
	 * records their own (201), cannot change the profile (403); the
	 * signatory adds a competence (201) and edits one (200).
	 *
	 * @return void
	 */
	public function testRecordingAndTheProfileAreGuarded(): void {
		$own = $this->controller(uid: 'mark', params: ['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'basic'])->record();
		self::assertSame(201, $own->getStatus());

		$edit = $this->controller(uid: 'mark', params: ['level' => 'experienced', 'id' => self::MARKS_WATER])->update(id: self::MARKS_WATER);
		self::assertSame(200, $edit->getStatus());

		$memberProfile = $this->controller(uid: 'mark', params: ['requiredHolders' => 3, 'id' => self::WATER])->updateCompetence(id: self::WATER);
		self::assertSame(403, $memberProfile->getStatus());

		$added = $this->controller(uid: 'janneke', params: ['governanceBody' => self::BOARD, 'name' => 'IT and cybersecurity'])->createCompetence();
		self::assertSame(201, $added->getStatus());
		self::assertSame('IT and cybersecurity', $added->getData()['competence']['name']);

		$edited = $this->controller(uid: 'janneke', params: ['requiredHolders' => 2, 'id' => self::WATER])->updateCompetence(id: self::WATER);
		self::assertSame(200, $edited->getStatus());
		self::assertSame(2, $edited->getData()['competence']['requiredHolders']);
	}//end testRecordingAndTheProfileAreGuarded()
}//end class
