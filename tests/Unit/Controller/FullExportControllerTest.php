<?php

/**
 * Unit tests for FullExportController: queueing, the latest export and its download.
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

use OCA\Decidiq\BackgroundJob\FullExportJob;
use OCA\Decidiq\Controller\FullExportController;
use OCA\Decidiq\Kernel\Contracts\FullExportServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FullExportController.
 *
 * @covers \OCA\Decidiq\Controller\FullExportController
 */
class FullExportControllerTest extends TestCase {

	/**
	 * The export service double.
	 *
	 * @var FullExportServiceInterface&MockObject
	 */
	private FullExportServiceInterface&MockObject $exports;

	/**
	 * The job list double.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobs;

	/**
	 * A controller for this caller.
	 *
	 * @param string|null $uid The caller, or null.
	 *
	 * @return FullExportController The controller under test.
	 */
	private function controller(?string $uid = 'admin'): FullExportController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->exports = $this->createMock(FullExportServiceInterface::class);
		$this->jobs = $this->createMock(IJobList::class);

		return new FullExportController(
			request: $this->createMock(IRequest::class),
			exportService: $this->exports,
			jobList: $this->jobs,
			userSession: $session,
		);
	}//end controller()

	/**
	 * Starting queues one job for the caller and answers 202.
	 *
	 * @return void
	 */
	public function testStartQueuesAJobForTheCaller(): void {
		$controller = $this->controller();
		$this->jobs->expects(self::once())->method('add')->with(FullExportJob::class, ['uid' => 'admin']);

		$response = $controller->start();

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		self::assertSame(['queued' => true], $response->getData());
	}//end testStartQueuesAJobForTheCaller()

	/**
	 * Without a signed-in caller nothing is queued.
	 *
	 * @return void
	 */
	public function testStartWithoutACallerIsUnauthorised(): void {
		$controller = $this->controller(uid: null);
		$this->jobs->expects(self::never())->method('add');

		$response = $controller->start();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['message' => 'Authentication required.'], $response->getData());
	}//end testStartWithoutACallerIsUnauthorised()

	/**
	 * The latest export, or null, is passed through.
	 *
	 * @return void
	 */
	public function testLatestPassesTheServiceAnswerThrough(): void {
		$controller = $this->controller();
		$latest = ['name' => 'decidiq-export-1.zip', 'size' => 42, 'createdAt' => '2026-11-01T10:00:00+00:00'];
		$this->exports->method('latest')->willReturnOnConsecutiveCalls($latest, null);

		self::assertSame(['export' => $latest], $controller->latest()->getData());
		self::assertSame(['export' => null], $controller->latest()->getData());
	}//end testLatestPassesTheServiceAnswerThrough()

	/**
	 * A known export streams as a zip attachment under its own name.
	 *
	 * @return void
	 */
	public function testDownloadStreamsTheZip(): void {
		$controller = $this->controller();
		$stream = fopen('php://memory', 'r');
		$file = $this->createMock(ISimpleFile::class);
		$file->method('read')->willReturn($stream);
		$file->method('getName')->willReturn('decidiq-export-1.zip');
		$this->exports->expects(self::once())->method('open')->with('decidiq-export-1.zip')->willReturn($file);

		$response = $controller->download(name: 'decidiq-export-1.zip');

		self::assertInstanceOf(StreamResponse::class, $response);
		// getHeaders() reaches the server container; the headers set are read directly.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('application/zip', $headers['Content-Type']);
		self::assertSame('attachment; filename="decidiq-export-1.zip"', $headers['Content-Disposition']);
		fclose($stream);
	}//end testDownloadStreamsTheZip()

	/**
	 * An unknown export is not found.
	 *
	 * @return void
	 */
	public function testAnUnknownExportIsNotFound(): void {
		$controller = $this->controller();
		$this->exports->method('open')->willThrowException(new NotFoundException('nope'));

		$response = $controller->download(name: '../etc/passwd');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['message' => 'No such export.'], $response->getData());
	}//end testAnUnknownExportIsNotFound()
}//end class
