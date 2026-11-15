<?php

/**
 * Unit tests for ProcessTemplateController.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @spec openspec/specs/process-configuration/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Decidiq\Controller\ProcessTemplateController;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TypeError;

/**
 * Tests for the process template admin endpoints.
 *
 * @covers \OCA\Decidiq\Controller\ProcessTemplateController
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class ProcessTemplateControllerTest extends TestCase {
	private IRequest&MockObject $request;

	private ProcessTemplateService&MockObject $service;

	private LoggerInterface&MockObject $logger;

	private ProcessTemplateController $controller;

	protected function setUp(): void {
		$this->request    = $this->createMock(IRequest::class);
		$this->service    = $this->createMock(ProcessTemplateService::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->controller = new ProcessTemplateController($this->request, $this->service, $this->logger);
	}

	public function testIndexListsTheTemplates(): void {
		$this->service->method('list')->willReturn([['id' => 't-1']]);

		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['id' => 't-1']]], $response->getData());
	}

	public function testShowReturnsTheTemplate(): void {
		$this->service->expects($this->once())->method('get')->with('t-1')->willReturn(['id' => 't-1']);

		$this->assertSame(['id' => 't-1'], $this->controller->show('t-1')->getData());
	}

	public function testShowAnswersNotFound(): void {
		$this->service->method('get')->willReturn(null);

		$response = $this->controller->show('t-x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'Process template not found.'], $response->getData());
	}

	public function testCreatePassesTheRequestParameters(): void {
		$this->request->method('getParams')->willReturn(['name' => 'Raad']);
		$this->service->expects($this->once())->method('create')->with(['name' => 'Raad'])->willReturn(['id' => 'n']);

		$response = $this->controller->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 'n'], $response->getData());
	}

	public function testCreateAnswersBadRequestOnInvalidInput(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('create')->willThrowException(new InvalidArgumentException('Name is required'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'Name is required'], $response->getData());
	}

	public function testCreateHidesUnexpectedErrors(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('create')->willThrowException(new TypeError('internal detail'));
		$this->logger->expects($this->once())->method('error')
			->with('Decidiq: process template create failed', ['error' => 'internal detail']);

		$response = $this->controller->create();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'Failed to create process template.'], $response->getData());
	}

	public function testValidateReturnsTheServiceVerdict(): void {
		$this->request->method('getParams')->willReturn(['states' => []]);
		$this->service->expects($this->once())->method('validateStateMachine')->with(['states' => []])
			->willReturn(['valid' => false, 'errors' => ['x']]);

		$this->assertSame(['valid' => false, 'errors' => ['x']], $this->controller->validate()->getData());
	}

	public function testUpdateReturnsTheUpdatedTemplate(): void {
		$this->request->method('getParams')->willReturn(['name' => 'B']);
		$this->service->expects($this->once())->method('update')->with('t-1', ['name' => 'B'])->willReturn(['id' => 't-1']);

		$response = $this->controller->update('t-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 't-1'], $response->getData());
	}

	/**
	 * @return array<string, array{\Throwable, int, string}>
	 */
	public static function updateFailures(): array {
		return [
			'invalid' => [new InvalidArgumentException('bad'), 400, 'bad'],
			'conflict' => [new RuntimeException('in use'), 409, 'in use'],
			'unexpected' => [new TypeError('boom'), 500, 'Failed to update process template.'],
		];
	}

	#[DataProvider('updateFailures')]
	public function testUpdateMapsFailures(\Throwable $error, int $status, string $message): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('update')->willThrowException($error);

		$response = $this->controller->update('t-1');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['message' => $message], $response->getData());
	}

	public function testDuplicateUsesANonEmptyName(): void {
		$this->request->method('getParams')->willReturn(['name' => 'Copy']);
		$this->service->expects($this->once())->method('duplicate')->with('t-1', 'Copy')->willReturn(['id' => 't-2']);

		$response = $this->controller->duplicate('t-1');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 't-2'], $response->getData());
	}

	public function testDuplicateIgnoresAnEmptyOrNonStringName(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(['name' => ''], ['name' => ['x']], []);
		$this->service->expects($this->exactly(3))->method('duplicate')->with('t-1', null)->willReturn([]);

		$this->controller->duplicate('t-1');
		$this->controller->duplicate('t-1');
		$this->controller->duplicate('t-1');
	}

	public function testDuplicateMapsAMissingTemplateToNotFound(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('duplicate')->willThrowException(new RuntimeException('Template not found'));

		$response = $this->controller->duplicate('t-x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'Template not found'], $response->getData());
	}

	public function testDuplicateHidesUnexpectedErrors(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('duplicate')->willThrowException(new TypeError('x'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->duplicate('t-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'Failed to duplicate process template.'], $response->getData());
	}

	public function testDestroyDeletesTheTemplate(): void {
		$this->service->expects($this->once())->method('delete')->with('t-1');

		$this->assertSame(['success' => true], $this->controller->destroy('t-1')->getData());
	}

	public function testDestroyMapsAConflict(): void {
		$this->service->method('delete')->willThrowException(new RuntimeException('Template in use'));

		$response = $this->controller->destroy('t-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['message' => 'Template in use'], $response->getData());
	}

	public function testDestroyHidesUnexpectedErrors(): void {
		$this->service->method('delete')->willThrowException(new TypeError('x'));
		$this->logger->expects($this->once())->method('error')
			->with('Decidiq: process template delete failed', ['error' => 'x']);

		$response = $this->controller->destroy('t-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'Failed to delete process template.'], $response->getData());
	}
}
