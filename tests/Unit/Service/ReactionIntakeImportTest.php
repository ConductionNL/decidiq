<?php

/**
 * Unit tests for ReactionIntakeService::importReaction(), the form-response intake.
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
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
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

#[CoversClass(ReactionIntakeService::class)]
#[UsesClass(ParticipationLifecycleService::class)]
#[UsesClass(SavedObjectNormaliser::class)]
final class ReactionIntakeImportTest extends TestCase {

	/**
	 * The consultation the responses belong to.
	 */
	private const CONSULTATION = 'c0a8b3e2-0000-4000-8000-000000000001';

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Reactions saved during the test, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Service under test.
	 *
	 * @var ReactionIntakeService
	 */
	private ReactionIntakeService $service;

	/**
	 * A consultation under post-moderation (a named reaction would auto-approve)
	 * and an object store that keeps what is saved.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null): ?ObjectEntity {
				if ($schema === 'public-consultation') {
					return $id === self::CONSULTATION ? $this->entity(['id' => self::CONSULTATION, 'moderationPolicy' => 'post-moderation', 'status' => 'closed']) : null;
				}

				return isset($this->saved[$id]) === true ? $this->entity($this->saved[$id]) : null;
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntity {
				$this->saved[$uuid] = $object + ['id' => $uuid];
				return $this->entity($this->saved[$uuid]);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('test-secret');

		$this->service = new ReactionIntakeService(
			logger: $this->createMock(LoggerInterface::class),
			appConfig: $appConfig,
			lifecycleService: new ParticipationLifecycleService(objectService: $this->objectService),
			objectService: $this->objectService,
		);
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
	 * A named response imports as pending, with the consultation ref, the respondent and the response date.
	 *
	 * @return void
	 */
	public function testANamedResponseImportsAsPendingWhateverThePolicy(): void {
		$reaction = $this->service->importReaction(
			consultationId: self::CONSULTATION,
			body: '  Question: answer  ',
			sourceKey: 'forms:4:17',
			submitter: 'alice',
			submittedAt: 1767225600
		);

		$uuid = ReactionIntakeService::importedReactionId(consultationId: self::CONSULTATION, sourceKey: 'forms:4:17');
		self::assertSame($uuid, $reaction['id']);
		self::assertSame('pending', $reaction['moderationStatus']);
		self::assertSame('Question: answer', $reaction['body']);
		self::assertSame('alice', $reaction['submitterId']);
		self::assertSame(self::CONSULTATION, $reaction['consultation']);
		self::assertSame('2026-01-01T00:00:00+00:00', $reaction['submittedAt']);
		self::assertSame([['register' => 'decidiq', 'schema' => 'public-consultation', 'id' => self::CONSULTATION]], $reaction['relations']);
	}//end testANamedResponseImportsAsPendingWhateverThePolicy()

	/**
	 * Importing the same response again finds it and saves nothing.
	 *
	 * @return void
	 */
	public function testTheSameResponseIsImportedOnce(): void {
		self::assertNotNull($this->service->importReaction(consultationId: self::CONSULTATION, body: 'A', sourceKey: 'forms:4:17', submitter: 'alice', submittedAt: 1));
		self::assertNull($this->service->importReaction(consultationId: self::CONSULTATION, body: 'A changed', sourceKey: 'forms:4:17', submitter: 'alice', submittedAt: 1));

		self::assertCount(1, $this->saved);
		self::assertSame('A', array_values($this->saved)[0]['body']);
	}//end testTheSameResponseIsImportedOnce()

	/**
	 * An anonymous Forms respondent is stored as a pseudonym, never by the Forms id.
	 *
	 * @return void
	 */
	public function testAnAnonymousRespondentIsStoredPseudonymously(): void {
		$first  = $this->service->importReaction(consultationId: self::CONSULTATION, body: 'A', sourceKey: 'forms:4:1', submitter: 'anon-user-abc', submittedAt: 1);
		$second = $this->service->importReaction(consultationId: self::CONSULTATION, body: 'B', sourceKey: 'forms:4:2', submitter: '', submittedAt: 1);

		self::assertStringStartsWith('anon-', $first['submitterId']);
		self::assertNotSame('anon-user-abc', $first['submitterId']);
		self::assertStringStartsWith('anon-', $second['submitterId']);
		self::assertNotSame($first['submitterId'], $second['submitterId']);
		self::assertSame('pending', $second['moderationStatus']);
	}//end testAnAnonymousRespondentIsStoredPseudonymously()

	/**
	 * An empty response is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnEmptyResponseIsRefused(): void {
		try {
			$this->service->importReaction(consultationId: self::CONSULTATION, body: '   ', sourceKey: 'forms:4:3', submitter: 'alice', submittedAt: 1);
			self::fail('An empty response was imported.');
		} catch (InvalidArgumentException) {
			self::assertSame([], $this->saved);
		}
	}//end testAnEmptyResponseIsRefused()

	/**
	 * A response for a consultation that does not exist is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownConsultationIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->service->importReaction(consultationId: 'missing', body: 'A', sourceKey: 'forms:4:3', submitter: 'alice', submittedAt: 1);
	}//end testAnUnknownConsultationIsRefused()

	/**
	 * The imported reaction id is a stable version 5 UUID per consultation and source key.
	 *
	 * @return void
	 */
	public function testTheImportedReactionIdIsStablePerResponse(): void {
		$id = ReactionIntakeService::importedReactionId(consultationId: 'c1', sourceKey: 'forms:1:1');

		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
		self::assertSame($id, ReactionIntakeService::importedReactionId(consultationId: 'c1', sourceKey: 'forms:1:1'));
		self::assertNotSame($id, ReactionIntakeService::importedReactionId(consultationId: 'c1', sourceKey: 'forms:1:2'));
		self::assertNotSame($id, ReactionIntakeService::importedReactionId(consultationId: 'c2', sourceKey: 'forms:1:1'));
	}//end testTheImportedReactionIdIsStablePerResponse()
}//end class
