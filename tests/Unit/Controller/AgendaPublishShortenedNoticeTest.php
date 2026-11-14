<?php

/**
 * The agenda publish endpoint hands a confirmed shortened notice to the service.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Decidiq\Controller\AgendaController;
use OCA\Decidiq\Kernel\Contracts\AgendaAuthorizationGuardInterface;
use OCA\Decidiq\Kernel\Contracts\AgendaServiceInterface;
use OCA\Decidiq\Kernel\Contracts\CurrentAgendaItemServiceInterface;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regular publish passes null; a confirmed shortened notice passes its reason; a refusal is 422.
 *
 * @covers \OCA\Decidiq\Controller\AgendaController
 */
class AgendaPublishShortenedNoticeTest extends TestCase {

	/**
	 * The controller for an authorised chair, over request parameters.
	 *
	 * @param array<string, mixed>   $params  The request parameters.
	 * @param AgendaServiceInterface $service The agenda service.
	 *
	 * @return AgendaController
	 */
	private function controller(array $params, AgendaServiceInterface $service): AgendaController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$guard = $this->createMock(AgendaAuthorizationGuardInterface::class);
		$guard->method('requireUser')->willReturn(null);
		$guard->method('requireChairOrAdmin')->willReturn(null);

		return new AgendaController(
			request: $request,
			agendaService: $service,
			currentItems: $this->createMock(CurrentAgendaItemServiceInterface::class),
			guard: $guard,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * Without shortenedNotice the publish is the regular one.
	 *
	 * @return void
	 */
	public function testARegularPublishPassesNoDeviation(): void {
		$service = $this->createMock(AgendaServiceInterface::class);
		$service->expects($this->once())->method('publishAgenda')->with('meet-1', null);

		$this->assertSame(200, $this->controller(params: [], service: $service)->publish('meet-1')->getStatus());
	}//end testARegularPublishPassesNoDeviation()

	/**
	 * A confirmed shortened notice passes its reason (empty when none is given).
	 *
	 * @return void
	 */
	public function testAConfirmedShortenedNoticePassesItsReason(): void {
		$service = $this->createMock(AgendaServiceInterface::class);
		$service->expects($this->exactly(2))->method('publishAgenda')->willReturnCallback(
			function (string $meetingId, ?string $reason) use (&$seen): void {
				$seen[] = $reason;
			}
		);
		$seen = [];

		$this->controller(params: ['shortenedNotice' => 'true', 'noticeDeviationReason' => 'Spoed'], service: $service)->publish('meet-1');
		$this->controller(params: ['shortenedNotice' => true], service: $service)->publish('meet-1');

		$this->assertSame(['Spoed', ''], $seen);
	}//end testAConfirmedShortenedNoticePassesItsReason()

	/**
	 * A refused deviation answers 422 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedDeviationIs422(): void {
		$service = $this->createMock(AgendaServiceInterface::class);
		$service->method('publishAgenda')->willThrowException(new InvalidArgumentException('The convocation was not sent: the notice may not be shorter than 48 hours.'));

		$response = $this->controller(params: ['shortenedNotice' => '1'], service: $service)->publish('meet-1');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['message' => 'The convocation was not sent: the notice may not be shorter than 48 hours.'], $response->getData());
	}//end testARefusedDeviationIs422()
}//end class
