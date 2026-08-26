<?php

/**
 * Unit tests for LogEIDASSignatureService (dormant eIDAS fallback).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\LogEIDASSignatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogEIDASSignatureService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
class LogEIDASSignatureServiceTest extends TestCase {

	/**
	 * Tracker for captured AuditLogService::append calls. Object-wrapped so
	 * the reference survives `return [$service, $tracker]` from makeService.
	 *
	 * @var \ArrayObject<int, array<string, mixed>>
	 */
	private \ArrayObject $appendCalls;

	/**
	 * Build a service with a captured AuditLogService double.
	 *
	 * @return LogEIDASSignatureService
	 */
	private function makeService(): LogEIDASSignatureService {
		$this->appendCalls = new \ArrayObject();
		$tracker = $this->appendCalls;

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturnCallback(
			function (string $actor, string $action, array $objectUids, array $payload = []) use ($tracker): array {
				$tracker->append(
					[
						'actor' => $actor,
						'action' => $action,
						'objectUids' => $objectUids,
						'payload' => $payload,
					]
				);
				return ['success' => true, 'entry' => [], 'message' => 'ok'];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		return new LogEIDASSignatureService(logger: $logger, auditLogService: $audit);
	}//end makeService()

	/**
	 * Initialise returns success:false with the unconfigured message and
	 * appends a 'signature' audit entry.
	 *
	 * @return void
	 */
	public function testInitializeReturnsUnconfiguredAndAudits(): void {
		$service = $this->makeService();

		$result = $service->initializeSigningRequest('min-1', ['m-1', 'm-2']);

		$this->assertFalse($result['success']);
		$this->assertNull($result['requestId']);
		$this->assertNull($result['signingUrl']);
		$this->assertSame(LogEIDASSignatureService::UNCONFIGURED_MESSAGE, $result['message']);

		$calls = $this->appendCalls->getArrayCopy();
		$this->assertCount(1, $calls);
		$this->assertSame('signature', $calls[0]['action']);
		$this->assertSame(['min-1'], $calls[0]['objectUids']);
		$this->assertSame('initiate', $calls[0]['payload']['phase']);
		$this->assertSame('dormant', $calls[0]['payload']['adapter']);

	}//end testInitializeReturnsUnconfiguredAndAudits()

	/**
	 * Verify returns valid:false with the unconfigured message.
	 *
	 * @return void
	 */
	public function testVerifyReturnsUnconfigured(): void {
		$service = $this->makeService();

		$result = $service->verifySignature('req-1', 'sig-blob');

		$this->assertFalse($result['valid']);
		$this->assertNull($result['certificateThumbprint']);
		$this->assertNull($result['timestamp']);
		$this->assertSame(LogEIDASSignatureService::UNCONFIGURED_MESSAGE, $result['message']);

	}//end testVerifyReturnsUnconfigured()

	/**
	 * Finalize returns success:false with the unconfigured message and audits
	 * the intent.
	 *
	 * @return void
	 */
	public function testFinalizeReturnsUnconfiguredAndAudits(): void {
		$service = $this->makeService();

		$result = $service->finalizeMinutes(
			'min-1',
			[
				['signer' => 'm-1', 'signature' => 's1', 'timestamp' => 't1'],
				['signer' => 'm-2', 'signature' => 's2', 'timestamp' => 't2'],
			]
		);

		$this->assertFalse($result['success']);
		$this->assertNull($result['pdfArchiveReference']);
		$this->assertNull($result['hashSha256']);
		$this->assertSame(LogEIDASSignatureService::UNCONFIGURED_MESSAGE, $result['message']);

		$calls = $this->appendCalls->getArrayCopy();
		$this->assertCount(1, $calls);
		$this->assertSame('finalize', $calls[0]['payload']['phase']);
		$this->assertSame(2, $calls[0]['payload']['signatures']);

	}//end testFinalizeReturnsUnconfiguredAndAudits()

	/**
	 * Cert chain returns valid:false with the unconfigured message.
	 *
	 * @return void
	 */
	public function testValidateCertReturnsUnconfigured(): void {
		$service = $this->makeService();

		$result = $service->validateCertificateChain('abc123');

		$this->assertFalse($result['valid']);
		$this->assertNull($result['issuer']);
		$this->assertNull($result['trustListLevel']);
		$this->assertSame(LogEIDASSignatureService::UNCONFIGURED_MESSAGE, $result['message']);

	}//end testValidateCertReturnsUnconfigured()

}//end class
