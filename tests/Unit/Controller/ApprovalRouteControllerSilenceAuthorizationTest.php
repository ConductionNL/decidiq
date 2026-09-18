<?php

/**
 * Approval Route Controller silence-authorization test.
 *
 * REQ-AR-015 says a step whose silence APPROVES may be declared only by an
 * administrator, because silence that approves is a signature nobody gave.
 *
 * That sentence was reported as a safety property while the guard enforcing it,
 * StageLapsePolicy::assertSettable(), had no production caller at all: it was
 * reached only from its own unit test, which is exactly why the suite stayed
 * green and nobody noticed. This test goes through the REAL write path, from the
 * controller endpoint down to the object store, with the least privileged
 * principal that should be refused: an ordinary signed-in user, not an
 * administrator. An anonymous caller is refused earlier, by the 401, so it would
 * prove nothing about this rule.
 *
 * The store double asserts the refusal from the CALLER's side: saveObject() is
 * expected NEVER to be called. Delete the guard call in
 * ApprovalRouteService::instantiate() and this test reddens with
 * "saveObject(...) was not expected to be called", rather than quietly passing
 * because the assertion was only ever about the guard in isolation.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

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
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The silence-approves rule, enforced through the controller.
 */
class ApprovalRouteControllerSilenceAuthorizationTest extends TestCase {
	/**
	 * The object-service double every controller in this file is built on.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $facade;

	/**
	 * A route whose second step declares that its silence approves.
	 *
	 * The offending step is the second one deliberately: a guard that ran inside
	 * the write loop would already have written the first stage before it threw.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function routeDeclaringApprovingSilence(): array {
		return [
			'name' => 'Review',
			'steps' => [
				['order' => 1, 'stageType' => 'endorsement', 'actorType' => 'person', 'actor' => 'alice', 'label' => 'Parafering'],
				[
					'order' => 2,
					'stageType' => 'decisive',
					'actorType' => 'person',
					'actor' => 'bob',
					'label' => 'Accordering',
					'onSilence' => 'approve',
				],
			],
		];
	}

	/**
	 * Build the controller on a real engine and a store double.
	 *
	 * @param bool $isAdministrator Whether the caller is an administrator.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ApprovalRouteController The controller.
	 */
	private function controller(bool $isAdministrator, array $params): ApprovalRouteController {
		$this->facade = $this->createMock(ObjectServiceInterface::class);

		// The subject exists and is reachable, so the run reaches the rule under
		// test rather than stopping at the accessibility refusal before it.
		$subject = $this->createMock(ObjectEntityInterface::class);
		$subject->method('jsonSerialize')->willReturn(['id' => 'subj-1', 'owner' => 'alice']);
		$this->facade->method('find')->willReturn($subject);

		// No stages yet, so instantiate() does not return early as idempotent.
		$this->facade->method('findAll')->willReturn([]);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdministrator);

		$store = new RegisterObjectStore($this->facade);

		return new ApprovalRouteController(
			request: $request,
			service: new ApprovalRouteService(
				store: $store,
				guard: new ApprovalStageGuard(new MandateDirectory($store)),
				mapper: new ApprovalRouteStepMapper(),
			),
			announcer: $this->createMock(ApprovalRouteConclusionAnnouncer::class),
			clearanceService: new SubjectClearanceService(),
			userSession: $session,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * An ordinary signed-in user is refused, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function testAnOrdinaryUserIsRefusedAndNoStageIsWritten(): void {
		$controller = $this->controller(
			isAdministrator: false,
			params: [
				'route' => $this->routeDeclaringApprovingSilence(),
				'subject' => 'subj-1',
				'subjectSchema' => 'decision',
			],
		);

		// THE mutation detector. With the guard call removed from
		// instantiate(), the engine writes the stages and this reddens with
		// "was not expected to be called".
		$this->facade->expects($this->never())->method('saveObject');

		$response = $controller->instantiate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString(
			'administrator',
			(string)($response->getData()['message'] ?? ''),
			'The refusal did not say who may declare an approving silence.'
		);
	}

	/**
	 * An administrator declaring the same route is not refused by this rule.
	 *
	 * The control: without it, a guard that refused EVERYBODY would pass the
	 * test above and nothing would say so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-015)
	 */
	public function testAnAdministratorIsNotRefusedByThisRule(): void {
		$controller = $this->controller(
			isAdministrator: true,
			params: [
				'route' => $this->routeDeclaringApprovingSilence(),
				'subject' => 'subj-1',
				'subjectSchema' => 'decision',
			],
		);

		$stage = $this->createMock(ObjectEntityInterface::class);
		$stage->method('jsonSerialize')->willReturn(['id' => 'stage-1', 'sequence' => 1]);
		$this->facade->expects($this->atLeastOnce())->method('saveObject')->willReturn($stage);

		$response = $controller->instantiate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}
}
