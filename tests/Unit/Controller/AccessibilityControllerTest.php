<?php

/**
 * Unit tests for AccessibilityController.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\AccessibilityController;
use OCA\Decidiq\Service\DocumentAccessibilityScanService;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Support\OpenRegisterFiles;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The scan endpoint scans a paper of an object the caller may read, and nothing else.
 *
 * @covers \OCA\Decidiq\Controller\AccessibilityController
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */
class AccessibilityControllerTest extends TestCase {

	/**
	 * The scanner double.
	 *
	 * @var DocumentAccessibilityScanService&MockObject
	 */
	private DocumentAccessibilityScanService&MockObject $scanner;

	/**
	 * The find() calls: [id, rbac].
	 *
	 * @var list<array{0: string, 1: bool}>
	 */
	private array $finds = [];

	/**
	 * The controller for a request, over a register where the caller may read
	 * item-1 (agenda item of meeting-1, body-raad) and meeting-1, holding file
	 * 11 on item-1; item-secret exists but RBAC refuses it.
	 *
	 * @param array<string, mixed> $params   The request parameters.
	 * @param bool                 $loggedIn Whether there is a session user.
	 *
	 * @return AccessibilityController
	 */
	private function controller(array $params, bool $loggedIn=true): AccessibilityController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($loggedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$objects = [
			'item-1' => ['agenda-item', ['id' => 'item-1', 'meeting' => 'meeting-1']],
			'meeting-1' => ['meeting', ['id' => 'meeting-1', 'governanceBody' => 'body-raad']],
			'decision-1' => ['decision', ['id' => 'decision-1']],
		];
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null, bool $_rbac=true) use ($objects): ?ObjectEntity {
				$this->finds[] = [(string)$id, $_rbac];
				if ($id === 'item-secret') {
					throw new RuntimeException('Access denied');
				}

				if (isset($objects[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntity::class);
				$entity->method('getSchema')->willReturn($objects[$id][0]);
				$entity->method('getObject')->willReturn($objects[$id][1]);
				return $entity;
			}
		);

		$paper = $this->createMock(originalClassName: File::class);
		$paper->method('getId')->willReturn(11);
		$fileService = new class ($paper) {
			/**
			 * Constructor.
			 *
			 * @param File $paper The paper on item-1.
			 */
			public function __construct(private readonly File $paper) {
			}

			/**
			 * The files of an object.
			 *
			 * @param string $id The object id.
			 *
			 * @return list<File>
			 */
			public function getFiles(string $id): array {
				if ($id === 'item-1') {
					return [$this->paper];
				}

				return [];
			}
		};
		$files = $this->createMock(originalClassName: OpenRegisterFiles::class);
		$files->method('installed')->willReturn(true);
		$files->method('fileService')->willReturn($fileService);

		$this->scanner = $this->createMock(originalClassName: DocumentAccessibilityScanService::class);

		return new AccessibilityController(
			request: $request,
			userSession: $session,
			objectService: $objectService,
			schemaResolver: new ListenerSchemaResolver(container: $this->createMock(originalClassName: ContainerInterface::class), logger: new NullLogger()),
			files: $files,
			scanner: $this->scanner,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Scenario: an authorised user scans a paper and gets the report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public function testAnAuthorisedUserGetsTheReport(): void {
		$controller = $this->controller(params: ['sourceObject' => 'item-1', 'fileId' => '11']);
		$report     = ['id' => 'r-1', 'fileId' => 11, 'status' => 'fail', 'findings' => [['code' => 'not-tagged']]];
		$this->scanner->expects(self::once())
			->method('scanFile')
			->with(11, 'item-1', 'agenda-item', 'body-raad')
			->willReturn($report);

		$response = $controller->scan();

		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: $report, actual: $response->getData());
		self::assertSame(expected: ['item-1', true], actual: $this->finds[0], message: 'the source is read under the caller\'s RBAC');
	}//end testAnAuthorisedUserGetsTheReport()

	/**
	 * Scenario: a user without access to the source object gets 403 and nothing is scanned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public function testNoAccessToTheSourceIs403(): void {
		foreach (['item-secret', 'item-gone', 'decision-1'] as $source) {
			$controller = $this->controller(params: ['sourceObject' => $source, 'fileId' => 11]);
			$this->scanner->expects(self::never())->method('scanFile');

			$response = $controller->scan();

			self::assertSame(expected: 403, actual: $response->getStatus(), message: $source);
			self::assertSame(expected: ['message' => 'You may not scan the papers of this object.'], actual: $response->getData());
		}
	}//end testNoAccessToTheSourceIs403()

	/**
	 * A file that is not one of the object's papers is not scanned.
	 *
	 * @return void
	 */
	public function testAFileOfAnotherObjectIs404(): void {
		$controller = $this->controller(params: ['sourceObject' => 'meeting-1', 'fileId' => 11]);
		$this->scanner->expects(self::never())->method('scanFile');

		$response = $controller->scan();

		self::assertSame(expected: [404, ['message' => 'This file is not a paper of this object.']], actual: [$response->getStatus(), $response->getData()]);
	}//end testAFileOfAnotherObjectIs404()

	/**
	 * No session is 401; a missing source or file is 400.
	 *
	 * @return void
	 */
	public function testSessionAndInputAnswers(): void {
		self::assertSame(expected: 401, actual: $this->controller(params: ['sourceObject' => 'item-1', 'fileId' => 11], loggedIn: false)->scan()->getStatus());
		self::assertSame(expected: 400, actual: $this->controller(params: ['fileId' => 11])->scan()->getStatus());
		self::assertSame(expected: 400, actual: $this->controller(params: ['sourceObject' => 'item-1', 'fileId' => 'x'])->scan()->getStatus());
		self::assertSame(expected: [], actual: $this->finds, message: 'nothing is read before the session and input are checked');
	}//end testSessionAndInputAnswers()

	/**
	 * An over-size file answers 413 with the not-scanned report; a broken scan answers 500.
	 *
	 * @return void
	 */
	public function testTooLargeIs413AndABrokenScanIs500(): void {
		$controller = $this->controller(params: ['sourceObject' => 'meeting-1', 'fileId' => 11]);
		self::assertSame(expected: 404, actual: $controller->scan()->getStatus());

		$controller = $this->controller(params: ['sourceObject' => 'item-1', 'fileId' => 11]);
		$tooLarge   = ['status' => 'not-scanned', 'findings' => [['code' => 'too-large', 'severity' => 'info']]];
		$this->scanner->method('scanFile')->willReturn($tooLarge);
		self::assertSame(expected: [413, $tooLarge], actual: [$controller->scan()->getStatus(), $controller->scan()->getData()]);

		$controller = $this->controller(params: ['sourceObject' => 'item-1', 'fileId' => 11]);
		$this->scanner->method('scanFile')->willThrowException(new RuntimeException('disk gone'));
		self::assertSame(expected: [500, ['message' => 'The paper could not be scanned.']], actual: [$controller->scan()->getStatus(), $controller->scan()->getData()]);
	}//end testTooLargeIs413AndABrokenScanIs500()
}//end class
