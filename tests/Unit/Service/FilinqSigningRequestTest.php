<?php

/**
 * Unit tests for FilinqSigningRequest.
 *
 * Pins the body decidiq posts to filinq's `POST api/signing/requests` to the
 * fields filinq's `SigningService::createRequest()` reads (decidiq#1387).
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
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\FilinqSigningRequest;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for FilinqSigningRequest.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class FilinqSigningRequestTest extends TestCase {

	/**
	 * Build the builder over a fixed set of decidiq objects.
	 *
	 * @param array<string, array<string, mixed>> $objects Object data by "schema/uuid"
	 * @param MeetingFolderService                $folders The folder service double
	 *
	 * @return FilinqSigningRequest
	 */
	private function makeRequest(array $objects, MeetingFolderService $folders): FilinqSigningRequest {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($objects): ?ObjectEntity {
				$key = (string)$schema . '/' . (string)$id;
				if (isset($objects[$key]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject($objects[$key]);
				return $entity;
			}
		);

		return new FilinqSigningRequest(objectService: $objectService, folders: $folders);
	}//end makeRequest()

	/**
	 * A file double.
	 *
	 * @param integer $fileId The Nextcloud file id
	 * @param string  $name   The file name
	 *
	 * @return File
	 */
	private function file(int $fileId, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getName')->willReturn($name);
		return $file;
	}//end file()

	/**
	 * Minutes are signed as their latest generated PDF, by Nextcloud file id,
	 * with the signers named in order and the QES level filinq reads.
	 *
	 * @return void
	 */
	public function testMinutesPayloadUsesFilinqFields(): void {
		$folders = $this->createMock(MeetingFolderService::class);
		$folders->expects($this->once())->method('fileAt')
			->with('Decidesk/Board/2026-10-01 Meeting/Minutes/Notulen v2.pdf')
			->willReturn($this->file(fileId: 812, name: 'Notulen v2.pdf'));

		$request = $this->makeRequest(
			objects: [
				'minutes/min-1' => [
					'generatedDocuments' => [
						['path' => 'Decidesk/Board/2026-10-01 Meeting/Minutes/Notulen v1.pdf', 'format' => 'pdf'],
						['path' => 'Decidesk/Board/2026-10-01 Meeting/Minutes/Notulen v2.pdf', 'format' => 'pdf'],
						['path' => 'Decidesk/Board/2026-10-01 Meeting/Minutes/Notulen v3.md', 'format' => 'markdown'],
					],
				],
				'participant/p-2' => ['displayName' => 'Chair', 'email' => 'chair@example.org'],
				'person/p-1' => ['name' => 'Secretary', 'email' => 'sec@example.org'],
			],
			folders: $folders
		);

		$payload = $request->payload(subjectType: 'minutes', subjectId: 'min-1', signatories: ['p-2', 'p-1']);

		$this->assertSame(812, $payload['documentFileId']);
		$this->assertSame('Notulen v2.pdf', $payload['documentName']);
		$this->assertSame('QES', $payload['signatureLevel']);
		$this->assertSame(
			[
				['name' => 'Chair', 'email' => 'chair@example.org', 'order' => 1],
				['name' => 'Secretary', 'email' => 'sec@example.org', 'order' => 2],
			],
			$payload['signers']
		);
		$this->assertArrayNotHasKey('documentId', $payload);
		$this->assertArrayNotHasKey('signatories', $payload);
		$this->assertArrayNotHasKey('signingLevel', $payload);

	}//end testMinutesPayloadUsesFilinqFields()

	/**
	 * Minutes without a generated PDF cannot be signed: nothing to point
	 * filinq at.
	 *
	 * @return void
	 */
	public function testMinutesWithoutPdfThrow(): void {
		$folders = $this->createMock(MeetingFolderService::class);
		$folders->expects($this->never())->method('fileAt');

		$request = $this->makeRequest(
			objects: ['minutes/min-1' => ['generatedDocuments' => [['path' => 'x/Notulen.md', 'format' => 'markdown']]]],
			folders: $folders
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Generate the PDF first');
		$request->payload(subjectType: 'minutes', subjectId: 'min-1', signatories: ['p-1']);

	}//end testMinutesWithoutPdfThrow()

	/**
	 * A decision list is signed as the meeting's Besluitenlijst.pdf.
	 *
	 * @return void
	 */
	public function testDecisionListUsesTheMeetingsDecisionListPdf(): void {
		$folders = $this->createMock(MeetingFolderService::class);
		$folders->expects($this->once())->method('meetingFile')
			->with(['id' => 'meet-1', 'title' => 'Board'], 'Minutes', 'Besluitenlijst.pdf')
			->willReturn($this->file(fileId: 77, name: 'Besluitenlijst.pdf'));

		$request = $this->makeRequest(objects: ['meeting/meet-1' => ['title' => 'Board']], folders: $folders);

		$payload = $request->payload(subjectType: 'decision-list', subjectId: 'meet-1', signatories: ['p-1']);

		$this->assertSame(77, $payload['documentFileId']);
		$this->assertSame('Besluitenlijst.pdf', $payload['documentName']);
		$this->assertSame([['name' => 'p-1', 'email' => '', 'order' => 1]], $payload['signers']);

	}//end testDecisionListUsesTheMeetingsDecisionListPdf()
}//end class
