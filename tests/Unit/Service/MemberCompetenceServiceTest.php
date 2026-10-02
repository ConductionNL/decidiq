<?php

/**
 * Who writes a body's competences and a member's competences, and who
 * confirms one (bodies-board-composition-skills-and-diversity task 2).
 *
 * The real CompetenceConfirmationGuard and GovernanceScopeGuard run; only
 * OpenRegister's object service and Nextcloud's session and groups are
 * faked. Every object the service writes is validated with Opis against the
 * merged register schema.
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
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Exception\CompetenceRefusedException;
use OCA\Decidiq\Service\CompetenceConfirmationGuard;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\Decidiq\Service\MemberCompetenceService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Decidiq\Service\MemberCompetenceService
 * @covers \OCA\Decidiq\Service\CompetenceConfirmationGuard
 */
class MemberCompetenceServiceTest extends TestCase {

	private const BOARD = '6a1c0000-0000-4000-8000-000000000001';
	private const OTHER = '6a1c0000-0000-4000-8000-000000000002';
	private const MARK_SEAT = '6a1c0000-0000-4000-8000-000000000011';
	private const JANNEKE_SEAT = '6a1c0000-0000-4000-8000-000000000012';
	private const MARK = '6a1c0000-0000-4000-8000-000000000021';
	private const JANNEKE = '6a1c0000-0000-4000-8000-000000000022';
	private const WATER = '6a1c0000-0000-4000-8000-000000000031';
	private const ELSEWHERE = '6a1c0000-0000-4000-8000-000000000032';
	private const MARKS_WATER = '6a1c0000-0000-4000-8000-000000000041';

	/**
	 * Objects by schema slug and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every save: [schema, object, uuid, rbac, multitenancy].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: string|null, 3: bool, 4: bool}>
	 */
	private array $saves = [];

	/**
	 * The signed-in account.
	 *
	 * @var string
	 */
	private string $uid = 'janneke';

	/**
	 * Group memberships by uid.
	 *
	 * @var array<string, list<string>>
	 */
	private array $groups = [];

	/**
	 * Admin accounts.
	 *
	 * @var list<string>
	 */
	private array $admins = [];

	/**
	 * The board, two seats on it, the people and Mark's water competence.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'governance-body' => [self::BOARD => ['name' => 'RvC'], self::OTHER => ['name' => 'Raad']],
			'person' => [
				self::MARK => ['name' => 'Mark van den Berg', 'nextcloudUserId' => 'mark'],
				self::JANNEKE => ['name' => 'Janneke de Bruin', 'nextcloudUserId' => 'janneke'],
			],
			'membership' => [
				self::MARK_SEAT => ['person' => self::MARK, 'governanceBody' => self::BOARD, 'role' => 'member'],
				self::JANNEKE_SEAT => ['person' => self::JANNEKE, 'governanceBody' => self::BOARD, 'role' => 'chair'],
			],
			'board-competence' => [
				self::WATER => ['governanceBody' => self::BOARD, 'name' => 'Water management', 'requiredHolders' => 1, 'active' => true],
				self::ELSEWHERE => ['governanceBody' => self::OTHER, 'name' => 'Spatial planning', 'requiredHolders' => 1, 'active' => true],
			],
			'member-competence' => [
				self::MARKS_WATER => ['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'expert'],
			],
		];
		$this->saves = [];
		$this->groups = ['janneke' => ['decidesk:body:' . self::BOARD . ':signatory']];
		$this->admins = [];
	}//end setUp()

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The uuid
	 * @param array<string, mixed> $data   The object
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $schema, string $id, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The service under test with its real guards.
	 *
	 * @return MemberCompetenceService
	 */
	private function service(): MemberCompetenceService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				return $data === null ? null : self::entity(schema: (string)$schema, id: (string)$id, data: $data);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->saves[] = [(string)$schema, $object, $uuid, $_rbac, $_multitenancy];
				$id = ($uuid ?? sprintf('6a1c0000-0000-4000-8000-%012d', 900 + count($this->saves)));
				$this->store[(string)$schema][$id] = $object;
				return self::entity(schema: (string)$schema, id: $id, data: $object);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->admins, true));
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $gid): bool => in_array($gid, ($this->groups[$uid] ?? []), true));

		$scope = new GovernanceScopeGuard(groupManager: $groupManager, logger: new NullLogger(), objectService: $objects);
		$guard = new CompetenceConfirmationGuard(scopeGuard: $scope, groupManager: $groupManager);

		return new MemberCompetenceService(objectService: $objects, guard: $guard, userSession: $session);
	}//end service()

	/**
	 * Assert a written object validates against the merged register schema.
	 *
	 * @param string               $slug   The schema slug
	 * @param array<string, mixed> $object The object as written
	 *
	 * @return void
	 */
	private static function assertValidAgainstRegister(string $slug, array $object): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$schema = [];
		foreach (array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: [])) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		self::assertNotSame([], $schema, $slug . ' is declared');
		$properties = $schema['properties'];
		foreach (array_keys($properties) as $key) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable']);
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($object)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), sprintf('The %s the service writes validates: %s', $slug, json_encode($object)));
	}//end assertValidAgainstRegister()

	/**
	 * The last save.
	 *
	 * @return array{0: string, 1: array<string, mixed>, 2: string|null, 3: bool, 4: bool}
	 */
	private function lastSave(): array {
		self::assertNotSame([], $this->saves, 'something was saved');
		return $this->saves[array_key_last($this->saves)];
	}//end lastSave()

	/**
	 * A signatory of the body confirms: confirmedBy and confirmedAt are set,
	 * in system context, and the object validates.
	 *
	 * @return void
	 */
	public function testASignatoryOfTheBodyConfirms(): void {
		$result = $this->service()->confirm(id: self::MARKS_WATER);

		[$schema, $object, $uuid, $rbac] = $this->lastSave();
		self::assertSame('member-competence', $schema);
		self::assertSame(self::MARKS_WATER, $uuid);
		self::assertFalse($rbac);
		self::assertSame('janneke', $object['confirmedBy']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $object['confirmedAt']);
		self::assertSame('expert', $object['level']);
		self::assertSame('janneke', $result['confirmedBy']);
		self::assertSame(self::MARKS_WATER, $result['id']);
		self::assertValidAgainstRegister(slug: 'member-competence', object: $object);
	}//end testASignatoryOfTheBodyConfirms()

	/**
	 * The member cannot confirm their own competence.
	 *
	 * @return void
	 */
	public function testTheMemberCannotConfirmTheirOwn(): void {
		$this->uid = 'mark';
		$this->expectException(AccessDeniedException::class);
		try {
			$this->service()->confirm(id: self::MARKS_WATER);
		} finally {
			self::assertSame([], $this->saves, 'nothing changes');
		}
	}//end testTheMemberCannotConfirmTheirOwn()

	/**
	 * A signatory confirming their own competence is refused too: a
	 * confirmation is someone else's word.
	 *
	 * @return void
	 */
	public function testASignatoryCannotConfirmTheirOwn(): void {
		$this->store['member-competence'][self::MARKS_WATER]['membership'] = self::JANNEKE_SEAT;
		$this->expectException(AccessDeniedException::class);
		try {
			$this->service()->confirm(id: self::MARKS_WATER);
		} finally {
			self::assertSame([], $this->saves, 'nothing changes');
		}
	}//end testASignatoryCannotConfirmTheirOwn()

	/**
	 * A signatory of another body is refused.
	 *
	 * @return void
	 */
	public function testASignatoryOfAnotherBodyCannotConfirm(): void {
		$this->uid = 'piet';
		$this->groups['piet'] = ['decidesk:body:' . self::OTHER . ':signatory'];
		$this->expectException(AccessDeniedException::class);
		try {
			$this->service()->confirm(id: self::MARKS_WATER);
		} finally {
			self::assertSame([], $this->saves, 'nothing changes');
		}
	}//end testASignatoryOfAnotherBodyCannotConfirm()

	/**
	 * An unknown member competence is a 404, not a 403.
	 *
	 * @return void
	 */
	public function testAnUnknownCompetenceIsMissing(): void {
		$this->expectException(MissingObjectException::class);
		$this->service()->confirm(id: '6a1c0000-0000-4000-8000-000000000099');
	}//end testAnUnknownCompetenceIsMissing()

	/**
	 * Changing the level of a confirmed competence clears both confirmation
	 * fields; a payload cannot set them.
	 *
	 * @return void
	 */
	public function testChangingTheLevelClearsTheConfirmation(): void {
		$this->store['member-competence'][self::MARKS_WATER] += ['confirmedBy' => 'janneke', 'confirmedAt' => '2026-09-01T10:00:00+00:00'];
		$this->uid = 'mark';

		$this->service()->record(data: ['level' => 'experienced', 'confirmedBy' => 'mark'], id: self::MARKS_WATER);

		[, $object] = $this->lastSave();
		self::assertSame('experienced', $object['level']);
		self::assertArrayNotHasKey('confirmedBy', $object);
		self::assertArrayNotHasKey('confirmedAt', $object);
		self::assertValidAgainstRegister(slug: 'member-competence', object: $object);
	}//end testChangingTheLevelClearsTheConfirmation()

	/**
	 * Saving the same level keeps the confirmation, and a payload cannot
	 * forge one either.
	 *
	 * @return void
	 */
	public function testTheSameLevelKeepsTheConfirmation(): void {
		$this->store['member-competence'][self::MARKS_WATER] += ['confirmedBy' => 'janneke', 'confirmedAt' => '2026-09-01T10:00:00+00:00'];
		$this->uid = 'mark';

		$this->service()->record(data: ['level' => 'expert', 'note' => 'Former dike-reeve', 'confirmedBy' => 'mark'], id: self::MARKS_WATER);

		[, $object] = $this->lastSave();
		self::assertSame('janneke', $object['confirmedBy']);
		self::assertSame('Former dike-reeve', $object['note']);
	}//end testTheSameLevelKeepsTheConfirmation()

	/**
	 * A member records their own competence, unconfirmed; another member
	 * cannot record one for them; a signatory can.
	 *
	 * @return void
	 */
	public function testAMemberRecordsTheirOwnAndASignatoryRecordsForAnyone(): void {
		$this->uid = 'mark';
		$created = $this->service()->record(data: ['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'basic', 'confirmedAt' => '2026-01-01T00:00:00+00:00'], id: null);
		[$schema, $object, $uuid] = $this->lastSave();
		self::assertSame('member-competence', $schema);
		self::assertNull($uuid);
		self::assertSame(['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'basic'], $object);
		self::assertNotSame('', $created['id']);
		self::assertValidAgainstRegister(slug: 'member-competence', object: $object);

		$this->uid = 'janneke';
		$this->service()->record(data: ['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'expert'], id: null);
		self::assertCount(2, $this->saves);

		$this->uid = 'mark';
		$this->expectException(AccessDeniedException::class);
		$this->service()->record(data: ['membership' => self::JANNEKE_SEAT, 'competence' => self::WATER, 'level' => 'basic'], id: null);
	}//end testAMemberRecordsTheirOwnAndASignatoryRecordsForAnyone()

	/**
	 * A competence of another body, or a level outside the enum, is refused.
	 *
	 * @return void
	 */
	public function testACompetenceOfAnotherBodyOrAnUnknownLevelIsRefused(): void {
		$service = $this->service();
		try {
			$service->record(data: ['membership' => self::MARK_SEAT, 'competence' => self::ELSEWHERE, 'level' => 'basic'], id: null);
			self::fail('a competence of another body is refused');
		} catch (CompetenceRefusedException $e) {
			self::assertStringContainsString('same body', $e->getMessage());
		}

		$this->expectException(CompetenceRefusedException::class);
		$service->record(data: ['membership' => self::MARK_SEAT, 'competence' => self::WATER, 'level' => 'guru'], id: null);
	}//end testACompetenceOfAnotherBodyOrAnUnknownLevelIsRefused()

	/**
	 * A signatory adds and edits a body's competences; an ordinary member
	 * cannot change one (REQ-BCS-001).
	 *
	 * @return void
	 */
	public function testOnlyASignatoryChangesTheBodysCompetences(): void {
		$created = $this->service()->saveCompetence(data: ['governanceBody' => self::BOARD, 'name' => 'IT and cybersecurity', 'requiredHolders' => 1, 'confirmedBy' => 'x'], id: null);
		[$schema, $object, $uuid, $rbac] = $this->lastSave();
		self::assertSame('board-competence', $schema);
		self::assertNull($uuid);
		self::assertFalse($rbac);
		self::assertSame(['governanceBody' => self::BOARD, 'name' => 'IT and cybersecurity', 'requiredHolders' => 1], $object);
		self::assertValidAgainstRegister(slug: 'board-competence', object: $object);
		self::assertSame('IT and cybersecurity', $created['name']);

		// The body of an existing competence cannot be moved by the payload.
		$this->service()->saveCompetence(data: ['governanceBody' => self::OTHER, 'requiredHolders' => 2, 'active' => false], id: self::WATER);
		[, $object] = $this->lastSave();
		self::assertSame(self::BOARD, $object['governanceBody']);
		self::assertSame(2, $object['requiredHolders']);
		self::assertFalse($object['active']);
		self::assertValidAgainstRegister(slug: 'board-competence', object: $object);

		$this->uid = 'mark';
		$saves = count($this->saves);
		try {
			$this->service()->saveCompetence(data: ['requiredHolders' => 3], id: self::WATER);
			self::fail('an ordinary member cannot change the profile');
		} catch (AccessDeniedException $e) {
			self::assertCount($saves, $this->saves, 'the competence is unchanged');
		}
	}//end testOnlyASignatoryChangesTheBodysCompetences()

	/**
	 * A competence needs a name and at least one required holder; an
	 * administrator may manage any body's competences.
	 *
	 * @return void
	 */
	public function testACompetenceNeedsANameAndAHolderAndAnAdminMayManage(): void {
		$this->uid = 'admin';
		$this->admins = ['admin'];
		$service = $this->service();
		try {
			$service->saveCompetence(data: ['governanceBody' => self::BOARD, 'name' => '  '], id: null);
			self::fail('a nameless competence is refused');
		} catch (CompetenceRefusedException $e) {
			self::assertSame([], $this->saves);
		}

		try {
			$service->saveCompetence(data: ['governanceBody' => self::BOARD, 'name' => 'Legal', 'requiredHolders' => 0], id: null);
			self::fail('zero required holders is refused');
		} catch (CompetenceRefusedException $e) {
			self::assertSame([], $this->saves);
		}

		$service->saveCompetence(data: ['governanceBody' => self::BOARD, 'name' => 'Legal', 'description' => 'Corporate law', 'order' => 3], id: null);
		[, $object] = $this->lastSave();
		self::assertSame(['governanceBody' => self::BOARD, 'name' => 'Legal', 'description' => 'Corporate law', 'order' => 3], $object);

		$this->expectException(MissingObjectException::class);
		$service->saveCompetence(data: ['governanceBody' => '6a1c0000-0000-4000-8000-000000000098', 'name' => 'Legal'], id: null);
	}//end testACompetenceNeedsANameAndAHolderAndAnAdminMayManage()
}//end class
