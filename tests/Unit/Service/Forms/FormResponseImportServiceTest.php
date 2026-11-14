<?php

/**
 * Unit tests for FormResponseImportService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Forms;

use OCA\Decidiq\Service\Forms\FormResponseImportService;
use OCA\Decidiq\Service\Forms\FormResponseReader;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\Decidiq\Service\ReactionIntakeService;
use OCA\Decidiq\Service\SavedObjectNormaliser;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(FormResponseImportService::class)]
#[UsesClass(ReactionIntakeService::class)]
#[UsesClass(ParticipationLifecycleService::class)]
#[UsesClass(SavedObjectNormaliser::class)]
final class FormResponseImportServiceTest extends TestCase {

	/**
	 * The consultation.
	 */
	private const CONSULTATION = 'c0a8b3e2-0000-4000-8000-000000000002';

	/**
	 * Reactions in the object store, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The reader, returning linked form 4 with the responses set per test.
	 *
	 * @var FormResponseReader&MockObject
	 */
	private FormResponseReader&MockObject $reader;

	/**
	 * Service under test, over the real intake service and an in-memory object store.
	 *
	 * @var FormResponseImportService
	 */
	private FormResponseImportService $service;

	/**
	 * Set up the object store and the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?ObjectEntity {
				if ($schema === 'public-consultation') {
					return $id === self::CONSULTATION ? $this->entity(['id' => $id, 'moderationPolicy' => 'post-moderation']) : null;
				}

				return isset($this->saved[$id]) === true ? $this->entity($this->saved[$id]) : null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				$this->saved[$uuid] = $object + ['id' => $uuid];
				return $this->entity($this->saved[$uuid]);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('secret');

		$intake = new ReactionIntakeService(
			logger: $this->createMock(LoggerInterface::class),
			appConfig: $appConfig,
			lifecycleService: new ParticipationLifecycleService(objectService: $objectService),
			objectService: $objectService,
		);

		$this->reader = $this->createMock(FormResponseReader::class);
		$this->reader->method('linkedFormIds')->with(self::CONSULTATION)->willReturn([4]);

		$this->service = new FormResponseImportService(reader: $this->reader, intake: $intake, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * An ObjectEntity mock serialising to the given array.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * One response of form 4.
	 *
	 * @param int    $submissionId The submission id.
	 * @param string $body         The response text.
	 *
	 * @return array{formId: int, submissionId: int, userId: string, timestamp: int, body: string}
	 */
	private static function response(int $submissionId, string $body): array {
		return ['formId' => 4, 'submissionId' => $submissionId, 'userId' => 'user' . $submissionId, 'timestamp' => 1700000000 + $submissionId, 'body' => $body];
	}//end response()

	/**
	 * Three responses become three pending reactions on the consultation; a second run adds none.
	 *
	 * @return void
	 */
	public function testThreeResponsesBecomeThreePendingReactionsOnce(): void {
		$this->reader->method('responses')->with(4)->willReturn([self::response(1, 'A'), self::response(2, 'B'), self::response(3, 'C')]);

		self::assertSame(['forms' => 1, 'imported' => 3, 'skipped' => 0, 'refused' => 0], $this->service->import(consultationId: self::CONSULTATION));
		self::assertCount(3, $this->saved);
		foreach ($this->saved as $reaction) {
			self::assertSame('pending', $reaction['moderationStatus']);
			self::assertSame(self::CONSULTATION, $reaction['consultation']);
		}

		self::assertSame(['forms' => 1, 'imported' => 0, 'skipped' => 3, 'refused' => 0], $this->service->import(consultationId: self::CONSULTATION));
		self::assertCount(3, $this->saved);
	}//end testThreeResponsesBecomeThreePendingReactionsOnce()

	/**
	 * An empty or over-long response is counted as refused and the others still import.
	 *
	 * @return void
	 */
	public function testAnEmptyOrTooLongResponseIsRefused(): void {
		$tooLong = str_repeat('x', (ReactionIntakeService::MAX_BODY_BYTES + 1));
		$this->reader->method('responses')->willReturn([self::response(1, ''), self::response(2, $tooLong), self::response(3, 'C')]);

		self::assertSame(['forms' => 1, 'imported' => 1, 'skipped' => 0, 'refused' => 2], $this->service->import(consultationId: self::CONSULTATION));
		self::assertSame(['C'], array_column(array_values($this->saved), 'body'));
	}//end testAnEmptyOrTooLongResponseIsRefused()

	/**
	 * A consultation that does not exist stops the import, it is not counted as refused.
	 *
	 * @return void
	 */
	public function testAnUnknownConsultationStopsTheImport(): void {
		$reader = $this->createMock(FormResponseReader::class);
		$reader->method('linkedFormIds')->willReturn([4]);
		$reader->method('responses')->willReturn([self::response(1, 'A')]);
		$service = new FormResponseImportService(
			reader: $reader,
			intake: (new \ReflectionProperty(FormResponseImportService::class, 'intake'))->getValue($this->service),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->expectException(RuntimeException::class);
		$service->import(consultationId: 'missing');
	}//end testAnUnknownConsultationStopsTheImport()
}//end class
