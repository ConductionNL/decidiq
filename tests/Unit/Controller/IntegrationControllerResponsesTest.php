<?php


// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\IntegrationController;
use OCA\Decidiq\Service\DecisionIntegrationAuthorizationGuard;
use OCA\Decidiq\Service\DecisionIntegrationService;
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
 * Pins IntegrationController's responses: createDecision end to end, the
 * failure logging of every action and the subscription failure statuses.
 */
class IntegrationControllerResponsesTest extends TestCase {

	private IRequest&MockObject $request;

	private DecisionIntegrationService&MockObject $service;

	private LoggerInterface&MockObject $logger;

	private DecisionIntegrationAuthorizationGuard&MockObject $guard;

	/**
	 * Build the controller with or without a signed-in user `svc-dossiq`.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return IntegrationController
	 */
	private function controller(bool $signedIn=true): IntegrationController {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DecisionIntegrationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = $this->createMock(DecisionIntegrationAuthorizationGuard::class);
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('svc-dossiq');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new IntegrationController($this->request, $session, $this->service, $this->logger, $groups, $this->guard);
	}//end controller()

	/**
	 * A complete creation request body.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		return ['decisionType' => 'besluit', 'title' => 'T', 'text' => 'X', 'decisionDate' => '2026-06-01'];
	}//end body()

	/**
	 * Without a session, creating is 401 and nothing is read.
	 *
	 * @return void
	 */
	public function testCreateWithoutSessionIs401(): void {
		$controller = $this->controller(signedIn: false);
		$this->request->expects($this->never())->method('getParams');

		$response = $controller->createDecision();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Authentication required.'], $response->getData());
	}//end testCreateWithoutSessionIs401()

	/**
	 * Every empty required field is listed, in order; nothing is created.
	 *
	 * @return void
	 */
	public function testCreateListsMissingFields(): void {
		$controller = $this->controller();
		$this->request->method('getParams')->willReturn(['title' => '0', 'text' => 'X', 'decisionDate' => '']);
		$this->service->expects($this->never())->method('createDecision');

		$response = $controller->createDecision();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Missing required fields: decisionType, title, decisionDate'], $response->getData());
	}//end testCreateListsMissingFields()

	/**
	 * A created decision is 201 with the service result, created as the session user.
	 *
	 * @return void
	 */
	public function testCreateIs201(): void {
		$controller = $this->controller();
		$this->request->method('getParams')->willReturn($this->body());
		$this->service->expects($this->once())->method('createDecision')
			->with($this->body(), 'svc-dossiq')
			->willReturn(['success' => true, 'decision' => ['id' => 'd-1']]);

		$response = $controller->createDecision();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['success' => true, 'decision' => ['id' => 'd-1']], $response->getData());
	}//end testCreateIs201()

	/**
	 * A refused creation is 422 with the service message, or the fallback.
	 *
	 * @return void
	 */
	public function testCreateRefusalIs422(): void {
		$controller = $this->controller();
		$this->request->method('getParams')->willReturn($this->body());
		$this->service->method('createDecision')->willReturnOnConsecutiveCalls(
			['success' => false, 'message' => 'Unknown decision type.'],
			['success' => false]
		);

		$first = $controller->createDecision();
		$second = $controller->createDecision();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $first->getStatus());
		$this->assertSame(['message' => 'Unknown decision type.'], $first->getData());
		$this->assertSame(['message' => 'Failed to create decision.'], $second->getData());
	}//end testCreateRefusalIs422()

	/**
	 * A failing creation is logged with the actor and answered 500 without detail.
	 *
	 * @return void
	 */
	public function testCreateFailureIsLoggedAnd500(): void {
		$controller = $this->controller();
		$this->request->method('getParams')->willReturn($this->body());
		$this->service->method('createDecision')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'IntegrationController: createDecision failed',
			['exception' => 'db down', 'actor' => 'svc-dossiq']
		);

		$response = $controller->createDecision();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Internal server error.'], $response->getData());
	}//end testCreateFailureIsLoggedAnd500()

	/**
	 * A failing outcome read is logged with the id.
	 *
	 * @return void
	 */
	public function testOutcomeFailureIsLogged(): void {
		$controller = $this->controller();
		$this->guard->method('isAuthorizedToReadOutcome')->willReturn(true);
		$this->service->method('getOutcomeEnvelope')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')->with(
			'IntegrationController: getOutcome failed',
			['id' => 'd-1', 'exception' => 'boom']
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $controller->getOutcome('d-1')->getStatus());
	}//end testOutcomeFailureIsLogged()

	/**
	 * A failing subscription is logged with the id and answered 500.
	 *
	 * @return void
	 */
	public function testSubscribeFailureIsLoggedAnd500(): void {
		$controller = $this->controller();
		$this->guard->method('isAuthorizedToSubscribe')->willReturn(true);
		$this->request->method('getParam')->willReturn('https://dossiq.example/hook');
		$this->service->expects($this->once())->method('registerOutcomeCallback')
			->with('d-1', 'https://dossiq.example/hook', 'svc-dossiq')
			->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')->with(
			'IntegrationController: subscribe failed',
			['id' => 'd-1', 'exception' => 'boom']
		);

		$response = $controller->subscribe('d-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Internal server error.'], $response->getData());
	}//end testSubscribeFailureIsLoggedAnd500()

	/**
	 * Subscription refusals map their code to a status; the message falls back.
	 *
	 * @return void
	 */
	public function testSubscribeRefusalStatuses(): void {
		$cases = [
			[['success' => false, 'code' => 'not_found'], Http::STATUS_NOT_FOUND, 'Subscription failed.'],
			[['success' => false, 'code' => 'ssrf_rejected', 'message' => 'No.'], Http::STATUS_FORBIDDEN, 'No.'],
			[['success' => false, 'code' => 'other'], Http::STATUS_UNPROCESSABLE_ENTITY, 'Subscription failed.'],
			[['success' => false, 'code' => 7], Http::STATUS_UNPROCESSABLE_ENTITY, 'Subscription failed.'],
			[['success' => false], Http::STATUS_UNPROCESSABLE_ENTITY, 'Subscription failed.'],
		];
		foreach ($cases as [$result, $status, $message]) {
			$controller = $this->controller();
			$this->guard->method('isAuthorizedToSubscribe')->willReturn(true);
			$this->request->method('getParam')->willReturn('https://dossiq.example/hook');
			$this->service->method('registerOutcomeCallback')->willReturn($result);

			$response = $controller->subscribe('d-1');

			$this->assertSame($status, $response->getStatus(), json_encode($result));
			$this->assertSame(['message' => $message], $response->getData());
		}
	}//end testSubscribeRefusalStatuses()
}//end class
