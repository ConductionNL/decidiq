<?php

/**
 * Unit tests for AgendaController — success, validation and error paths.
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

use OCA\Decidiq\Controller\AgendaController;
use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Service\AgendaAuthorizationGuard;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\CurrentAgendaItemService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AgendaController behaviour once the caller is authorised.
 *
 * @covers \OCA\Decidiq\Controller\AgendaController
 */
class AgendaControllerErrorPathsTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock AgendaService.
	 *
	 * @var AgendaService&MockObject
	 */
	private AgendaService&MockObject $agendaService;

	/**
	 * Mock CurrentAgendaItemService.
	 *
	 * @var CurrentAgendaItemService&MockObject
	 */
	private CurrentAgendaItemService&MockObject $currentItems;

	/**
	 * Mock authorization guard.
	 *
	 * @var AgendaAuthorizationGuard&MockObject
	 */
	private AgendaAuthorizationGuard&MockObject $guard;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var AgendaController
	 */
	private AgendaController $controller;

	/**
	 * Set up shared mocks; the guard authorises by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->agendaService = $this->createMock(AgendaService::class);
		$this->currentItems = $this->createMock(CurrentAgendaItemService::class);
		$this->guard = $this->createMock(AgendaAuthorizationGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->guard->method('requireUser')->willReturn(null);

		$this->controller = new AgendaController(
			request: $this->request,
			agendaService: $this->agendaService,
			currentItems: $this->currentItems,
			guard: $this->guard,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Expect exactly one error log mentioning the given message for the given id.
	 *
	 * @param string $template The log template.
	 * @param string $idKey    The context key for the id.
	 * @param string $id       The id expected in context.
	 * @param string $error    The exception message expected in context.
	 *
	 * @return void
	 */
	private function expectErrorLogged(string $template, string $idKey, string $id, string $error): void {
		$this->logger->expects($this->once())
			->method('error')
			->with(
				$template,
				$this->callback(
					static function (array $context) use ($idKey, $id, $error): bool {
						return $context[$idKey] === $id
							&& $context['error'] === $error
							&& $context['exception'] instanceof \Throwable;
					}
				)
			);

	}//end expectErrorLogged()

	/**
	 * Assert a generic 500 response.
	 *
	 * @param JSONResponse $result The response.
	 *
	 * @return void
	 */
	private function assertInternalError(JSONResponse $result): void {
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		self::assertSame(['message' => 'An internal error occurred.'], $result->getData());

	}//end assertInternalError()

	/**
	 * A chair publishes the agenda.
	 *
	 * @return void
	 */
	public function testPublishSucceeds(): void {
		$this->guard->expects($this->once())->method('requireChairOrAdmin')->with('m-1')->willReturn(null);
		$this->agendaService->expects($this->once())->method('publishAgenda')->with('m-1');

		$result = $this->controller->publish('m-1');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame(['success' => true], $result->getData());

	}//end testPublishSucceeds()

	/**
	 * A forbidden response from the guard is returned as-is.
	 *
	 * @return void
	 */
	public function testPublishForbiddenReturnsGuardResponse(): void {
		$denied = new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		$this->guard->method('requireChairOrAdmin')->willReturn($denied);
		$this->agendaService->expects($this->never())->method('publishAgenda');

		self::assertSame($denied, $this->controller->publish('m-1'));

	}//end testPublishForbiddenReturnsGuardResponse()

	/**
	 * A validation failure on publish is a 422 with the reason.
	 *
	 * @return void
	 */
	public function testPublishValidationFailureIs422(): void {
		$this->agendaService->method('publishAgenda')->willThrowException(new \InvalidArgumentException('No agenda items.'));
		$this->logger->expects($this->never())->method('error');

		$result = $this->controller->publish('m-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		self::assertSame(['message' => 'No agenda items.'], $result->getData());

	}//end testPublishValidationFailureIs422()

	/**
	 * An unexpected failure on publish is logged and hidden behind a 500.
	 *
	 * @return void
	 */
	public function testPublishUnexpectedFailureIs500(): void {
		$this->agendaService->method('publishAgenda')->willThrowException(new \RuntimeException('db down'));
		$this->expectErrorLogged('publishAgenda failed for meeting {id}: {error}', 'id', 'm-1', 'db down');

		$this->assertInternalError($this->controller->publish('m-1'));

	}//end testPublishUnexpectedFailureIs500()

	/**
	 * A chair advances the BOB phase.
	 *
	 * @return void
	 */
	public function testAdvanceBobPhaseSucceeds(): void {
		$this->guard->expects($this->once())->method('requireChairOrAdminForAgendaItem')->with('i-1')->willReturn(null);
		$this->agendaService->expects($this->once())->method('advanceBobPhase')->with('i-1');

		$result = $this->controller->advanceBobPhase('i-1');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame(['success' => true], $result->getData());

	}//end testAdvanceBobPhaseSucceeds()

	/**
	 * A non-chair is refused before the phase is advanced.
	 *
	 * @return void
	 */
	public function testAdvanceBobPhaseForbidden(): void {
		$denied = new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		$this->guard->method('requireChairOrAdminForAgendaItem')->willReturn($denied);
		$this->agendaService->expects($this->never())->method('advanceBobPhase');

		self::assertSame($denied, $this->controller->advanceBobPhase('i-1'));

	}//end testAdvanceBobPhaseForbidden()

	/**
	 * An unknown agenda item reads as 404, even when the guard is the one that cannot find it.
	 *
	 * @return void
	 */
	public function testAdvanceBobPhaseNotFoundIs404(): void {
		$this->guard->method('requireChairOrAdminForAgendaItem')->willThrowException(new NotFoundException('Agenda item not found'));
		$this->agendaService->expects($this->never())->method('advanceBobPhase');

		$result = $this->controller->advanceBobPhase('i-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		self::assertSame(['message' => 'Agenda item not found'], $result->getData());

	}//end testAdvanceBobPhaseNotFoundIs404()

	/**
	 * An invalid phase transition is a 422.
	 *
	 * @return void
	 */
	public function testAdvanceBobPhaseInvalidIs422(): void {
		$this->guard->method('requireChairOrAdminForAgendaItem')->willReturn(null);
		$this->agendaService->method('advanceBobPhase')->willThrowException(new \InvalidArgumentException('Already in final phase'));

		$result = $this->controller->advanceBobPhase('i-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		self::assertSame(['message' => 'Already in final phase'], $result->getData());

	}//end testAdvanceBobPhaseInvalidIs422()

	/**
	 * An unexpected failure advancing the phase is a logged 500.
	 *
	 * @return void
	 */
	public function testAdvanceBobPhaseUnexpectedFailureIs500(): void {
		$this->guard->method('requireChairOrAdminForAgendaItem')->willReturn(null);
		$this->agendaService->method('advanceBobPhase')->willThrowException(new \RuntimeException('boom'));
		$this->expectErrorLogged('advanceBobPhase failed for item {id}: {error}', 'id', 'i-1', 'boom');

		$this->assertInternalError($this->controller->advanceBobPhase('i-1'));

	}//end testAdvanceBobPhaseUnexpectedFailureIs500()

	/**
	 * A failure adopting formalities is a logged 500.
	 *
	 * @return void
	 */
	public function testProcessHamerstukkenFailureIs500(): void {
		$this->agendaService->method('processHamerstukken')->willThrowException(new \RuntimeException('boom'));
		$this->expectErrorLogged('processHamerstukken failed for meeting {meetingId}: {error}', 'meetingId', 'm-1', 'boom');

		$this->assertInternalError($this->controller->processHamerstukken('m-1'));

	}//end testProcessHamerstukkenFailureIs500()

	/**
	 * A chair opens the agenda for revision.
	 *
	 * @return void
	 */
	public function testReviseSucceeds(): void {
		$this->agendaService->expects($this->once())->method('reviseAgenda')->with('m-1');

		$result = $this->controller->revise('m-1');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame(['success' => true], $result->getData());

	}//end testReviseSucceeds()

	/**
	 * A failure revising is a logged 500.
	 *
	 * @return void
	 */
	public function testReviseFailureIs500(): void {
		$this->agendaService->method('reviseAgenda')->willThrowException(new \RuntimeException('boom'));
		$this->expectErrorLogged('reviseAgenda failed for meeting {meetingId}: {error}', 'meetingId', 'm-1', 'boom');

		$this->assertInternalError($this->controller->revise('m-1'));

	}//end testReviseFailureIs500()

	/**
	 * Reordering passes the ids through in order.
	 *
	 * @return void
	 */
	public function testReorderSucceeds(): void {
		$this->request->method('getParams')->willReturn(['ids' => ['b', 'a', 'c']]);
		$this->agendaService->expects($this->once())->method('reorderItems')->with('m-1', ['b', 'a', 'c']);

		$result = $this->controller->reorder('m-1');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame(['success' => true], $result->getData());

	}//end testReorderSucceeds()

	/**
	 * Reordering without ids is a 400.
	 *
	 * @return void
	 */
	public function testReorderWithoutIdsIs400(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->agendaService->expects($this->never())->method('reorderItems');

		$result = $this->controller->reorder('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
		self::assertSame(['message' => 'ids array is required'], $result->getData());

	}//end testReorderWithoutIdsIs400()

	/**
	 * Reordering with ids that are not an array is a 400.
	 *
	 * @return void
	 */
	public function testReorderWithNonArrayIdsIs400(): void {
		$this->request->method('getParams')->willReturn(['ids' => 'a,b']);
		$this->agendaService->expects($this->never())->method('reorderItems');

		$result = $this->controller->reorder('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
		self::assertSame(['message' => 'ids array is required'], $result->getData());

	}//end testReorderWithNonArrayIdsIs400()

	/**
	 * A failure reordering is a logged 500.
	 *
	 * @return void
	 */
	public function testReorderFailureIs500(): void {
		$this->request->method('getParams')->willReturn(['ids' => ['a']]);
		$this->agendaService->method('reorderItems')->willThrowException(new \RuntimeException('boom'));
		$this->expectErrorLogged('reorderItems failed for meeting {meetingId}: {error}', 'meetingId', 'm-1', 'boom');

		$this->assertInternalError($this->controller->reorder('m-1'));

	}//end testReorderFailureIs500()

	/**
	 * Taking the formality mark off parses "false" to boolean false.
	 *
	 * @return void
	 */
	public function testFormalityFalseStringUnmarks(): void {
		$this->request->method('getParam')->with('isFormality')->willReturn('false');
		$this->agendaService->expects($this->once())->method('setFormality')->with('m-1', 'i-1', false);

		$result = $this->controller->formality('m-1', 'i-1');

		self::assertSame(['success' => true, 'isFormality' => false], $result->getData());

	}//end testFormalityFalseStringUnmarks()

	/**
	 * A failure marking a formality is a logged 500.
	 *
	 * @return void
	 */
	public function testFormalityFailureIs500(): void {
		$this->request->method('getParam')->willReturn(true);
		$this->agendaService->method('setFormality')->willThrowException(new \RuntimeException('boom'));
		$this->expectErrorLogged('setFormality failed for meeting {meetingId}: {error}', 'meetingId', 'm-1', 'boom');

		$this->assertInternalError($this->controller->formality('m-1', 'i-1'));

	}//end testFormalityFailureIs500()

	/**
	 * A failure setting the current item is a logged 500.
	 *
	 * @return void
	 */
	public function testCurrentItemFailureIs500(): void {
		$this->request->method('getParam')->willReturn('i-1');
		$this->currentItems->method('setCurrentItem')->willThrowException(new \RuntimeException('boom'));
		$this->expectErrorLogged('setCurrentItem failed for meeting {meetingId}: {error}', 'meetingId', 'm-1', 'boom');

		$this->assertInternalError($this->controller->currentItem('m-1'));

	}//end testCurrentItemFailureIs500()

	/**
	 * A missing agendaItem parameter is passed on as an empty id.
	 *
	 * @return void
	 */
	public function testCurrentItemMissingParamPassesEmptyId(): void {
		$this->request->method('getParam')->with('agendaItem')->willReturn(null);
		$this->currentItems->expects($this->once())->method('setCurrentItem')->with('m-1', '')
			->willThrowException(new \InvalidArgumentException('agendaItem is required'));

		$result = $this->controller->currentItem('m-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
		self::assertSame(['message' => 'agendaItem is required'], $result->getData());

	}//end testCurrentItemMissingParamPassesEmptyId()

}//end class
