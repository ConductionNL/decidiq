<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use ArrayObject;
use OCA\Decidiq\Controller\ApprovalRouteController;
use OCA\Decidiq\Service\ApprovalRouteConclusionAnnouncer;
use OCA\Decidiq\Service\ApprovalRouteService;
use OCA\Decidiq\Service\ApprovalRouteStepMapper;
use OCA\Decidiq\Service\ApprovalStageGuard;
use OCA\Decidiq\Service\MandateDirectory;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\Decidiq\Service\SubjectClearanceService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sending a route with a nominated approver, through the controller and the real engine (REQ-DWP-009).
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-009)
 */
class ApprovalRouteControllerNominationTest extends TestCase {
	/**
	 * The object-service double the controller is built on.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $facade;

	/**
	 * The stage rows the engine saved.
	 *
	 * @var ArrayObject<int, array<string, mixed>>
	 */
	private ArrayObject $saved;

	/**
	 * Whether the engine is built with the nomination checker, as the container builds it.
	 *
	 * @var bool
	 */
	private bool $withChecker = true;

	/**
	 * A route whose only step takes its approver from `tekenbevoegden`.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function route(): array {
		return [
			'name' => 'Ondertekening',
			'steps' => [
				[
					'order' => 1,
					'stageType' => 'decisive',
					'actorType' => 'role',
					'actor' => 'secretary',
					'label' => 'Tekenen',
					'nominationGroup' => 'tekenbevoegden',
				],
			],
			// Sent by the caller and ignored: who nominated is the session's user.
			'nominatedBy' => 'eve',
		];
	}

	/**
	 * Build the controller as `bea`, with only `dirk` in `tekenbevoegden`.
	 *
	 * @param array<string, mixed> $nominations The nominations sent with the route.
	 *
	 * @return ApprovalRouteController The controller.
	 */
	private function controller(array $nominations): ApprovalRouteController {
		$this->facade = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->saved  = new ArrayObject();

		$subject = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$subject->method('jsonSerialize')->willReturn(['id' => 'subj-1', 'owner' => 'bea']);
		$this->facade->method('find')->willReturn($subject);
		$this->facade->method('findAll')->willReturn([]);

		$saved = $this->saved;
		$this->facade->method('saveObject')->willReturnCallback(
			function (array $object) use ($saved): ObjectEntityInterface {
				$saved->append($object);
				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object + ['id' => 'stage-' . count($saved)]);
				return $entity;
			}
		);
		$this->facade->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch): ObjectEntityInterface {
				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($patch->data + ['id' => $patch->objectId]);
				return $entity;
			}
		);

		$params = [
			'route' => $this->route(),
			'subject' => 'subj-1',
			'subjectSchema' => 'decision',
			'nominations' => $nominations,
		];
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('bea');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($uid === 'dirk' && $group === 'tekenbevoegden')
		);

		$store = new RegisterObjectStore(objectService: $this->facade);

		return new ApprovalRouteController(
			request: $request,
			service: new ApprovalRouteService(
				store: $store,
				guard: new ApprovalStageGuard(mandates: new MandateDirectory(store: $store)),
				mapper: new ApprovalRouteStepMapper(groups: $this->checkerGroups(groupManager: $groupManager)),
			),
			announcer: $this->createMock(originalClassName: ApprovalRouteConclusionAnnouncer::class),
			clearanceService: new SubjectClearanceService(),
			userSession: $session,
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}

	/**
	 * The group manager the engine checks nominees with, or none.
	 *
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @return IGroupManager|null The checker.
	 */
	private function checkerGroups(IGroupManager $groupManager): ?IGroupManager {
		if ($this->withChecker === false) {
			return null;
		}

		return $groupManager;
	}

	/**
	 * A company names its own authorised signatory: the stage is assigned to them.
	 *
	 * @return void
	 */
	public function testTheOpenActionIsAssignedToTheNominatedMember(): void {
		$response = $this->controller(nominations: ['1' => 'dirk'])->instantiate();

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus(), message: (string)json_encode(value: $response->getData()));
		$stages = array_values(array_filter((array)$this->saved, static fn (array $row): bool => isset($row['sequence'])));
		$this->assertCount(expectedCount: 1, haystack: $stages);
		$this->assertSame(expected: 'dirk', actual: $stages[0]['assignedPerson']);
		$this->assertSame(expected: 'tekenbevoegden', actual: $stages[0]['nominationGroup']);
		$this->assertSame(expected: 'bea', actual: $stages[0]['nominatedBy']);
	}

	/**
	 * A nomination outside the bound is refused, names the bound, and writes nothing.
	 *
	 * @return void
	 */
	public function testANominationOutsideTheBoundIsRefusedAndWritesNothing(): void {
		$response = $this->controller(nominations: ['1' => 'mallory'])->instantiate();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertStringContainsString(needle: 'tekenbevoegden', haystack: (string)($response->getData()['message'] ?? ''));
		$this->assertCount(expectedCount: 0, haystack: $this->saved, message: 'A refused nomination still wrote a stage.');
	}

	/**
	 * An engine built without the checker refuses the route rather than travel it unchecked.
	 *
	 * @return void
	 */
	public function testWithoutTheCheckerANominationRouteIsRefused(): void {
		$this->withChecker = false;
		$response = $this->controller(nominations: ['1' => 'dirk'])->instantiate();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertStringContainsString(needle: 'cannot be checked', haystack: (string)($response->getData()['message'] ?? ''));
		$this->assertCount(expectedCount: 0, haystack: $this->saved);
	}
}
