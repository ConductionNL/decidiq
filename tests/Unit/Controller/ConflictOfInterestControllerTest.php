<?php

/**
 * Unit tests for ConflictOfInterestController.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Controller;

use OCA\Decidesk\Controller\ConflictOfInterestController;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConflictOfInterestController.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-4.3
 */
class ConflictOfInterestControllerTest extends TestCase {

	/**
	 * Build a controller wired with the given service double.
	 *
	 * @param ConflictOfInterestService $service Service double
	 * @param array<string, mixed> $requestParams Params returned by IRequest
	 * @param bool $authenticated Session has user
	 *
	 * @return ConflictOfInterestController
	 */
	private function makeController(
		ConflictOfInterestService $service,
		array $requestParams = [],
		bool $authenticated = true
	): ConflictOfInterestController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($requestParams);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($requestParams): mixed {
				return ($requestParams[$key] ?? $default);
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($authenticated === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		return new ConflictOfInterestController(
			request: $request,
			conflictService: $service,
			userSession: $session
		);
	}//end makeController()

	/**
	 * declare requires membershipId + agendaItemId.
	 *
	 * @return void
	 */
	public function testDeclareRequiresMemberAndAgenda(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$controller = $this->makeController(service: $service, requestParams: ['membershipId' => 'm1']);

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $controller->declare()->getStatus());

	}//end testDeclareRequiresMemberAndAgenda()

	/**
	 * declare returns 201 and forwards severity to service.
	 *
	 * @return void
	 */
	public function testDeclareSucceedsReturns201(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$service->expects($this->once())
			->method('declare')
			->with('m1', 'a1', 'financial-interest', 'shares', 'material')
			->willReturn(
				[
					'success' => true,
					'declaration' => ['id' => 'd1'],
					'message' => 'ok',
				]
			);

		$controller = $this->makeController(
			service: $service,
			requestParams: [
				'membershipId' => 'm1',
				'agendaItemId' => 'a1',
				'declarationType' => 'financial-interest',
				'description' => 'shares',
				'severity' => 'material',
			]
		);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $controller->declare()->getStatus());

	}//end testDeclareSucceedsReturns201()

	/**
	 * declare rejects a payload with agendaItemId but no membershipId.
	 *
	 * @return void
	 */
	public function testDeclareRequiresMembershipIdWhenAgendaGiven(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$service->expects($this->never())->method('declare');
		$controller = $this->makeController(service: $service, requestParams: ['agendaItemId' => 'a1']);

		$response = $controller->declare();

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(
			expected: "Missing required parameter 'membershipId' or 'agendaItemId'.",
			actual: $response->getData()['message']
		);

	}//end testDeclareRequiresMembershipIdWhenAgendaGiven()

	/**
	 * declare surfaces a service-level failure as 422 with the service message.
	 *
	 * @return void
	 */
	public function testDeclareServiceFailureReturns422(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$service->method('declare')->willReturn(
			[
				'success' => false,
				'declaration' => null,
				'message' => 'Unknown declaration type: bogus',
			]
		);

		$controller = $this->makeController(
			service: $service,
			requestParams: [
				'membershipId' => 'm1',
				'agendaItemId' => 'a1',
				'declarationType' => 'bogus',
			]
		);

		$response = $controller->declare();

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: 'Unknown declaration type: bogus', actual: $response->getData()['message']);

	}//end testDeclareServiceFailureReturns422()

	/**
	 * forMember requires agendaItemId; returns the conflict wrapper.
	 *
	 * @return void
	 */
	public function testForMemberReturnsConflict(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$service->expects($this->once())
			->method('getActiveConflicts')
			->with('m1', 'a1')
			->willReturn(['id' => 'd1', 'actionTaken' => 'recused-from-vote']);

		$controller = $this->makeController(service: $service, requestParams: ['agendaItemId' => 'a1']);
		$response = $controller->forMember('m1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'recused-from-vote', actual: $response->getData()['conflict']['actionTaken']);

	}//end testForMemberReturnsConflict()

	/**
	 * forMember rejects a request missing agendaItemId.
	 *
	 * @return void
	 */
	public function testForMemberRequiresAgendaItem(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$service->expects($this->never())->method('getActiveConflicts');
		$controller = $this->makeController(service: $service);

		$response = $controller->forMember('m1');

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(
			expected: "Missing required parameter 'agendaItemId'.",
			actual: $response->getData()['message']
		);

	}//end testForMemberRequiresAgendaItem()

	/**
	 * recordAction requires actionTaken body param.
	 *
	 * @return void
	 */
	public function testRecordActionRequiresActionParam(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$controller = $this->makeController(service: $service);

		$this->assertSame(
			expected: Http::STATUS_UNPROCESSABLE_ENTITY,
			actual: $controller->recordAction('d1')->getStatus()
		);

	}//end testRecordActionRequiresActionParam()

	/**
	 * recordAction returns 200 with the declaration payload on success.
	 *
	 * @return void
	 */
	public function testRecordActionSucceedsReturns200(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$service->expects($this->once())
			->method('recordAction')
			->with('d1', 'recused-from-vote')
			->willReturn(
				[
					'success' => true,
					'declaration' => ['id' => 'd1', 'actionTaken' => 'recused-from-vote'],
					'message' => 'Action recorded.',
				]
			);

		$controller = $this->makeController(
			service: $service,
			requestParams: ['actionTaken' => 'recused-from-vote']
		);
		$response = $controller->recordAction('d1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'recused-from-vote', actual: $response->getData()['actionTaken']);

	}//end testRecordActionSucceedsReturns200()

	/**
	 * recordAction surfaces a "not found" service failure as 404.
	 *
	 * @return void
	 */
	public function testRecordActionNotFoundReturns404(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$service->method('recordAction')->willReturn(
			[
				'success' => false,
				'declaration' => null,
				'message' => 'Declaration not found.',
			]
		);

		$controller = $this->makeController(
			service: $service,
			requestParams: ['actionTaken' => 'recused-from-vote']
		);
		$response = $controller->recordAction('missing-id');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: 'Declaration not found.', actual: $response->getData()['message']);

	}//end testRecordActionNotFoundReturns404()

	/**
	 * Anonymous access rejected.
	 *
	 * @return void
	 */
	public function testAnonymousAccessRejected(): void {
		$service = $this->createMock(originalClassName: ConflictOfInterestService::class);
		$controller = $this->makeController(service: $service, authenticated: false);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->declare()->getStatus());
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->forMember('m1')->getStatus());
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->recordAction('d1')->getStatus());

	}//end testAnonymousAccessRejected()

}//end class
