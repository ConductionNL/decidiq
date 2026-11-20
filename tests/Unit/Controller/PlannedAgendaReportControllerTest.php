<?php

/**
 * Tests for the long-term agenda report endpoint.
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

use OCA\Decidiq\Controller\PlannedAgendaReportController;
use OCA\Decidiq\Exception\PlannedAgendaReportException;
use OCA\Decidiq\Service\PlannedAgendaReportService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Controller\PlannedAgendaReportController
 * @uses   \OCA\Decidiq\Exception\PlannedAgendaReportException
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
class PlannedAgendaReportControllerTest extends TestCase {

	/**
	 * The controller over a service double and request parameters.
	 *
	 * @param PlannedAgendaReportService $reports The service.
	 * @param array<string,mixed>        $params  The request parameters.
	 * @param LoggerInterface|null       $logger  The logger.
	 *
	 * @return PlannedAgendaReportController
	 */
	private function controller(PlannedAgendaReportService $reports, array $params=[], ?LoggerInterface $logger=null): PlannedAgendaReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		return new PlannedAgendaReportController($request, $reports, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end controller()

	/**
	 * The filter and search text go to the service; the saved report answers 201.
	 *
	 * @return void
	 */
	public function testCreatePassesTheFiltersAndAnswersCreated(): void {
		$result  = ['format' => 'pdf', 'name' => 'r.pdf', 'path' => '/Decidiq/Reports/r.pdf', 'fileId' => 3, 'count' => 2, 'note' => null];
		$reports = $this->createMock(PlannedAgendaReportService::class);
		$reports->expects(self::once())->method('render')
			->with(['owner' => 'de-boer'], 'warmte')
			->willReturn($result);

		$response = $this->controller($reports, ['filter' => ['owner' => 'de-boer'], 'search' => 'warmte'])->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame($result, $response->getData());
	}//end testCreatePassesTheFiltersAndAnswersCreated()

	/**
	 * A filter that is not an object is read as no filter.
	 *
	 * @return void
	 */
	public function testANonArrayFilterIsReadAsNoFilter(): void {
		$reports = $this->createMock(PlannedAgendaReportService::class);
		$reports->expects(self::once())->method('render')->with([], '')->willReturn(['format' => 'pdf']);

		self::assertSame(201, $this->controller($reports, ['filter' => 'owner=x'])->create()->getStatus());
	}//end testANonArrayFilterIsReadAsNoFilter()

	/**
	 * A refusal answers its own status and sentence.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsStatus(): void {
		$reports = $this->createMock(PlannedAgendaReportService::class);
		$reports->method('render')->willThrowException(new PlannedAgendaReportException(message: 'No items match these filters', status: 422));

		$response = $this->controller($reports)->create();

		self::assertSame(422, $response->getStatus());
		self::assertSame(['message' => 'No items match these filters'], $response->getData());
	}//end testARefusalAnswersItsStatus()

	/**
	 * An unexpected failure is logged and answers 500 without its cause.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsLoggedAndAnswers500(): void {
		$reports = $this->createMock(PlannedAgendaReportService::class);
		$reports->method('render')->willThrowException(new RuntimeException('disk on fire'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Decidiq long-term agenda report: the report failed', self::arrayHasKey('exception'));

		$response = $this->controller($reports, [], $logger)->create();

		self::assertSame(500, $response->getStatus());
		self::assertStringNotContainsString('disk on fire', (string)json_encode($response->getData()));
	}//end testAnUnexpectedFailureIsLoggedAndAnswers500()
}//end class
