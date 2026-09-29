<?php

/**
 * Contract tests for the export-with-attachments endpoints.
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

use OCA\Decidiq\Controller\ExportBundleController;
use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Service\ExportBundleService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Controller\ExportBundleController
 * @uses   \OCA\Decidiq\Exception\ExportBundleException
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */
class ExportBundleControllerTest extends TestCase {

	/**
	 * The controller over a service double and request parameters.
	 *
	 * @param ExportBundleService  $exports The service.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return ExportBundleController
	 */
	private function controller(ExportBundleService $exports, array $params=[]): ExportBundleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		return new ExportBundleController($request, $exports, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * GET formats says a ZIP always works and one PDF only with filinq.
	 *
	 * @return void
	 */
	public function testFormatsSaysWhetherOnePdfCanBeMade(): void {
		$exports = $this->createMock(ExportBundleService::class);
		$exports->method('pdfAvailable')->willReturn(false);

		$response = $this->controller($exports)->formats();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['zip' => true, 'pdf' => false], $response->getData());
	}//end testFormatsSaysWhetherOnePdfCanBeMade()

	/**
	 * POST create passes the request through and answers 201, or 202 when queued.
	 *
	 * @return void
	 */
	public function testCreateAnswersCreatedOrAccepted(): void {
		$exports = $this->createMock(ExportBundleService::class);
		$exports->expects(self::exactly(2))->method('export')
			->with('zip', 'Motions', ['m-1', 'm-2'], [], '')
			->willReturnOnConsecutiveCalls(
				['status' => 'done', 'format' => 'zip', 'name' => 'Motions 2026-10-20.zip', 'path' => 'Decidiq exports/Motions 2026-10-20.zip', 'fileId' => 5],
				['status' => 'queued', 'format' => 'zip', 'name' => 'Motions 2026-10-20.zip', 'path' => 'Decidiq exports/Motions 2026-10-20.zip', 'fileId' => null],
			);
		$controller = $this->controller($exports, ['format' => 'zip', 'list' => 'Motions', 'ids' => ['m-1', 'm-2']]);

		self::assertSame(201, $controller->create()->getStatus());
		self::assertSame(202, $controller->create()->getStatus());
	}//end testCreateAnswersCreatedOrAccepted()

	/**
	 * A refusal keeps its status and sentence; anything else is a plain 500.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatusAndSentence(): void {
		$exports = $this->createMock(ExportBundleService::class);
		$exports->method('export')->willReturnOnConsecutiveCalls(
			self::throwException(new ExportBundleException(message: 'You may not read annex.pdf.', status: 403)),
			self::throwException(new RuntimeException('disk full')),
		);
		$controller = $this->controller($exports, ['format' => 'pdf']);

		$refused = $controller->create();
		self::assertSame(403, $refused->getStatus());
		self::assertSame(['message' => 'You may not read annex.pdf.'], $refused->getData());

		$failed = $controller->create();
		self::assertSame(500, $failed->getStatus());
		self::assertStringNotContainsString('disk full', (string)json_encode($failed->getData()));
	}//end testARefusalKeepsItsStatusAndSentence()
}//end class
