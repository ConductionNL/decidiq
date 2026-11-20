<?php

/**
 * The urgent procedure endpoints pass the caller and the request to the services.
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
 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\UrgentProcedureController;
use OCA\Decidiq\Service\ExpeditedRoundService;
use OCA\Decidiq\Service\UrgencyDeclarationService;
use OCA\Decidiq\Service\UrgentReportService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * 401 without a user, otherwise the service's status and body verbatim.
 *
 * @covers \OCA\Decidiq\Controller\UrgentProcedureController
 */
class UrgentProcedureControllerTest extends TestCase {

	/**
	 * The controller over a request with the given parameters.
	 *
	 * @param array<string, mixed>           $params       The request parameters.
	 * @param string|null                    $uid          The signed-in user, or null.
	 * @param UrgencyDeclarationService|null $declarations The declaration service.
	 * @param ExpeditedRoundService|null     $rounds       The round service.
	 *
	 * @return UrgentProcedureController
	 */
	private function controller(
		array $params,
		?string $uid,
		?UrgencyDeclarationService $declarations = null,
		?ExpeditedRoundService $rounds = null,
		?UrgentReportService $reports = null
	): UrgentProcedureController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new UrgentProcedureController(
			request: $request,
			userSession: $session,
			declarations: ($declarations ?? $this->createMock(UrgencyDeclarationService::class)),
			rounds: ($rounds ?? $this->createMock(ExpeditedRoundService::class)),
			reports: ($reports ?? $this->createMock(originalClassName: UrgentReportService::class)),
		);
	}//end controller()

	/**
	 * Without a user both endpoints answer 401 and call nothing.
	 *
	 * @return void
	 */
	public function testWithoutAUserBothAnswer401(): void {
		$declarations = $this->createMock(UrgencyDeclarationService::class);
		$declarations->expects($this->never())->method('declare');
		$rounds = $this->createMock(ExpeditedRoundService::class);
		$rounds->expects($this->never())->method('open');
		$controller = $this->controller(params: [], uid: null, declarations: $declarations, rounds: $rounds);

		$this->assertSame(401, $controller->declareUrgency(decisionId: 'dec-1')->getStatus());
		$this->assertSame(401, $controller->expeditedRound(decisionId: 'dec-1')->getStatus());
	}//end testWithoutAUserBothAnswer401()

	/**
	 * The declaration gets the caller, the reason and the override; the answer is the service's.
	 *
	 * @return void
	 */
	public function testTheDeclarationIsPassedThrough(): void {
		$declarations = $this->createMock(UrgencyDeclarationService::class);
		$declarations->expects($this->once())->method('declare')
			->with('dec-1', 'burgemeester', 'Acute crisis', 'body-raad', ['body' => 'body-college', 'office' => 'Burgemeester'])
			->willReturn(['status' => 403, 'data' => ['message' => 'no']]);

		$params   = [
			'reason' => 'Acute crisis',
			'ratifyingBody' => 'body-raad',
			'urgencyDeclaredByBody' => 'body-college',
			'urgencyDeclaredInOffice' => 'Burgemeester',
		];
		$response = $this->controller(params: $params, uid: 'burgemeester', declarations: $declarations)->declareUrgency(decisionId: 'dec-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'no'], $response->getData());
	}//end testTheDeclarationIsPassedThrough()

	/**
	 * A non-string override is ignored.
	 *
	 * @return void
	 */
	public function testANonStringOverrideIsIgnored(): void {
		$declarations = $this->createMock(UrgencyDeclarationService::class);
		$declarations->expects($this->once())->method('declare')
			->with('dec-1', 'burgemeester', '', null, ['body' => null, 'office' => null])
			->willReturn(['status' => 422, 'data' => []]);

		$this->controller(params: ['ratifyingBody' => ['x']], uid: 'burgemeester', declarations: $declarations)->declareUrgency(decisionId: 'dec-1');
	}//end testANonStringOverrideIsIgnored()

	/**
	 * The round gets the caller and the hours as sent; the answer is the service's.
	 *
	 * @return void
	 */
	public function testTheRoundIsPassedThrough(): void {
		$rounds = $this->createMock(ExpeditedRoundService::class);
		$rounds->expects($this->once())->method('open')->with('dec-2', 'ceo', 24)->willReturn(['status' => 201, 'data' => ['id' => 'round-1']]);

		$response = $this->controller(params: ['responseDeadlineHours' => 24], uid: 'ceo', rounds: $rounds)->expeditedRound(decisionId: 'dec-2');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 'round-1'], $response->getData());
	}//end testTheRoundIsPassedThrough()

	/**
	 * The report action answers 401 without a user and asks nothing of the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	public function testTheReportActionNeedsAUser(): void {
		$reports = $this->createMock(originalClassName: UrgentReportService::class);
		$reports->expects($this->never())->method('report');

		$this->assertSame(expected: 401, actual: $this->controller(params: [], uid: null, reports: $reports)->reported(decisionId: 'dec-1')->getStatus());
	}//end testTheReportActionNeedsAUser()

	/**
	 * The report action passes the caller through and answers as the service does,
	 * a refusal included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	public function testTheReportIsPassedThrough(): void {
		$reports = $this->createMock(originalClassName: UrgentReportService::class);
		$reports->expects($this->once())->method('report')->with('dec-1', 'raadslid')->willReturn(['status' => 403, 'data' => ['message' => 'no']]);

		$response = $this->controller(params: [], uid: 'raadslid', reports: $reports)->reported(decisionId: 'dec-1');

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'no'], actual: $response->getData());
	}//end testTheReportIsPassedThrough()
}//end class
