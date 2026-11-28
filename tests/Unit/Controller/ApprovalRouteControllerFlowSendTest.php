<?php

/**
 * Tests: Send for approval starts a flow run, and a route already under way on the old engine stays there.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\ApprovalRouteController;
use OCA\Decidiq\Kernel\Contracts\ApprovalRouteConclusionAnnouncerInterface;
use OCA\Decidiq\Kernel\Contracts\ApprovalRouteServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SubjectClearanceServiceInterface;
use OCA\Decidiq\Service\ApprovalRouteFlowStarter;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Controller\ApprovalRouteController
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there
 */
class ApprovalRouteControllerFlowSendTest extends TestCase {

	private const ROUTE = [
		'id' => 'route-1',
		'steps' => [['order' => 1, 'stageType' => 'decisive', 'actorType' => 'person', 'actor' => 'wouter']],
	];

	/**
	 * @var ApprovalRouteServiceInterface&MockObject
	 */
	private $engine;

	/**
	 * @var ApprovalRouteFlowStarter&MockObject
	 */
	private $flows;

	/**
	 * @param array<string, mixed> $params The request.
	 *
	 * @return ApprovalRouteController The controller.
	 */
	private function controller(array $params): ApprovalRouteController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->engine = $this->createMock(ApprovalRouteServiceInterface::class);
		$this->flows  = $this->createMock(ApprovalRouteFlowStarter::class);

		return new ApprovalRouteController(
			$request,
			$this->engine,
			$this->createMock(ApprovalRouteConclusionAnnouncerInterface::class),
			$this->createMock(SubjectClearanceServiceInterface::class),
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			$this->flows
		);
	}//end controller()

	/**
	 * A template route sent for a decision starts a run and writes no decidiq stage.
	 */
	public function testATemplateRouteForADecisionStartsAFlowRun(): void {
		$controller = $this->controller(['route' => self::ROUTE, 'subject' => 'd-1', 'subjectSchema' => 'decision']);
		$this->engine->expects($this->once())->method('assertSubjectAccessible')->with('d-1', 'decision');
		$this->engine->method('stagesFor')->willReturn([]);
		$this->engine->expects($this->never())->method('instantiate');
		$this->flows->expects($this->once())->method('start')->with(self::ROUTE, 'd-1')->willReturn(['run' => 'run-42', 'flow' => 'flow-9']);

		$response = $controller->instantiate();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['stages' => [], 'run' => 'run-42'], $response->getData());
	}//end testATemplateRouteForADecisionStartsAFlowRun()

	/**
	 * Scenario: a half-way route is not restarted; it continues on the old engine and no run is created.
	 */
	public function testADecisionWithStagesOnTheOldEngineStaysThere(): void {
		$controller = $this->controller(['route' => self::ROUTE, 'subject' => 'd-1', 'subjectSchema' => 'decision']);
		$stages     = [['id' => 'stage-3', 'status' => 'active']];
		$this->engine->method('stagesFor')->willReturn($stages);
		$this->engine->expects($this->once())->method('instantiate')->willReturn($stages);
		$this->flows->expects($this->never())->method('start');

		$this->assertSame(['stages' => $stages], $controller->instantiate()->getData());
	}//end testADecisionWithStagesOnTheOldEngineStaysThere()

	/**
	 * An ad-hoc route is held from its people, as before.
	 */
	public function testAnAdhocRouteIsNotAFlow(): void {
		$controller = $this->controller(['route' => ['origin' => 'adhoc'] + self::ROUTE, 'subject' => 'd-1', 'subjectSchema' => 'decision']);
		$this->engine->method('stagesFor')->willReturn([]);
		$this->flows->expects($this->never())->method('start');

		$this->assertArrayNotHasKey('run', (array)$controller->instantiate()->getData());
	}//end testAnAdhocRouteIsNotAFlow()

	/**
	 * The starter's refusal reaches the caller as a 400 with its reason.
	 */
	public function testARefusedSendAnswers400WithTheReason(): void {
		$controller = $this->controller(['route' => self::ROUTE, 'subject' => 'd-1', 'subjectSchema' => 'decision']);
		$this->engine->method('stagesFor')->willReturn([]);
		$this->flows->method('start')->willThrowException(new RuntimeException('This proposal is already on its route: step 1 is still open. It can be sent again once the route has concluded.'));

		$response = $controller->instantiate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('step 1 is still open', $response->getData()['message']);
	}//end testARefusedSendAnswers400WithTheReason()
}//end class
