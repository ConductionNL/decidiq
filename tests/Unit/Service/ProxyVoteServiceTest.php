<?php

/**
 * Unit tests for ProxyVoteService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ParticipantToPersonMembershipResolver;
use OCA\Decidesk\Service\ProxyVoteService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProxyVoteService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
 */
class ProxyVoteServiceTest extends TestCase {

	/**
	 * Tracker for audit log calls.
	 *
	 * @var \ArrayObject<int, array<string, mixed>>
	 */
	private \ArrayObject $auditCalls;

	/**
	 * Build a service wired to in-memory rows.
	 *
	 * @param array<int, array<string, mixed>> &$rows Existing rows
	 * @param array<int, array<string, mixed>> &$saved Captured saves
	 * @param int $maxProxies Configured max_proxies_per_holder app config value
	 * @param bool $findAllFail When true the ObjectService::findAll() call throws (fail-closed path)
	 * @param array<int, array<string, string>> $participants Fixture `participant` rows: ['uuid' => .., 'nextcloudUserId' => ..]
	 * @param array<string, array<int, string>> $chairsByMeeting Map of meetingId => Nextcloud UIDs holding chair/secretary role
	 * @param array<string, array{person: string, membership: string}|null> $crosswalk Non-identity Participant->Person/Membership map for
	 *                                                                                 tests that need to prove resolution actually ran
	 *                                                                                 (or fails); defaults to an identity map
	 *                                                                                 (person = participantId) so every pre-existing
	 *                                                                                 'g-1'/'h-1'-style assertion holds.
	 * @param bool $appConfigThrows When true, resolving \OCP\IAppConfig from the container throws
	 *                              (exercises maxProxiesPerHolder()'s fail-closed-to-default catch branch)
	 * @param bool $saveObjectFails When true, ObjectService::saveObject() throws (exercises the
	 *                              register()/transition() catch branches around the write)
	 * @param bool $rowsAsEntities When true, findAll()'s normal (register/schema-context) path returns
	 *                             ObjectEntity doubles instead of raw arrays, exercising forMeeting()'s
	 *                             is_object()/jsonSerialize() conversion branch
	 *
	 * @return ProxyVoteService
	 */
	private function makeService(
		array &$rows,
		array &$saved,
		int $maxProxies = 2,
		bool $findAllFail = false,
		array $participants = [],
		array $chairsByMeeting = [],
		array $crosswalk = [],
		bool $appConfigThrows = false,
		bool $saveObjectFails = false,
		bool $rowsAsEntities = false,
	): ProxyVoteService {
		$rowsRef = &$rows;
		$savedRef = &$saved;
		$participantsRef = $participants;
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$rowsRef, $findAllFail, $participantsRef, $rowsAsEntities): array {
				if ($findAllFail === true) {
					throw new \RuntimeException('OpenRegister unavailable');
				}

				$filters = ($config['filters'] ?? []);
				if (array_key_exists('nextcloudUserId', $filters) === true) {
					$out = [];
					foreach ($participantsRef as $participant) {
						if (($participant['nextcloudUserId'] ?? null) !== $filters['nextcloudUserId']) {
							continue;
						}

						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($participant);
						$out[] = $entity;
					}

					return $out;
				}

				// ObjectService::prepareFindAllConfig() reads filters.register /
				// filters.schema as the register/schema CONTEXT, not as object
				// fields — a top-level 'register'/'schema' key is ignored and the
				// query then runs with no context and returns nothing. This mock
				// previously treated every filter key as a row field, so a caller
				// using the top-level (broken) shape still got rows here: the mock
				// could not tell the working call from the one that silently
				// returns [] in production. Model the real contract instead.
				$context = ['register', 'schema'];
				if (isset($filters['register'], $filters['schema']) === false) {
					// No register/schema context reached the engine: production
					// returns an empty array here (it does NOT throw), so the
					// caller cannot tell "no proxies" from "never looked". Repeat
					// that here so a regression to the top-level shape turns this
					// suite red instead of passing on fixture data.
					return [];
				}

				$out = [];
				foreach ($rowsRef as $row) {
					$matches = true;
					foreach ($filters as $k => $v) {
						if (in_array($k, $context, true) === true) {
							continue;
						}

						if (($row[$k] ?? null) !== $v) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $row;
					}
				}

				if ($rowsAsEntities === true) {
					$wrapped = [];
					foreach ($out as $row) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						$wrapped[] = $entity;
					}

					return $wrapped;
				}

				return $out;
			}
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use (&$rowsRef) {
				foreach ($rowsRef as $row) {
					if (($row['id'] ?? null) === $id) {
						$entity = $this->createMock(ObjectEntity::class);
						$entity->method('jsonSerialize')->willReturn($row);
						$entity->method('getObject')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$savedRef, &$rowsRef, $saveObjectFails): ObjectEntity {
				if ($saveObjectFails === true) {
					throw new \RuntimeException('OpenRegister save unavailable');
				}

				$savedRef[] = $object;
				$existingId = ($uuid ?? ($object['id'] ?? null));
				if ($existingId !== null) {
					foreach ($rowsRef as $i => $row) {
						if (($row['id'] ?? null) === $existingId) {
							$rowsRef[$i] = array_merge($row, $object, ['id' => $existingId]);
							$row = $rowsRef[$i];
							$entity = $this->createMock(ObjectEntity::class);
							$entity->method('jsonSerialize')->willReturn($row);
							$entity->method('getObject')->willReturn($row);
							return $entity;
						}
					}
				}

				$row = array_merge(['id' => 'proxy-' . count($rowsRef)], $object);
				$rowsRef[] = $row;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				$entity->method('getObject')->willReturn($row);
				return $entity;
			}
		);

		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) use ($maxProxies): int {
				if ($app === 'decidesk' && $key === ProxyVoteService::MAX_PROXIES_CONFIG_KEY) {
					return $maxProxies;
				}

				return $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $appConfig, $appConfigThrows): object {
				if ($id === \OCP\IAppConfig::class) {
					if ($appConfigThrows === true) {
						throw new \RuntimeException('IAppConfig unavailable');
					}

					return $appConfig;
				}

				return $objectService;
			}
		);

		$this->auditCalls = new \ArrayObject();
		$tracker = $this->auditCalls;
		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload = []) use ($tracker): array {
				$tracker->append(
					[
						'actor' => $actor,
						'action' => $action,
						'objectUids' => $objectUids,
						'payload' => $payload,
					]
				);
				return ['success' => true, 'entry' => [], 'message' => 'ok'];
			}
		);

		$participantResolver = $this->createMock(ParticipantResolver::class);
		$participantResolver->method('hasRole')->willReturnCallback(
			static function (string $meetingId, string $nextcloudUid, array $roles) use ($chairsByMeeting): bool {
				return in_array($nextcloudUid, ($chairsByMeeting[$meetingId] ?? []), true);
			}
		);

		// Identity crosswalk double: person = participantId, so every existing
		// assertion below that compares a `grantor`/`holder` value against the
		// original 'g-1'/'h-1'-style Participant UUID still holds — the
		// production resolver's own matching behaviour is covered by
		// ParticipantToPersonMembershipResolverTest, not re-derived here.
		$participantCrosswalk = $this->createMock(ParticipantToPersonMembershipResolver::class);
		$participantCrosswalk->method('resolve')->willReturnCallback(
			static function (string $participantId) use ($crosswalk): ?array {
				if ($participantId === '') {
					return null;
				}

				// array_key_exists, not ?? : a test may map an id explicitly to
				// null (unresolvable) and ?? would silently fall through to the
				// identity default for a null value, since isset() treats a
				// stored null the same as "not set".
				if (array_key_exists($participantId, $crosswalk) === true) {
					return $crosswalk[$participantId];
				}

				return ['person' => $participantId, 'membership' => $participantId . '-membership'];
			}
		);

		return new ProxyVoteService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			auditLogService: $audit,
			participantResolver: $participantResolver,
			objectService: $objectService,
			participantCrosswalk: $participantCrosswalk,
		);

	}//end makeService()

	/**
	 * register validates required fields.
	 *
	 * @return void
	 */
	public function testRegisterRequiresFields(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->register('', 'g', 'h');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('required', $result['message']);

	}//end testRegisterRequiresFields()

	/**
	 * register rejects grantor == holder.
	 *
	 * @return void
	 */
	public function testRegisterRejectsSelfProxy(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->register('m-1', 'g-1', 'g-1');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('must differ', $result['message']);

	}//end testRegisterRejectsSelfProxy()

	/**
	 * register stores a pending-approval row and audits proxy-created.
	 *
	 * @return void
	 */
	public function testRegisterStoresPendingAndAuditsCreated(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->register('m-1', 'g-1', 'h-1', ['scope' => 'all-resolutions']);

		$this->assertTrue($result['success']);
		$this->assertSame('pending-approval', $saved[0]['proxyStatus']);
		$this->assertSame('unsigned', $saved[0]['signatureStatus']);
		$this->assertSame('m-1', $saved[0]['meeting']);

		$calls = $this->auditCalls->getArrayCopy();
		$this->assertCount(1, $calls);
		$this->assertSame('proxy-created', $calls[0]['action']);

	}//end testRegisterStoresPendingAndAuditsCreated()

	/**
	 * register() writes the schema's own `proxy-authorization` grantor/holder
	 * fields with the crosswalk-resolved PERSON uuids, not the caller-supplied
	 * Participant uuids — proven with a non-identity crosswalk map so a
	 * regression to writing the raw Participant id turns this test red.
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 *
	 * @return void
	 */
	public function testRegisterWritesCrosswalkResolvedPersonUuids(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			crosswalk: [
				'g-1' => ['person' => 'person-grantor-1', 'membership' => 'membership-grantor-1'],
				'h-1' => ['person' => 'person-holder-1', 'membership' => 'membership-holder-1'],
			],
		);

		$result = $svc->register('m-1', 'g-1', 'h-1');

		$this->assertTrue($result['success']);
		$this->assertSame('person-grantor-1', $saved[0]['grantor']);
		$this->assertSame('person-holder-1', $saved[0]['holder']);

	}//end testRegisterWritesCrosswalkResolvedPersonUuids()

	/**
	 * register() fails closed when the grantor or holder Participant cannot
	 * be resolved to a Person record.
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 *
	 * @return void
	 */
	public function testRegisterFailsWhenCrosswalkCannotResolveGrantorOrHolder(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			crosswalk: ['g-1' => null],
		);

		$result = $svc->register('m-1', 'g-1', 'h-1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Could not resolve', $result['message']);
		$this->assertCount(0, $saved);

	}//end testRegisterFailsWhenCrosswalkCannotResolveGrantorOrHolder()

	/**
	 * suspend transitions proxyStatus to 'suspended' and does NOT audit
	 * proxy-revoked.
	 *
	 * @return void
	 */
	public function testSuspendTransitionsWithoutRevokeAudit(): void {
		$rows = [
			[
				'id' => 'p-1',
				'meeting' => 'm-1',
				'grantor' => 'g-1',
				'holder' => 'h-1',
				'proxyStatus' => 'active',
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->suspend('p-1', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame('suspended', end($saved)['proxyStatus']);

		$calls = $this->auditCalls->getArrayCopy();
		$revokeCalls = array_filter($calls, static fn (array $c): bool => $c['action'] === 'proxy-revoked');
		$this->assertCount(0, $revokeCalls);

	}//end testSuspendTransitionsWithoutRevokeAudit()

	/**
	 * revoke transitions proxyStatus to 'revoked' and audits.
	 *
	 * @return void
	 */
	public function testRevokeTransitionsAndAudits(): void {
		$rows = [
			[
				'id' => 'p-1',
				'meeting' => 'm-1',
				'grantor' => 'g-1',
				'holder' => 'h-1',
				'proxyStatus' => 'active',
			],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->revoke('p-1', 'alice');

		$this->assertTrue($result['success']);
		$this->assertSame('revoked', end($saved)['proxyStatus']);

		$calls = $this->auditCalls->getArrayCopy();
		$revokeCalls = array_values(
			array_filter($calls, static fn (array $c): bool => $c['action'] === 'proxy-revoked')
		);
		$this->assertCount(1, $revokeCalls);
		$this->assertSame('alice', $revokeCalls[0]['actor']);

	}//end testRevokeTransitionsAndAudits()

	/**
	 * transition rejects unknown statuses.
	 *
	 * @return void
	 */
	public function testTransitionRejectsUnknownStatus(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->transition('p-1', 'bogus', 'alice');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Unknown proxy status', $result['message']);

	}//end testTransitionRejectsUnknownStatus()

	/**
	 * forMeeting returns rows for the meeting, optionally filtered by status.
	 *
	 * @return void
	 */
	public function testForMeetingReturnsRowsAndFilters(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-1', 'proxyStatus' => 'revoked'],
			['id' => 'p-3', 'meeting' => 'm-2', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$all = $svc->forMeeting('m-1');
		$this->assertSame(2, $all['count']);

		$activeOnly = $svc->forMeeting('m-1', 'active');
		$this->assertSame(1, $activeOnly['count']);
		$this->assertSame('p-1', $activeOnly['proxies'][0]['id']);

	}//end testForMeetingReturnsRowsAndFilters()

	/**
	 * register rejects a holder who already holds the maximum number of ACTIVE
	 * proxies in the meeting (per-member proxy limit, default 2).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testRegisterRejectsHolderAtProxyCap(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-1', 'grantor' => 'g-2', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->register('m-1', 'g-3', 'h-1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Maximum number of proxies reached', $result['message']);
		$this->assertCount(0, $saved, 'No proxy row may be written when the cap is reached');

	}//end testRegisterRejectsHolderAtProxyCap()

	/**
	 * Non-active proxies (revoked/suspended/pending) and other meetings/holders
	 * do not count toward the cap.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testRegisterCapCountsOnlyActiveProxiesInMeetingForHolder(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-1', 'grantor' => 'g-2', 'holder' => 'h-1', 'proxyStatus' => 'revoked'],
			['id' => 'p-3', 'meeting' => 'm-1', 'grantor' => 'g-3', 'holder' => 'h-1', 'proxyStatus' => 'suspended'],
			['id' => 'p-4', 'meeting' => 'm-1', 'grantor' => 'g-4', 'holder' => 'h-1', 'proxyStatus' => 'pending-approval'],
			['id' => 'p-5', 'meeting' => 'm-2', 'grantor' => 'g-5', 'holder' => 'h-1', 'proxyStatus' => 'active'],
			['id' => 'p-6', 'meeting' => 'm-1', 'grantor' => 'g-6', 'holder' => 'h-2', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->register('m-1', 'g-7', 'h-1');

		$this->assertTrue($result['success'], 'Only 1 ACTIVE proxy in m-1 for h-1 — under the cap of 2');
		$this->assertSame('pending-approval', $saved[0]['proxyStatus']);

	}//end testRegisterCapCountsOnlyActiveProxiesInMeetingForHolder()

	/**
	 * The cap is configurable via app config decidesk/max_proxies_per_holder.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testRegisterCapIsConfigurable(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-1', 'grantor' => 'g-2', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved = [];

		// Raised cap (3): the third proxy is accepted.
		$svc = $this->makeService($rows, $saved, 3);
		$result = $svc->register('m-1', 'g-3', 'h-1');
		$this->assertTrue($result['success']);

		// A cap below 1 falls back to the default of 2 (never disables the limit).
		$rows2 = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-1', 'grantor' => 'g-2', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved2 = [];
		$svc2 = $this->makeService($rows2, $saved2, 0);
		$result = $svc2->register('m-1', 'g-3', 'h-1');
		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Maximum number of proxies reached', $result['message']);

	}//end testRegisterCapIsConfigurable()

	/**
	 * Fail closed: when existing proxies cannot be counted, registration is rejected.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testRegisterFailsClosedWhenProxyCountUnavailable(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved, 2, true);

		$result = $svc->register('m-1', 'g-1', 'h-1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('registration refused', $result['message']);
		$this->assertCount(0, $saved, 'No proxy row may be written when the count is unavailable');

	}//end testRegisterFailsClosedWhenProxyCountUnavailable()

	/**
	 * register() allows self-grantor registration when callerUid resolves to grantorId.
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy
	 *
	 * @return void
	 */
	public function testRegisterAllowsSelfGrantor(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			participants: [['uuid' => 'g-1', 'nextcloudUserId' => 'alice']]
		);

		$result = $svc->register('m-1', 'g-1', 'h-1', callerUid: 'alice');
		$this->assertTrue($result['success']);

	}//end testRegisterAllowsSelfGrantor()

	/**
	 * register() allows a chair to register a proxy on behalf of two other members.
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy
	 *
	 * @return void
	 */
	public function testRegisterAllowsChairOnBehalfOfOthers(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			participants: [
				['uuid' => 'g-1', 'nextcloudUserId' => 'alice'],
				['uuid' => 'h-1', 'nextcloudUserId' => 'bob'],
			],
			chairsByMeeting: ['m-1' => ['chair-carol']]
		);

		$result = $svc->register('m-1', 'g-1', 'h-1', callerUid: 'chair-carol');
		$this->assertTrue($result['success']);

	}//end testRegisterAllowsChairOnBehalfOfOthers()

	/**
	 * register() rejects an unrelated authenticated user with a Forbidden message.
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy
	 *
	 * @return void
	 */
	public function testRegisterRejectsUnrelatedCaller(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			participants: [
				['uuid' => 'g-1', 'nextcloudUserId' => 'alice'],
				['uuid' => 'h-1', 'nextcloudUserId' => 'bob'],
				['uuid' => 'c-1', 'nextcloudUserId' => 'carol'],
			]
		);

		$result = $svc->register('m-1', 'g-1', 'h-1', callerUid: 'carol');
		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Forbidden:', $result['message']);
		$this->assertCount(0, $saved, 'No proxy row may be written for an unauthorized caller');

	}//end testRegisterRejectsUnrelatedCaller()

	/**
	 * register() allows a null callerUid (admin bypass convention).
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy
	 *
	 * @return void
	 */
	public function testRegisterAllowsAdminBypassViaNullCallerUid(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->register('m-1', 'g-1', 'h-1', callerUid: null);
		$this->assertTrue($result['success']);

	}//end testRegisterAllowsAdminBypassViaNullCallerUid()

	/**
	 * suspend()/revoke() allow the proxy's grantor or holder to transition it.
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-002-only-a-party-to-the-proxy-or-an-authorized-official-may-suspend-or-revoke-it
	 *
	 * @return void
	 */
	public function testRevokeAllowsGrantorAndSuspendAllowsHolder(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-1', 'grantor' => 'g-2', 'holder' => 'h-2', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			participants: [
				['uuid' => 'g-1', 'nextcloudUserId' => 'alice'],
				['uuid' => 'h-2', 'nextcloudUserId' => 'bob'],
			]
		);

		$revokeResult = $svc->revoke('p-1', 'alice', callerUid: 'alice');
		$this->assertTrue($revokeResult['success'], 'Grantor may revoke their own proxy');

		$suspendResult = $svc->suspend('p-2', 'bob', callerUid: 'bob');
		$this->assertTrue($suspendResult['success'], 'Holder may suspend a proxy held on their behalf');

	}//end testRevokeAllowsGrantorAndSuspendAllowsHolder()

	/**
	 * suspend()/revoke() reject an unrelated authenticated user (IDOR guard),
	 * leaving the proxy's status unchanged.
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-002-only-a-party-to-the-proxy-or-an-authorized-official-may-suspend-or-revoke-it
	 *
	 * @return void
	 */
	public function testRevokeRejectsUnrelatedCallerAndLeavesStatusUnchanged(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			participants: [
				['uuid' => 'g-1', 'nextcloudUserId' => 'alice'],
				['uuid' => 'h-1', 'nextcloudUserId' => 'bob'],
				['uuid' => 'c-1', 'nextcloudUserId' => 'carol'],
			]
		);

		$result = $svc->revoke('p-1', 'carol', callerUid: 'carol');

		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Forbidden:', $result['message']);
		$this->assertSame('active', $rows[0]['proxyStatus'], 'Unrelated caller must not change the proxy status');
		$this->assertCount(0, $saved, 'No save may occur for an unauthorized transition');

	}//end testRevokeRejectsUnrelatedCallerAndLeavesStatusUnchanged()

	/**
	 * suspend()/revoke() allow a chair/clerk of the meeting to transition
	 * a proxy they are not a party to.
	 *
	 * @spec openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-002-only-a-party-to-the-proxy-or-an-authorized-official-may-suspend-or-revoke-it
	 *
	 * @return void
	 */
	public function testSuspendAllowsChairOfMeeting(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			chairsByMeeting: ['m-1' => ['chair-carol']]
		);

		$result = $svc->suspend('p-1', 'chair-carol', callerUid: 'chair-carol');
		$this->assertTrue($result['success']);

	}//end testSuspendAllowsChairOfMeeting()

	/**
	 * maxProxiesPerHolder() fails closed to the NL governance default (2) when
	 * the app config lookup throws — the configured value (99) is proven NOT
	 * to leak through by seeding exactly 2 existing active proxies for the
	 * holder and asserting the third registration is still rejected.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testMaxProxiesPerHolderFallsBackToDefaultWhenAppConfigLookupThrows(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-1', 'grantor' => 'g-2', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved, maxProxies: 99, appConfigThrows: true);

		$result = $svc->register('m-1', 'g-3', 'h-1');

		$this->assertFalse($result['success'], 'A thrown app config lookup must fall back to the default cap of 2, not the configured 99');
		$this->assertStringContainsString('Maximum number of proxies reached', $result['message']);
		$this->assertCount(0, $saved);

	}//end testMaxProxiesPerHolderFallsBackToDefaultWhenAppConfigLookupThrows()

	/**
	 * register() fails when the holder (but not the grantor) cannot be
	 * resolved to a Person record — the OR condition's second operand.
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 *
	 * @return void
	 */
	public function testRegisterFailsWhenCrosswalkCannotResolveHolderOnly(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			crosswalk: ['h-1' => null],
		);

		$result = $svc->register('m-1', 'g-1', 'h-1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Could not resolve', $result['message']);
		$this->assertCount(0, $saved);

	}//end testRegisterFailsWhenCrosswalkCannotResolveHolderOnly()

	/**
	 * register() reports the write failure and writes nothing when
	 * ObjectService::saveObject() throws.
	 *
	 * @return void
	 */
	public function testRegisterFailsWhenSaveObjectThrows(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved, saveObjectFails: true);

		$result = $svc->register('m-1', 'g-1', 'h-1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Failed to register proxy', $result['message']);
		$this->assertCount(0, $saved);
		$this->assertCount(0, $this->auditCalls->getArrayCopy(), 'A failed write must not be audited as created');

	}//end testRegisterFailsWhenSaveObjectThrows()

	/**
	 * transition() reports "Proxy not found." when no row matches the given
	 * proxyId, without attempting a write.
	 *
	 * @return void
	 */
	public function testTransitionReturnsNotFoundForUnknownProxyId(): void {
		$rows = [];
		$saved = [];
		$svc = $this->makeService($rows, $saved);

		$result = $svc->transition('missing-id', 'active', 'alice');

		$this->assertFalse($result['success']);
		$this->assertSame('Proxy not found.', $result['message']);
		$this->assertNull($result['proxy']);
		$this->assertCount(0, $saved);

	}//end testTransitionReturnsNotFoundForUnknownProxyId()

	/**
	 * transition() reports the write failure and leaves the stored row
	 * unchanged when ObjectService::saveObject() throws.
	 *
	 * @return void
	 */
	public function testTransitionFailsWhenSaveObjectThrows(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'g-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved, saveObjectFails: true);

		$result = $svc->transition('p-1', 'suspended', 'alice');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Failed to transition proxy', $result['message']);
		$this->assertSame('active', $rows[0]['proxyStatus'], 'The row must not change when the write fails');
		$this->assertCount(0, $this->auditCalls->getArrayCopy());

	}//end testTransitionFailsWhenSaveObjectThrows()

	/**
	 * forMeeting() converts real ObjectEntity-shaped rows (as findAll() returns
	 * in production) via jsonSerialize() before filtering by meeting — a mock
	 * that only ever hands back raw arrays cannot exercise this conversion.
	 *
	 * @return void
	 */
	public function testForMeetingConvertsObjectEntityRowsToArrays(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'proxyStatus' => 'active'],
			['id' => 'p-2', 'meeting' => 'm-2', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService($rows, $saved, rowsAsEntities: true);

		$result = $svc->forMeeting('m-1');

		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['count']);
		$this->assertIsArray($result['proxies'][0], 'The ObjectEntity row must be converted to an array');
		$this->assertSame('p-1', $result['proxies'][0]['id']);

	}//end testForMeetingConvertsObjectEntityRowsToArrays()

	/**
	 * suspend()/revoke() reject a caller whose own Participant record cannot
	 * be resolved to a Person by the crosswalk, even when their raw
	 * Participant UUID happens to equal the proxy's grantor field — proving
	 * the guard compares the crosswalk-resolved Person id, not the caller's
	 * raw Participant id.
	 *
	 * @spec openspec/changes/archive/2026-08-19-model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping
	 *
	 * @return void
	 */
	public function testSuspendRejectsCallerWhoseCrosswalkCannotResolveOwnIdentity(): void {
		$rows = [
			['id' => 'p-1', 'meeting' => 'm-1', 'grantor' => 'd-1', 'holder' => 'h-1', 'proxyStatus' => 'active'],
		];
		$saved = [];
		$svc = $this->makeService(
			$rows,
			$saved,
			participants: [['uuid' => 'd-1', 'nextcloudUserId' => 'dave']],
			crosswalk: ['d-1' => null],
		);

		$result = $svc->suspend('p-1', 'dave', callerUid: 'dave');

		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Forbidden:', $result['message']);
		$this->assertSame('active', $rows[0]['proxyStatus']);
		$this->assertCount(0, $saved);

	}//end testSuspendRejectsCallerWhoseCrosswalkCannotResolveOwnIdentity()
}//end class
