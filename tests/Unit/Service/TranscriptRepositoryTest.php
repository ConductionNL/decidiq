<?php

/**
 * Unit tests for TranscriptRepository.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\TranscriptRepository;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * OpenRegister and Files access for transcripts.
 *
 * @covers \OCA\Decidiq\Service\TranscriptRepository
 */
class TranscriptRepositoryTest extends TestCase {

	/**
	 * Mock file service.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * Mock object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->fileService = $this->createMock(FileService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

	}//end setUp()

	/**
	 * Build the repository under test.
	 *
	 * @return TranscriptRepository
	 */
	private function repository(): TranscriptRepository {
		return new TranscriptRepository(fileService: $this->fileService, objectService: $this->objectService);

	}//end repository()

	/**
	 * Wrap a payload in an ObjectEntity double.
	 *
	 * @param array<string, mixed> $object The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'getObject'])
			->getMock();
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getObject')->willReturn($object);
		return $entity;

	}//end entity()

	/**
	 * Meetings and transcripts are read from their own schema in the decidiq register.
	 *
	 * @return void
	 */
	public function testFetchMeetingAndTranscriptReadTheirSchemas(): void {
		$this->objectService->expects($this->exactly(2))
			->method('find')
			->willReturnCallback(
				fn (string|int $id, ?array $extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ObjectEntity => $this->entity(
					['id' => $id, 'register' => $register, 'schema' => $schema]
				)
			);

		$repository = $this->repository();

		$this->assertSame(['id' => 'm-1', 'register' => 'decidiq', 'schema' => 'meeting'], $repository->fetchMeeting('m-1'));
		$this->assertSame(['id' => 't-1', 'register' => 'decidiq', 'schema' => 'transcript'], $repository->fetchTranscript('t-1'));

	}//end testFetchMeetingAndTranscriptReadTheirSchemas()

	/**
	 * A null lookup becomes a MissingObjectException naming the object.
	 *
	 * @return void
	 */
	public function testAbsentMeetingThrows(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(MissingObjectException::class);
		$this->expectExceptionMessage('Meeting "m-9" not found.');

		$this->repository()->fetchMeeting('m-9');

	}//end testAbsentMeetingThrows()

	/**
	 * A DoesNotExistException from the store is translated as well.
	 *
	 * @return void
	 */
	public function testDoesNotExistIsTranslated(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(MissingObjectException::class);
		$this->expectExceptionMessage('Transcript "t-9" not found.');

		$this->repository()->fetchTranscript('t-9');

	}//end testDoesNotExistIsTranslated()

	/**
	 * Agenda items are queried by meeting relation and normalised from every shape.
	 *
	 * @return void
	 */
	public function testFetchAgendaItemsNormalisesEveryShape(): void {
		$serialisable = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'a-3'];
			}
		};

		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				[
					'register' => 'decidiq',
					'schema' => 'agenda-item',
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'agenda-item',
						'_relations.meeting' => 'm-1',
					],
				]
			)
			->willReturn([['id' => 'a-1'], $this->entity(['id' => 'a-2']), $serialisable, new \stdClass()]);

		$this->assertSame(
			[['id' => 'a-1'], ['id' => 'a-2'], ['id' => 'a-3']],
			$this->repository()->fetchAgendaItems('m-1')
		);

	}//end testFetchAgendaItemsNormalisesEveryShape()

	/**
	 * Saving upserts under the transcript's id and returns the serialised result.
	 *
	 * @return void
	 */
	public function testSaveTranscriptUpsertsById(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['@self' => ['id' => 't-1'], 'text' => 'x'], [], 'decidiq', 'transcript', 't-1')
			->willReturn($this->entity(['id' => 't-1', 'text' => 'x', 'saved' => true]));

		$this->assertSame(
			['id' => 't-1', 'text' => 'x', 'saved' => true],
			$this->repository()->saveTranscript(['@self' => ['id' => 't-1'], 'text' => 'x'])
		);

	}//end testSaveTranscriptUpsertsById()

	/**
	 * A new transcript without an id is created rather than upserted.
	 *
	 * @return void
	 */
	public function testSaveNewTranscriptPassesNullUuid(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['id' => '', 'text' => 'x'], [], 'decidiq', 'transcript', null)
			->willReturn($this->entity(['id' => 'new']));

		$this->assertSame(['id' => 'new'], $this->repository()->saveTranscript(['id' => '', 'text' => 'x']));

	}//end testSaveNewTranscriptPassesNullUuid()

	/**
	 * The retention policy is written onto the governance body.
	 *
	 * @return void
	 */
	public function testSaveRetentionPolicyUpdatesBody(): void {
		$this->objectService->method('find')
			->with('body-1', [], false, 'decidiq', 'governance-body')
			->willReturn($this->entity(['id' => 'body-1', 'name' => 'Raad']));
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				['id' => 'body-1', 'name' => 'Raad', 'transcriptRetentionPolicy' => 'delete', 'transcriptRetentionDays' => 30],
				[],
				'decidiq',
				'governance-body',
				'body-1'
			)
			->willReturn($this->entity([]));

		$this->repository()->saveRetentionPolicy('body-1', 'delete', 30);

	}//end testSaveRetentionPolicyUpdatesBody()

	/**
	 * A retention policy for an unknown body is refused without a write.
	 *
	 * @return void
	 */
	public function testSaveRetentionPolicyForUnknownBodyThrows(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(MissingObjectException::class);
		$this->expectExceptionMessage('Governance body "body-9" not found.');

		$this->repository()->saveRetentionPolicy('body-9', 'keep', 0);

	}//end testSaveRetentionPolicyForUnknownBodyThrows()

	/**
	 * The transcript id comes from the top level, then @self, else null.
	 *
	 * @return void
	 */
	public function testTranscriptId(): void {
		$repository = $this->repository();

		$this->assertSame('t-1', $repository->transcriptId(['id' => 't-1', '@self' => ['id' => 'other']]));
		$this->assertSame('42', $repository->transcriptId(['@self' => ['id' => 42]]));
		$this->assertNull($repository->transcriptId(['id' => '']));
		$this->assertNull($repository->transcriptId([]));

	}//end testTranscriptId()

	/**
	 * The meeting id is read from relations, the plain field, or an array reference.
	 *
	 * @return void
	 */
	public function testResolveMeetingId(): void {
		$repository = $this->repository();

		$this->assertSame('m-1', $repository->resolveMeetingId(['relations' => ['meeting' => 'm-1'], 'meeting' => 'm-2']));
		$this->assertSame('m-2', $repository->resolveMeetingId(['meeting' => 'm-2']));
		$this->assertSame('m-3', $repository->resolveMeetingId(['meeting' => ['id' => 'm-3']]));
		$this->assertSame('m-4', $repository->resolveMeetingId(['relations' => ['meeting' => ['m-4']]]));
		$this->assertNull($repository->resolveMeetingId(['meeting' => '']));
		$this->assertNull($repository->resolveMeetingId(['meeting' => []]));
		$this->assertNull($repository->resolveMeetingId([]));

	}//end testResolveMeetingId()

	/**
	 * A source path resolves to the file in its parent folder.
	 *
	 * @return void
	 */
	public function testResolveSourceNodeReturnsFile(): void {
		$file = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('audio.mp3')->willReturn($file);
		$this->fileService->expects($this->once())->method('createFolder')->with('Decidesk/Raad')->willReturn($folder);

		$this->assertSame($file, $this->repository()->resolveSourceNode('Decidesk/Raad/audio.mp3'));

	}//end testResolveSourceNodeReturnsFile()

	/**
	 * A path that resolves to a folder is refused.
	 *
	 * @return void
	 */
	public function testResolveSourceNodeRefusesFolder(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($this->createMock(Folder::class));
		$this->fileService->method('createFolder')->willReturn($folder);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Source path is not a file: Decidesk/sub');

		$this->repository()->resolveSourceNode('Decidesk/sub');

	}//end testResolveSourceNodeRefusesFolder()

	/**
	 * An empty source path is refused before Files is touched.
	 *
	 * @return void
	 */
	public function testResolveSourceNodeRefusesEmptyPath(): void {
		$this->fileService->expects($this->never())->method('createFolder');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Transcript has no source file path.');

		$this->repository()->resolveSourceNode('');

	}//end testResolveSourceNodeRefusesEmptyPath()
}//end class
