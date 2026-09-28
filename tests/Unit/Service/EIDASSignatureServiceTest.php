<?php

/**
 * Unit tests for EIDASSignatureService (openconnector-delegating QES adapter).
 *
 * The tests wire integriq the way a real instance has it: sources are
 * OpenRegister objects found by slug, and the call log is an ObjectEntity.
 * They were written against an openconnector SourceMapper that no current
 * instance has, so the service never resolved in production (EIDAS defect).
 * Original note: the tests stub the CallService via the DI container
 * to verify each delegated method's success / failure surface without requiring
 * the real openconnector app at test time.
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
use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for EIDASSignatureService.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
 */
class EIDASSignatureServiceTest extends TestCase {

	/**
	 * Build a service wired the way integriq is on a real instance.
	 *
	 * integriq keeps its sources as OpenRegister objects (register `integriq`,
	 * schema `source`), found by slug through ObjectService; there is no
	 * `Db\SourceMapper` under any namespace, so the container here answers
	 * only the CallService. `CallService::call()` returns the call log as an
	 * ObjectEntity whose object holds `response.body`, which is what the
	 * double returns.
	 *
	 * @param array<string, mixed> $responseBody Body the source answers with
	 * @param object|null          $sourceObject Non-null when the `eidas-qes` source is configured
	 *
	 * @return EIDASSignatureService
	 */
	private function makeService(array $responseBody, ?object $sourceObject = null): EIDASSignatureService {
		$callLog = new ObjectEntity();
		$callLog->setUuid('call-log-1');
		$callLog->setObject(['response' => ['statusCode' => 200, 'body' => json_encode($responseBody)]]);

		$callService = new class($callLog) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity $result The call log returned from call()
			 */
			public function __construct(private readonly ObjectEntity $result) {
			}

			/**
			 * The shape of integriq's CallService::call(): a source object in,
			 * the call log out.
			 *
			 * @param ObjectEntity         $source   The source object
			 * @param string               $endpoint Endpoint path
			 * @param string               $method   HTTP method
			 * @param array<string, mixed> $config   Call config
			 *
			 * @return ObjectEntity
			 */
			public function call(ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []): ObjectEntity {
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($callService) {
				if ($id === 'OCA\\Integriq\\Service\\CallService') {
					return $callService;
				}

				throw new \RuntimeException('Service ' . $id . ' is not registered');
			}
		);

		$source = new ObjectEntity();
		$source->setUuid('source-1');
		$source->setObject(['slug' => EIDASSignatureService::ESIGN_SOURCE_SLUG, 'location' => 'https://sign.example.org']);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($source, $sourceObject): array {
				$filters = ($config['filters'] ?? []);
				if (($filters['register'] ?? '') === 'integriq' && ($filters['schema'] ?? '') === 'source'
					&& ($filters['slug'] ?? '') === EIDASSignatureService::ESIGN_SOURCE_SLUG && $sourceObject !== null
				) {
					return [$source];
				}

				return [];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'ok']);

		return new EIDASSignatureService(container: $container, logger: $logger, auditLogService: $audit, objectService: $objectService);
	}//end makeService()

	/**
	 * Initialize returns success with the openconnector-supplied requestId
	 * and signingUrl.
	 *
	 * @return void
	 */
	public function testInitializeReturnsRequestIdFromOpenconnector(): void {
		$service = $this->makeService(
			responseBody: [
				'requestId' => 'qes-req-42',
				'signingUrl' => 'https://qsp.example/sign/42',
			],
			sourceObject: new \stdClass()
		);

		$result = $service->initializeSigningRequest('min-1', ['m-1', 'm-2']);

		$this->assertTrue($result['success']);
		$this->assertSame('qes-req-42', $result['requestId']);
		$this->assertSame('https://qsp.example/sign/42', $result['signingUrl']);

	}//end testInitializeReturnsRequestIdFromOpenconnector()

	/**
	 * Initialize rejects empty signatories.
	 *
	 * @return void
	 */
	public function testInitializeRejectsEmptySignatories(): void {
		$service = $this->makeService(responseBody: [], sourceObject: new \stdClass());

		$result = $service->initializeSigningRequest('min-1', []);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('at least one signatory', $result['message']);

	}//end testInitializeRejectsEmptySignatories()

	/**
	 * Initialize returns success:false when the openconnector source is
	 * unconfigured (SourceMapper::findBySlug returns null).
	 *
	 * @return void
	 */
	public function testInitializeFailsWhenSourceNotConfigured(): void {
		$service = $this->makeService(responseBody: [], sourceObject: null);

		$result = $service->initializeSigningRequest('min-1', ['m-1']);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not configured', $result['message']);

	}//end testInitializeFailsWhenSourceNotConfigured()

	/**
	 * VerifySignature surfaces openconnector validation as valid:true.
	 *
	 * @return void
	 */
	public function testVerifyReportsValidWhenOpenconnectorAcceptsSignature(): void {
		$service = $this->makeService(
			responseBody: [
				'valid' => true,
				'certificateThumbprint' => 'thumb-aabb',
				'timestamp' => '2026-06-10T12:00:00Z',
			],
			sourceObject: new \stdClass()
		);

		$result = $service->verifySignature('req-1', 'sig-blob');

		$this->assertTrue($result['valid']);
		$this->assertSame('thumb-aabb', $result['certificateThumbprint']);
		$this->assertSame('2026-06-10T12:00:00Z', $result['timestamp']);

	}//end testVerifyReportsValidWhenOpenconnectorAcceptsSignature()

	/**
	 * VerifySignature rejects missing required parameters before opening a
	 * call to openconnector.
	 *
	 * @return void
	 */
	public function testVerifyRejectsMissingParameters(): void {
		$service = $this->makeService(responseBody: [], sourceObject: new \stdClass());

		$result = $service->verifySignature('', 'sig');
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('required', $result['message']);

		$result2 = $service->verifySignature('req-1', '');
		$this->assertFalse($result2['valid']);
		$this->assertStringContainsString('required', $result2['message']);

	}//end testVerifyRejectsMissingParameters()

	/**
	 * ValidateCertificateChain rejects an empty thumbprint.
	 *
	 * @return void
	 */
	public function testValidateCertRejectsEmptyThumbprint(): void {
		$service = $this->makeService(responseBody: [], sourceObject: new \stdClass());

		$result = $service->validateCertificateChain('');
		$this->assertFalse($result['valid']);

	}//end testValidateCertRejectsEmptyThumbprint()

	/**
	 * ValidateCertificateChain surfaces openconnector's verdict.
	 *
	 * @return void
	 */
	public function testValidateCertSurfacesOpenconnectorVerdict(): void {
		$service = $this->makeService(
			responseBody: [
				'valid' => true,
				'issuer' => 'CN=Example QSP',
				'trustListLevel' => 'qualified',
			],
			sourceObject: new \stdClass()
		);

		$result = $service->validateCertificateChain('thumb-aabb');

		$this->assertTrue($result['valid']);
		$this->assertSame('CN=Example QSP', $result['issuer']);
		$this->assertSame('qualified', $result['trustListLevel']);

	}//end testValidateCertSurfacesOpenconnectorVerdict()

}//end class
