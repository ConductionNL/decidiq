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
use OCA\Decidiq\Service\ApprovalNomination;
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
		$this->facade = $this->createMock(ObjectServiceInterface::class);
		$this->saved  = new ArrayObject();

		$subject = $this->createMock(ObjectEntityInterface::class);
		$subject->method('jsonSerialize')->willReturn(['id' => 'subj-1', 'owner' => 'bea']);
		$this->facade->method('find')->willReturn($subject);
		$this->facade->method('findAll')->willReturn([]);

		$saved = $this->saved;
		$this->facade->method('saveObject')->willReturnCallback(
			function (array $object) use ($saved): ObjectEntityInterface {
				$saved->append($object);
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object + ['id' => 'stage-' . count($saved)]);
				return $entity;
			}
		);
		$this->facade->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch): ObjectEntityInterface {
				$entity = $this->createMock(ObjectEntityInterface::class);
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
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bea');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($uid === 'dirk' && $group === 'tekenbevoegden')
		);

		$store = new RegisterObjectStore($this->facade);

		return new ApprovalRouteController(
			request: $request,
			service: new ApprovalRouteService(
				store: $store,
				guard: new ApprovalStageGuard(new MandateDirectory($store)),
				mapper: new ApprovalRouteStepMapper(),
				nomination: $this->withChecker ? new ApprovalNomination(groups: $groupManager) : null,
			),
			announcer: $this->createMock(ApprovalRouteConclusionAnnouncer::class),
			clearanceService: new SubjectClearanceService(),
			userSession: $session,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A company names its own authorised signatory: the stage is assigned to them.
	 *
	 * @return void
	 */
	public function testTheOpenActionIsAssignedToTheNominatedMember(): void {
		$response = $this->controller(nominations: ['1' => 'dirk'])->instantiate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus(), (string)json_encode($response->getData()));
		$stages = array_values(array_filter((array)$this->saved, static fn (array $row): bool => isset($row['sequence'])));
		$this->assertCount(1, $stages);
		$this->assertSame('dirk', $stages[0]['assignedPerson']);
		$this->assertSame('tekenbevoegden', $stages[0]['nominationGroup']);
		$this->assertSame('bea', $stages[0]['nominatedBy']);
	}

	/**
	 * A nomination outside the bound is refused, names the bound, and writes nothing.
	 *
	 * @return void
	 */
	public function testANominationOutsideTheBoundIsRefusedAndWritesNothing(): void {
		$response = $this->controller(nominations: ['1' => 'mallory'])->instantiate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('tekenbevoegden', (string)($response->getData()['message'] ?? ''));
		$this->assertCount(0, $this->saved, 'A refused nomination still wrote a stage.');
	}

	/**
	 * An engine built without the checker refuses the route rather than travel it unchecked.
	 *
	 * @return void
	 */
	public function testWithoutTheCheckerANominationRouteIsRefused(): void {
		$this->withChecker = false;
		$response = $this->controller(nominations: ['1' => 'dirk'])->instantiate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('cannot be checked', (string)($response->getData()['message'] ?? ''));
		$this->assertCount(0, $this->saved);
	}
}
