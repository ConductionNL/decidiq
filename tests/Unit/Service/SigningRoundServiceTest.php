<?php

/**
 * Unit tests for SigningRoundService: send minutes, decision lists and motions
 * for signature in a chosen order, and store the signed copy back.
 *
 * The signing adapter is the real EIDASSignatureService, wired to integriq the
 * way a real instance has it (sources are OpenRegister objects, the call log
 * is an ObjectEntity holding `response.body`), so the test reads the exact
 * request body the signing service would receive. Every object written back
 * is validated against the merged register schema (decidesk_register.json
 * plus every register.d fragment) with the opis validator.
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
 * @spec openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AuditLogService;
use OCA\Decidiq\Service\EIDASSignatureService;
use OCA\Decidiq\Service\SigningRoundService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SigningRoundService.
 *
 * @spec openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class SigningRoundServiceTest extends TestCase {

	/**
	 * Request bodies the signing service received, in call order.
	 *
	 * @var array<int, array{endpoint: string, method: string, body: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * Objects written back through saveObject(), with their schema.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Files stored through FileService::addFile().
	 *
	 * @var array<int, array{object: mixed, fileName: string, content: mixed}>
	 */
	private array $files = [];

	/**
	 * What the signing service answers, per endpoint.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $answers = [];

	/**
	 * The real signing adapter the service was built around.
	 *
	 * @var EIDASSignatureService|null
	 */
	private ?EIDASSignatureService $signing = null;

	/**
	 * Build the service around one stored record.
	 *
	 * @param string               $schema The schema slug of the record
	 * @param array<string, mixed> $data   The record's data
	 *
	 * @return SigningRoundService
	 */
	private function makeService(string $schema, array $data): SigningRoundService {
		$test = $this;

		$callService = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param SigningRoundServiceTest $test The test collecting the calls
			 */
			public function __construct(private readonly SigningRoundServiceTest $test) {
			}

			/**
			 * The shape of integriq's CallService::call().
			 *
			 * @param ObjectEntity         $source   The source object
			 * @param string               $endpoint Endpoint path
			 * @param string               $method   HTTP method
			 * @param array<string, mixed> $config   Call config
			 *
			 * @return ObjectEntity
			 */
			public function call(ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []): ObjectEntity {
				return $this->test->answer(endpoint: $endpoint, method: $method, config: $config);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($callService) {
				if ($id === 'OCA\\Integriq\\Service\\CallService') {
					return $callService;
				}

				throw new \RuntimeException('Service ' . $id . ' is not registered');
			}
		);

		$source = new ObjectEntity();
		$source->setUuid('source-1');
		$source->setObject(['slug' => EIDASSignatureService::ESIGN_SOURCE_SLUG]);

		$record = new ObjectEntity();
		$record->setUuid('rec-1');
		$record->setObject($data);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($source, $record, $schema): array {
				$filters = ($config['filters'] ?? []);
				if (($filters['register'] ?? '') === 'integriq' && ($filters['slug'] ?? '') === EIDASSignatureService::ESIGN_SOURCE_SLUG) {
					return [$source];
				}

				if (($filters['register'] ?? '') === 'decidiq' && ($filters['schema'] ?? '') === $schema
					&& ($filters['signingStatus'] ?? '') === 'sent' && ($record->getObject()['signingStatus'] ?? '') === 'sent'
				) {
					return ['results' => [$record]];
				}

				return [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schemaArg = null) use ($record, $schema): ?ObjectEntity {
				if ($id === 'rec-1' && $schemaArg === $schema) {
					return $record;
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use ($record): ObjectEntity {
				$this->saved[] = ['schema' => (string)$schema, 'object' => (array)$object];
				$record->setObject((array)$object);
				return $record;
			}
		);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('addFile')->willReturnCallback(
			function (ObjectEntity|string $objectEntity, string $fileName, mixed $content) {
				$this->files[] = ['object' => $objectEntity, 'fileName' => $fileName, 'content' => $content];
				return $this->createMock(File::class);
			}
		);

		$audit = $this->createMock(AuditLogService::class);
		$audit->method('append')->willReturn(['success' => true, 'entry' => [], 'message' => 'ok']);

		$signing = new EIDASSignatureService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			auditLogService: $audit,
			objectService: $objectService
		);

		$this->signing = $signing;

		return new SigningRoundService(
			objectService: $objectService,
			signatureService: $signing,
			fileService: $fileService,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end makeService()

	/**
	 * Answer a call to the signing service and record its body.
	 *
	 * @param string               $endpoint Endpoint path
	 * @param string               $method   HTTP method
	 * @param array<string, mixed> $config   Call config
	 *
	 * @return ObjectEntity The call log
	 */
	public function answer(string $endpoint, string $method, array $config): ObjectEntity {
		$this->calls[] = [
			'endpoint' => $endpoint,
			'method' => $method,
			'body' => (array)json_decode((string)($config['body'] ?? '{}'), true),
		];

		$log = new ObjectEntity();
		$log->setUuid('call-log-' . count($this->calls));
		$log->setObject(['response' => ['statusCode' => 200, 'body' => json_encode($this->answers[$endpoint] ?? [])]]);
		return $log;
	}//end answer()

	/**
	 * The merged properties of one schema: the base register plus every
	 * register.d fragment, the way the app loads them.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function mergedProperties(string $slug): array {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		$properties = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $schema) {
				if (($schema['slug'] ?? strtolower((string)$name)) === $slug) {
					$properties = array_replace_recursive($properties, ($schema['properties'] ?? []));
				}
			}
		}

		return $properties;
	}//end mergedProperties()

	/**
	 * Assert that every key written back is declared by the merged schema and
	 * carries a value that schema accepts.
	 *
	 * @param string               $slug   The schema slug
	 * @param array<string, mixed> $object The object written back
	 *
	 * @return void
	 */
	private function assertValidAgainstSchema(string $slug, array $object): void {
		$properties = $this->mergedProperties(slug: $slug);
		$schema = [
			'type' => 'object',
			'properties' => $properties,
			'additionalProperties' => false,
		];

		// `id` and `@self` are OpenRegister's own metadata, not schema data.
		unset($object['id'], $object['@self']);

		$result = (new Validator())->validate(
			json_decode((string)json_encode($object)),
			json_decode((string)json_encode($schema))
		);
		$error = $result->error();
		$message = '';
		if ($error !== null) {
			$message = $error->message() . ' at ' . implode('/', $error->data()->fullPath()) . ' ' . json_encode($error->args());
		}

		self::assertTrue($result->isValid(), 'Written ' . $slug . ' must validate: ' . $message);
	}//end assertValidAgainstSchema()

	/**
	 * A motion with Pieter (order 2) then Anna (order 1) goes out with Anna
	 * first, and the signing service is told to keep that order.
	 *
	 * @return void
	 */
	public function testTheSigningServiceReceivesTheSignersInOrder(): void {
		$this->answers['/initiate'] = ['requestId' => 'req-7', 'signingUrl' => 'https://sign.example/7'];
		$service = $this->makeService(
			schema: 'decision',
			data: [
				'title' => 'Motie groen dak',
				'decisionType' => 'motion',
				'signers' => [
					['participant' => 'pieter', 'order' => 2],
					['participant' => 'anna', 'order' => 1],
				],
			]
		);

		$result = $service->send(subjectType: 'motion', subjectId: 'rec-1');

		self::assertTrue($result['success'], $result['message']);
		self::assertCount(1, $this->calls);
		self::assertSame('/initiate', $this->calls[0]['endpoint']);
		self::assertSame(['anna', 'pieter'], $this->calls[0]['body']['signatories']);
		self::assertSame('sequential', $this->calls[0]['body']['signingOrder']);
		self::assertSame('motion', $this->calls[0]['body']['subjectType']);
		self::assertSame('rec-1', $this->calls[0]['body']['subjectId']);
	}//end testTheSigningServiceReceivesTheSignersInOrder()

	/**
	 * The record remembers the request it went out under, and what is written
	 * validates against the Decision schema.
	 *
	 * @return void
	 */
	public function testSendingRecordsTheRequestOnTheRecord(): void {
		$this->answers['/initiate'] = ['requestId' => 'req-7', 'signingUrl' => 'https://sign.example/7'];
		$service = $this->makeService(
			schema: 'decision',
			data: ['title' => 'Motie groen dak', 'signers' => [['participant' => 'anna', 'order' => 1]]]
		);

		$service->send(subjectType: 'motion', subjectId: 'rec-1');

		self::assertCount(1, $this->saved);
		self::assertSame('decision', $this->saved[0]['schema']);
		self::assertSame('req-7', $this->saved[0]['object']['signingRequestId']);
		self::assertSame('sent', $this->saved[0]['object']['signingStatus']);
		$this->assertValidAgainstSchema(slug: 'decision', object: $this->saved[0]['object']);
	}//end testSendingRecordsTheRequestOnTheRecord()

	/**
	 * A decision list is the meeting's; it goes out under the meeting record.
	 *
	 * @return void
	 */
	public function testTheDecisionListIsSentFromTheMeeting(): void {
		$this->answers['/initiate'] = ['requestId' => 'req-8'];
		$service = $this->makeService(
			schema: 'meeting',
			data: [
				'title' => 'Raad 14 oktober',
				'signers' => [
					['participant' => 'griffier', 'order' => 2],
					['participant' => 'voorzitter', 'order' => 1],
				],
			]
		);

		$result = $service->send(subjectType: 'decision-list', subjectId: 'rec-1');

		self::assertTrue($result['success'], $result['message']);
		self::assertSame(['voorzitter', 'griffier'], $this->calls[0]['body']['signatories']);
		self::assertSame('decision-list', $this->calls[0]['body']['subjectType']);
		$this->assertValidAgainstSchema(slug: 'meeting', object: $this->saved[0]['object']);
	}//end testTheDecisionListIsSentFromTheMeeting()

	/**
	 * Without signers nothing is sent.
	 *
	 * @return void
	 */
	public function testARecordWithoutSignersIsNotSent(): void {
		$service = $this->makeService(schema: 'minutes', data: ['title' => 'Notulen']);

		$result = $service->send(subjectType: 'minutes', subjectId: 'rec-1');

		self::assertFalse($result['success']);
		self::assertStringContainsString('signer', $result['message']);
		self::assertSame([], $this->calls);
		self::assertSame([], $this->saved);
	}//end testARecordWithoutSignersIsNotSent()

	/**
	 * An unknown subject type is refused before anything is looked up.
	 *
	 * @return void
	 */
	public function testAnUnknownSubjectTypeIsRefused(): void {
		$service = $this->makeService(schema: 'decision', data: []);

		$result = $service->send(subjectType: 'agenda', subjectId: 'rec-1');

		self::assertFalse($result['success']);
		self::assertSame([], $this->calls);
	}//end testAnUnknownSubjectTypeIsRefused()

	/**
	 * Once the signing service reports signed, the signed PDF is stored in
	 * the record's files and linked on the record.
	 *
	 * @return void
	 */
	public function testTheSignedCopyIsStoredAndLinkedOnTheMotion(): void {
		$pdf = "%PDF-1.7\nsigned";
		$this->answers['/status'] = [
			'status' => 'signed',
			'document' => base64_encode($pdf),
			'fileName' => 'motie-groen-dak-getekend.pdf',
		];
		$service = $this->makeService(
			schema: 'decision',
			data: [
				'title' => 'Motie groen dak',
				'signers' => [['participant' => 'anna', 'order' => 1], ['participant' => 'pieter', 'order' => 2]],
				'signingRequestId' => 'req-7',
				'signingStatus' => 'sent',
			]
		);

		$result = $service->collect(subjectType: 'motion', subjectId: 'rec-1');

		self::assertSame('signed', $result['status'], $result['message']);
		self::assertSame(['requestId' => 'req-7'], $this->calls[0]['body']);
		self::assertCount(1, $this->files);
		self::assertInstanceOf(ObjectEntity::class, $this->files[0]['object']);
		self::assertSame('motie-groen-dak-getekend.pdf', $this->files[0]['fileName']);
		self::assertSame($pdf, $this->files[0]['content']);

		$written = $this->saved[0]['object'];
		self::assertSame('signed', $written['signingStatus']);
		self::assertSame('motie-groen-dak-getekend.pdf', $written['signedCopy']);
		self::assertSame(hash('sha256', $pdf), $written['signedCopyHash']);
		self::assertNotEmpty($written['signers'][0]['signedAt']);
		$this->assertValidAgainstSchema(slug: 'decision', object: $written);
	}//end testTheSignedCopyIsStoredAndLinkedOnTheMotion()

	/**
	 * A round the service still has out is left alone.
	 *
	 * @return void
	 */
	public function testAPendingRoundIsLeftAlone(): void {
		$this->answers['/status'] = ['status' => 'pending'];
		$service = $this->makeService(
			schema: 'minutes',
			data: ['signingRequestId' => 'req-9', 'signingStatus' => 'sent']
		);

		$result = $service->collect(subjectType: 'minutes', subjectId: 'rec-1');

		self::assertSame('pending', $result['status']);
		self::assertSame([], $this->files);
		self::assertSame([], $this->saved);
	}//end testAPendingRoundIsLeftAlone()

	/**
	 * A declined round is marked failed so nobody waits for it.
	 *
	 * @return void
	 */
	public function testADeclinedRoundIsMarkedFailed(): void {
		$this->answers['/status'] = ['status' => 'declined'];
		$service = $this->makeService(
			schema: 'minutes',
			data: ['signingRequestId' => 'req-9', 'signingStatus' => 'sent']
		);

		$result = $service->collect(subjectType: 'minutes', subjectId: 'rec-1');

		self::assertSame('failed', $result['status']);
		self::assertSame('failed', $this->saved[0]['object']['signingStatus']);
		$this->assertValidAgainstSchema(slug: 'minutes', object: $this->saved[0]['object']);
	}//end testADeclinedRoundIsMarkedFailed()

	/**
	 * A signed answer without a readable document stores nothing.
	 *
	 * @return void
	 */
	public function testASignedAnswerWithoutADocumentStoresNothing(): void {
		$this->answers['/status'] = ['status' => 'signed', 'document' => '***not base64***'];
		$service = $this->makeService(
			schema: 'decision',
			data: ['signingRequestId' => 'req-7', 'signingStatus' => 'sent']
		);

		$result = $service->collect(subjectType: 'motion', subjectId: 'rec-1');

		self::assertSame('pending', $result['status']);
		self::assertSame([], $this->files);
		self::assertSame([], $this->saved);
	}//end testASignedAnswerWithoutADocumentStoresNothing()

	/**
	 * The background sweep finds the motion that is out and stores its copy.
	 *
	 * @return void
	 */
	public function testTheSweepStoresTheCopyOfEveryRoundThatIsOut(): void {
		$this->answers['/status'] = ['status' => 'signed', 'document' => base64_encode('%PDF-1.7')];
		$service = $this->makeService(
			schema: 'decision',
			data: ['signers' => [['participant' => 'anna', 'order' => 1]], 'signingRequestId' => 'req-7', 'signingStatus' => 'sent']
		);

		self::assertSame(1, $service->collectAllSent());
		self::assertSame('motion-rec-1-signed.pdf', $this->files[0]['fileName']);
		self::assertSame('signed', $this->saved[0]['object']['signingStatus']);
	}//end testTheSweepStoresTheCopyOfEveryRoundThatIsOut()

	/**
	 * Finalising signed minutes writes only what the Minutes schema declares:
	 * it wrote `version: signed` into an integer field and signer tuples into
	 * a list of names, so OpenRegister refused the save and nothing was kept.
	 *
	 * @return void
	 */
	public function testFinalisedMinutesValidateAgainstTheMinutesSchema(): void {
		$this->answers['/finalize'] = ['pdfArchiveReference' => 'archive/notulen-14-oktober.pdf', 'hashSha256' => str_repeat('a', 64)];
		$this->makeService(schema: 'minutes', data: ['title' => 'Notulen 14 oktober', 'version' => 3]);

		$result = $this->signing->finalizeMinutes(
			'rec-1',
			[['signer' => 'anna', 'signature' => 'sig-a', 'timestamp' => '2026-10-15T10:00:00Z']]
		);

		self::assertTrue($result['success']);
		self::assertCount(1, $this->saved);
		$written = $this->saved[0]['object'];
		self::assertSame(3, $written['version']);
		self::assertSame('signed', $written['signingStatus']);
		self::assertSame('archive/notulen-14-oktober.pdf', $written['signedCopy']);
		self::assertSame(['anna'], $written['signedBy']);
		$this->assertValidAgainstSchema(slug: 'minutes', object: $written);
	}//end testFinalisedMinutesValidateAgainstTheMinutesSchema()

	/**
	 * The merged schemas refuse what they should, or the validation above
	 * proves nothing.
	 *
	 * @return void
	 */
	public function testTheSchemaCheckRefusesAnUndeclaredStatus(): void {
		$properties = $this->mergedProperties(slug: 'decision');
		$schema = json_decode((string)json_encode(['type' => 'object', 'properties' => $properties]));

		$result = (new Validator())->validate(json_decode('{"signingStatus":"maybe"}'), $schema);

		self::assertFalse($result->isValid());
	}//end testTheSchemaCheckRefusesAnUndeclaredStatus()
}//end class
