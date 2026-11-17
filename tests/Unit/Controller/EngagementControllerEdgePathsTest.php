<?php

/**
 * Unit tests for EngagementController::capture() refusal and failure paths.
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
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\EngagementController;
use OCA\Decidiq\Kernel\Contracts\EngagementServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Missing fields, a failing participant lookup and a service refusal.
 */
#[CoversClass(EngagementController::class)]
final class EngagementControllerEdgePathsTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock engagement service.
	 *
	 * @var EngagementServiceInterface&MockObject
	 */
	private EngagementServiceInterface&MockObject $engagementService;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock participant resolver.
	 *
	 * @var ParticipantResolverInterface&MockObject
	 */
	private ParticipantResolverInterface&MockObject $participantResolver;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request             = $this->createMock(IRequest::class);
		$this->engagementService   = $this->createMock(EngagementServiceInterface::class);
		$this->groupManager        = $this->createMock(IGroupManager::class);
		$this->container           = $this->createMock(ContainerInterface::class);
		$this->participantResolver = $this->createMock(ParticipantResolverInterface::class);

	}//end setUp()

	/**
	 * Build the controller for an authenticated caller.
	 *
	 * @param string $uid The caller's UID.
	 *
	 * @return EngagementController
	 */
	private function controllerFor(string $uid): EngagementController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new EngagementController(
			request: $this->request,
			engagementService: $this->engagementService,
			userSession: $session,
			groupManager: $this->groupManager,
			container: $this->container,
			participantResolver: $this->participantResolver,
		);

	}//end controllerFor()

	/**
	 * Stub the request parameters.
	 *
	 * @param array<string,mixed> $params The parameters by name.
	 *
	 * @return void
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => ($params[$key] ?? $default)
		);

	}//end stubParams()

	/**
	 * A capture without eventType is refused with 422 before any check runs.
	 *
	 * @return void
	 */
	public function testCaptureWithAMissingFieldIsUnprocessable(): void {
		$this->stubParams(['meeting' => 'm-1', 'participant' => 'p-1']);
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->engagementService->expects($this->never())->method('captureEngagement');

		$result = $this->controllerFor('alice')->capture();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		self::assertSame(['message' => 'Missing required fields: meeting, participant, eventType.'], $result->getData());

	}//end testCaptureWithAMissingFieldIsUnprocessable()

	/**
	 * When the participant lookup throws, the caller resolves to no participant
	 * and recording for anyone is refused (fail-closed).
	 *
	 * @return void
	 */
	public function testCaptureIsForbiddenWhenTheParticipantLookupFails(): void {
		$this->stubParams(['meeting' => 'm-1', 'participant' => 'p-1', 'eventType' => 'speech']);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->method('hasRole')->willReturn(false);
		$this->container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willThrowException(new \RuntimeException('OpenRegister unavailable'));
		$this->engagementService->expects($this->never())->method('captureEngagement');

		$result = $this->controllerFor('alice')->capture();

		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
		self::assertSame(['message' => 'You may only record engagement for your own participant record.'], $result->getData());

	}//end testCaptureIsForbiddenWhenTheParticipantLookupFails()

	/**
	 * A chair may record for any participant; a service refusal becomes a 422
	 * carrying the service's message, and the event data is passed through.
	 *
	 * @return void
	 */
	public function testCaptureMapsAServiceRefusalToUnprocessable(): void {
		$this->stubParams([
			'meeting' => 'm-1',
			'participant' => 'p-2',
			'eventType' => 'dance',
			'eventData' => ['duration' => 12],
		]);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->participantResolver->expects($this->once())
			->method('hasRole')
			->with('m-1', 'chair-uid', ['chair', 'secretary'])
			->willReturn(true);
		$this->container->expects($this->never())->method('get');
		$this->engagementService->expects($this->once())
			->method('captureEngagement')
			->with('m-1', 'p-2', 'dance', ['duration' => 12])
			->willThrowException(new \InvalidArgumentException('Unknown event type: dance'));

		$result = $this->controllerFor('chair-uid')->capture();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		self::assertSame(['message' => 'Unknown event type: dance'], $result->getData());

	}//end testCaptureMapsAServiceRefusalToUnprocessable()
}//end class
