<?php

/**
 * Unit tests for OnboardingChecklistService and OnboardingController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/tasks.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Controller\OnboardingController;
use OCA\Decidiq\Exception\OnboardingStepRefusedException;
use OCA\Decidiq\Kernel\Contracts\GovernanceScopeGuardInterface;
use OCA\Decidiq\Service\OnboardingChecklistService;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Steps done and skipped, the lifecycle, the membership effects, and who may mark a step.
 *
 * @covers \OCA\Decidiq\Service\OnboardingChecklistService
 * @covers \OCA\Decidiq\Controller\OnboardingController
 */
final class OnboardingChecklistServiceTest extends TestCase {

	/**
	 * Stored objects by schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Saves, as [schema, uuid|null, object].
	 *
	 * @var array<int, array{0: string, 1: string|null, 2: array<string, mixed>}>
	 */
	private array $saves = [];

	protected function setUp(): void {
		$this->store = [
			'member-onboarding' => [
				'on-1' => [
					'person' => 'person-pieter',
					'targetBody' => 'body-council',
					'targetRole' => 'member',
					'trigger' => 'join',
					'installedOn' => '2026-10-01',
					'installationType' => 'oath',
					'installationMeeting' => 'meeting-1',
					'lifecycle' => 'started',
					'steps' => [
						['stepType' => 'installation', 'title' => 'Record the installation', 'status' => 'open'],
						['stepType' => 'induction-pack', 'title' => 'Send the induction pack', 'status' => 'open', 'mandatory' => false],
					],
				],
			],
			'member-offboarding' => [
				'off-1' => [
					'person' => 'person-pieter',
					'body' => 'body-council',
					'membership' => 'ms-1',
					'endDate' => '2026-12-31',
					'lifecycle' => 'in-execution',
					'steps' => [
						['stepType' => 'end-membership', 'title' => 'End the membership', 'status' => 'open'],
						['stepType' => 'revoke-groups', 'title' => 'Withdraw groups', 'status' => 'open', 'mandatory' => false],
					],
				],
			],
			'membership' => ['ms-1' => ['person' => 'person-pieter', 'governanceBody' => 'body-council', 'role' => 'member', 'startDate' => '2022-01-01']],
		];
	}

	public function testTheInstallationCreatesTheMembershipFromTheInstallationDate(): void {
		$saved = $this->service()->completeStep(kind: 'onboarding', id: 'on-1', index: 0, outcome: 'completed');

		$membership = $this->savesTo(schema: 'membership')[0];
		$this->assertSame([null, ['person' => 'person-pieter', 'governanceBody' => 'body-council', 'role' => 'member', 'startDate' => '2026-10-01']], [$membership[1], $membership[2]]);
		$this->assertSame([], MergedRegisterSchema::violations(slug: 'membership', payload: $membership[2]));
		$this->assertSame('new-membership', $saved['membership']);
		$this->assertSame('completed', $saved['steps'][0]['status']);
		$this->assertSame('in-execution', $saved['lifecycle']);
	}

	public function testTheLastOpenStepCompletesTheRecordThroughInExecution(): void {
		$service = $this->service();
		$service->completeStep(kind: 'onboarding', id: 'on-1', index: 0, outcome: 'completed');

		$saved = $service->completeStep(kind: 'onboarding', id: 'on-1', index: 1, outcome: 'skipped');

		$this->assertSame('skipped', $saved['steps'][1]['status']);
		$this->assertSame('completed', $saved['lifecycle']);
		$this->assertSame(['in-execution', 'in-execution', 'completed'], array_map(static fn (array $save): string => $save[2]['lifecycle'], $this->savesTo(schema: 'member-onboarding')));
		$this->assertCount(1, $this->savesTo(schema: 'membership'));
	}

	public function testTheEndStepEndDatesTheMembership(): void {
		$this->service()->completeStep(kind: 'offboarding', id: 'off-1', index: 0, outcome: 'completed');

		$membership = $this->savesTo(schema: 'membership')[0];
		$this->assertSame('ms-1', $membership[1]);
		$this->assertSame('2026-12-31', $membership[2]['endDate']);
		$this->assertSame('2022-01-01', $membership[2]['startDate']);
		$this->assertSame([], MergedRegisterSchema::violations(slug: 'membership', payload: $membership[2]));
	}

	public function testRevokingGroupsIsNeverSkippableAndAMandatoryStepNeither(): void {
		$this->assertRefused(status: 409, message: 'A mandatory step cannot be skipped.', call: fn () => $this->service()->completeStep(kind: 'offboarding', id: 'off-1', index: 1, outcome: 'skipped'));
		$this->assertRefused(status: 409, message: 'A mandatory step cannot be skipped.', call: fn () => $this->service()->completeStep(kind: 'onboarding', id: 'on-1', index: 0, outcome: 'skipped'));
		$this->assertSame([], $this->saves);
	}

	public function testAnInstallationWithoutItsFactsIsRefusedAndNothingIsWritten(): void {
		unset($this->store['member-onboarding']['on-1']['installationMeeting']);

		$this->assertRefused(status: 409, message: 'Record the installation date, type and meeting before completing the installation.', call: fn () => $this->service()->completeStep(kind: 'onboarding', id: 'on-1', index: 0, outcome: 'completed'));
		$this->assertSame([], $this->saves);
	}

	public function testRefusalsForMissingRecordsStepsAndFinishedOnes(): void {
		$this->assertRefused(status: 400, message: 'Unknown checklist kind.', call: fn () => $this->service()->record(kind: 'induction', id: 'on-1'));
		$this->assertRefused(status: 404, message: 'This record does not exist.', call: fn () => $this->service()->completeStep(kind: 'onboarding', id: 'nope', index: 0, outcome: 'completed'));
		$this->assertRefused(status: 404, message: 'This step does not exist.', call: fn () => $this->service()->completeStep(kind: 'onboarding', id: 'on-1', index: 7, outcome: 'completed'));
		$this->assertRefused(status: 400, message: 'A step is marked completed or skipped.', call: fn () => $this->service()->completeStep(kind: 'onboarding', id: 'on-1', index: 0, outcome: 'open'));

		$this->store['member-onboarding']['on-1']['steps'][1]['status'] = 'completed';
		$this->assertRefused(status: 409, message: 'This step is already completed.', call: fn () => $this->service()->completeStep(kind: 'onboarding', id: 'on-1', index: 1, outcome: 'completed'));

		$this->store['member-onboarding']['on-1']['lifecycle'] = 'lapsed';
		$this->assertRefused(status: 409, message: 'This record is finished; its steps no longer change.', call: fn () => $this->service()->completeStep(kind: 'onboarding', id: 'on-1', index: 0, outcome: 'completed'));
		$this->assertSame([], $this->saves);
	}

	public function testAPlainMemberGets403AndTheStepIsUnchanged(): void {
		$response = $this->controller(uid: 'kees', admin: false, signatory: false)->updateStep(kind: 'onboarding', id: 'on-1', index: 0);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->saves);
	}

	public function testTheSecretaryAndAnAdministratorMayMarkAStep(): void {
		$response = $this->controller(uid: 'anna', admin: false, signatory: true)->updateStep(kind: 'onboarding', id: 'on-1', index: 1);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('skipped', $response->getData()['steps'][1]['status']);

		$this->assertSame(200, $this->controller(uid: 'admin', admin: true, signatory: false, status: 'completed')->updateStep(kind: 'offboarding', id: 'off-1', index: 0)->getStatus());
	}

	public function testWithoutASessionTheAnswerIs401AndARefusalKeepsItsStatus(): void {
		$this->assertSame(401, $this->controller(uid: null, admin: false, signatory: false)->updateStep(kind: 'onboarding', id: 'on-1', index: 0)->getStatus());
		$this->assertSame(404, $this->controller(uid: 'admin', admin: true, signatory: false)->updateStep(kind: 'onboarding', id: 'nope', index: 0)->getStatus());
	}

	/**
	 * Assert a call is refused with this status and message.
	 *
	 * @param int      $status  The status.
	 * @param string   $message The message.
	 * @param callable $call    The call.
	 *
	 * @return void
	 */
	private function assertRefused(int $status, string $message, callable $call): void {
		try {
			$call();
			$this->fail('Expected a refusal: ' . $message);
		} catch (OnboardingStepRefusedException $e) {
			$this->assertSame([$status, $message], [$e->getStatus(), $e->getMessage()]);
		}
	}

	/**
	 * The saves to one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array{0: string, 1: string|null, 2: array<string, mixed>}>
	 */
	private function savesTo(string $schema): array {
		return array_values(array_filter($this->saves, static fn (array $save): bool => $save[0] === $schema));
	}

	/**
	 * The controller with a caller.
	 *
	 * @param string|null $uid       The caller, or null for none.
	 * @param bool        $admin     Whether an administrator.
	 * @param bool        $signatory Whether in the body's signatory scope.
	 * @param string      $status    The requested step status.
	 *
	 * @return OnboardingController
	 */
	private function controller(?string $uid, bool $admin, bool $signatory, string $status = 'skipped'): OnboardingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($key === 'status' ? $status : $default));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$guard = $this->createMock(GovernanceScopeGuardInterface::class);
		$guard->method('isInBodyScope')->willReturnCallback(
			static fn (string $userId, string $bodyId, string $scope): bool => $signatory && $bodyId === 'body-council' && $scope === 'signatory'
		);

		return new OnboardingController($request, $session, $groups, $guard, $this->service());
	}

	/**
	 * The service over a mocked object service backed by the store.
	 *
	 * @return OnboardingChecklistService
	 */
	private function service(): OnboardingChecklistService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?ObjectEntityInterface {
				$this->assertSame('decidiq', $register);
				$data = ($this->store[$schema][$id] ?? null);
				if ($data === null) {
					return null;
				}
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($data);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saves[] = [(string)$schema, $uuid, $object];
				$id = ($uuid ?? 'new-' . $schema);
				$this->store[$schema][$id] = $object;
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getUuid')->willReturn($id);
				$entity->method('getObject')->willReturn($object);
				return $entity;
			}
		);

		return new OnboardingChecklistService($objects);
	}
}
