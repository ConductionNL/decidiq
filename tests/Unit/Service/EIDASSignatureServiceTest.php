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
use OCA\Decidiq\Service\FilinqSigningRequest;
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
	 * The arguments after `config` of the last findAll() call.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $findAllArgs = [];

	/**
	 * The endpoint and config of the calls made to the `docudesk-signing` source.
	 *
	 * @var array<int, array{endpoint: string, config: array<string, mixed>}>
	 */
	private array $docudeskCalls = [];

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
			function (array $config, ...$rest) use ($source, $sourceObject): array {
				$this->findAllArgs = $rest;
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
	 * The source is admin configuration, so it is read in system context the
	 * way integriq reads it: a griffier without rights on the integriq
	 * register still reaches the signing service.
	 *
	 * @return void
	 */
	public function testSourceIsReadInSystemContext(): void {
		$service = $this->makeService(responseBody: ['requestId' => 'r', 'signingUrl' => 'u'], sourceObject: new \stdClass());

		$service->initializeSigningRequest('min-1', ['m-1']);

		$this->assertSame([false, false], array_values($this->findAllArgs));

	}//end testSourceIsReadInSystemContext()

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

	/**
	 * Build a service with filinq registered as the `docudesk-signing` source.
	 *
	 * @param array<string, mixed>      $responseBody What filinq answers
	 * @param FilinqSigningRequest|null $request      The request builder
	 *
	 * @return EIDASSignatureService
	 */
	private function makeFilinqService(array $responseBody, ?FilinqSigningRequest $request): EIDASSignatureService {
		$callLog = new ObjectEntity();
		$callLog->setObject(['response' => ['statusCode' => 200, 'body' => json_encode($responseBody)]]);

		$callService = new class($callLog, $this) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity              $result The call log returned from call()
			 * @param EIDASSignatureServiceTest $test   Records the calls
			 */
			public function __construct(private readonly ObjectEntity $result, private readonly EIDASSignatureServiceTest $test) {
			}

			/**
			 * Record the call and answer with the call log.
			 *
			 * @param ObjectEntity         $source   The source object
			 * @param string               $endpoint Endpoint path
			 * @param string               $method   HTTP method
			 * @param array<string, mixed> $config   Call config
			 *
			 * @return ObjectEntity
			 */
			public function call(ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []): ObjectEntity {
				$this->test->recordDocudeskCall(endpoint: $endpoint, config: $config);
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
		$source->setObject(['slug' => EIDASSignatureService::DOCUDESK_SOURCE_SLUG, 'location' => 'https://nc.example.org/index.php/apps/filinq']);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($source): array {
				if (($config['filters']['slug'] ?? '') === EIDASSignatureService::DOCUDESK_SOURCE_SLUG) {
					return [$source];
				}

				return [];
			}
		);

		return new EIDASSignatureService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			auditLogService: $this->createMock(AuditLogService::class),
			objectService: $objectService,
			filinqRequest: $request
		);
	}//end makeFilinqService()

	/**
	 * Record a call to the `docudesk-signing` source.
	 *
	 * @param string               $endpoint Endpoint path
	 * @param array<string, mixed> $config   Call config
	 *
	 * @return void
	 */
	public function recordDocudeskCall(string $endpoint, array $config): void {
		$this->docudeskCalls[] = ['endpoint' => $endpoint, 'config' => $config];
	}//end recordDocudeskCall()

	/**
	 * With filinq registered, the request goes to filinq's create route with
	 * the fields filinq reads, and filinq's `id` comes back as the request id
	 * (decidiq#1387).
	 *
	 * @return void
	 */
	public function testFilinqGetsItsOwnRouteAndFields(): void {
		$body = [
			'documentFileId' => 812,
			'documentName' => 'Notulen.pdf',
			'signers' => [['name' => 'A', 'email' => 'a@example.org', 'order' => 1]],
			'signingOrder' => 'sequential',
			'signatureLevel' => 'QES',
		];
		$request = $this->createMock(FilinqSigningRequest::class);
		$request->expects($this->once())->method('payload')->with('minutes', 'min-1', ['m-1'])->willReturn($body);

		$result = $this->makeFilinqService(responseBody: ['id' => 'sr-9'], request: $request)->initializeSigningRequest('min-1', ['m-1']);

		$this->assertTrue($result['success']);
		$this->assertSame('sr-9', $result['requestId']);
		$this->assertCount(1, $this->docudeskCalls);
		$this->assertSame('/api/signing/requests', $this->docudeskCalls[0]['endpoint']);
		$this->assertSame($body, json_decode((string)$this->docudeskCalls[0]['config']['body'], true));

	}//end testFilinqGetsItsOwnRouteAndFields()

	/**
	 * An answer without an id (filinq's error answer) fails, and closed: the
	 * openconnector source is not tried after it.
	 *
	 * @return void
	 */
	public function testFilinqAnswerWithoutIdFailsClosed(): void {
		$request = $this->createMock(FilinqSigningRequest::class);
		$request->method('payload')->willReturn(['documentFileId' => 1]);

		$result = $this->makeFilinqService(responseBody: ['message' => 'Not found'], request: $request)->initializeSigningRequest('min-1', ['m-1']);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('fail-closed', $result['message']);
		$this->assertStringContainsString('Not found', $result['message']);
		$this->assertCount(1, $this->docudeskCalls);

	}//end testFilinqAnswerWithoutIdFailsClosed()

	/**
	 * Without a PDF to sign nothing is posted, and the failure is final.
	 *
	 * @return void
	 */
	public function testNoPdfPostsNothing(): void {
		$request = $this->createMock(FilinqSigningRequest::class);
		$request->method('payload')->willThrowException(new \RuntimeException('No PDF of this minutes to sign was found.'));

		$result = $this->makeFilinqService(responseBody: ['id' => 'x'], request: $request)->initializeSigningRequest('min-1', ['m-1']);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('No PDF', $result['message']);
		$this->assertSame([], $this->docudeskCalls);

	}//end testNoPdfPostsNothing()

}//end class
