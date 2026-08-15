<?php

/**
 * Unit tests for ProofPackageService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Service\MeetingFolderService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ProofPackageService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests evidence assembly (convocation / quorum / votes / decisions), the
 * honesty contract for unrecorded data, the SHA-256 integrity seal, and the
 * failure modes.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class ProofPackageServiceTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock ParticipantResolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participantResolver;

	/**
	 * Mock MeetingFolderService.
	 *
	 * @var MeetingFolderService&MockObject
	 */
	private MeetingFolderService&MockObject $folderService;

	/**
	 * The service under test.
	 *
	 * @var ProofPackageService
	 */
	private ProofPackageService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock the (stubbed) OpenRegister ObjectService class itself so that
		// named-argument calls (find(id: ..., register: ...)) bind correctly.
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->folderService = $this->createMock(MeetingFolderService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$this->service = new ProofPackageService(
			logger: $this->createMock(LoggerInterface::class),
			participantResolver: $this->participantResolver,
			folderService: $this->folderService,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * Helper: create an ObjectEntity double exposing jsonSerialize().
	 *
	 * Must be an ObjectEntity double, not an anonymous JsonSerializable:
	 * ObjectService::find() is typed `?ObjectEntity` in production, so any
	 * other JsonSerializable is a value the service can never hand the code
	 * under test (#399).
	 *
	 * @param array<string,mixed> $data Object data
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function createEntityMock(array $data): ObjectEntity&MockObject {
		$mock = $this->createMock(ObjectEntity::class);
		$mock->method('jsonSerialize')->willReturn($data);
		return $mock;
	}//end createEntityMock()

	/**
	 * Wire a full meeting fixture into the object service mocks.
	 *
	 * @param array<string,mixed> $meeting Meeting payload
	 *
	 * @return void
	 */
	private function wireMeetingFixture(array $meeting): void {
		$meetingEntity = $this->createEntityMock($meeting);

		$this->objectService->method('find')->willReturnCallback(
			static function (string $id) use ($meetingEntity, $meeting): ?object {
				if ($id === ($meeting['id'] ?? '')) {
					return $meetingEntity;
				}

				return null;
			}
		);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'agenda-item') {
					return [
						$this->createEntityMock(['id' => 'ai-2', 'title' => 'Begroting 2027', 'orderNumber' => 2]),
						$this->createEntityMock(['id' => 'ai-1', 'title' => 'Opening', 'orderNumber' => 1]),
					];
				}

				if ($schema === 'voting-round') {
					return [
						$this->createEntityMock(
							[
								'id' => 'vr-1',
								'votingMethod' => 'roll-call',
								'votesFor' => 14,
								'votesAgainst' => 5,
								'votesAbstain' => 1,
								'result' => 'passed',
								'quorumWith' => true,
							]
						),
					];
				}

				if ($schema === 'decision') {
					return [
						$this->createEntityMock(
							[
								'id' => 'dec-1',
								'title' => 'Statutenwijziging',
								'text' => 'De statuten worden gewijzigd.',
								'outcome' => 'adopted',
								'legalBasis' => 'Artikel 160 Gemeentewet',
								'decisionDate' => '2026-06-12',
								'lifecycle' => 'enacted',
							]
						),
					];
				}

				return [];
			}
		);

	}//end wireMeetingFixture()

	/**
	 * Happy path: the package contains convocation, quorum, votes, and
	 * decision evidence; both files are written; the SHA-256 hash verifies
	 * against the canonical JSON of the package member.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAssembleProducesVerifiableSealedPackage(): void {
		$this->wireMeetingFixture(
			[
				'id' => 'meeting-001',
				'title' => 'Raadsvergadering juni',
				'meetingType' => 'council',
				'scheduledDate' => '2026-06-12T19:00:00Z',
				'quorumRequired' => 10,
				'@self' => ['created' => '2026-06-01T08:00:00Z'],
			]
		);

		$this->participantResolver->method('resolveMeetingParticipants')->willReturn(
			[
				['displayName' => 'A', 'role' => 'chair', 'attendanceStatus' => 'present'],
				['displayName' => 'B', 'role' => 'member', 'attendanceStatus' => 'remote'],
				['displayName' => 'C', 'role' => 'member', 'attendanceStatus' => 'absent'],
			]
		);

		$writes = [];
		$this->folderService->method('writeMeetingFile')->willReturnCallback(
			static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$writes): string {
				$writes[$fileName] = ['subfolder' => $subfolder, 'content' => $content];
				return 'Decidesk/x/' . $subfolder . '/' . $fileName;
			}
		);

		$result = $this->service->assemble(meetingId: 'meeting-001', generatedBy: 'Secretaris');

		self::assertCount(2, $result['files']);
		self::assertCount(2, $writes);

		$jsonName = null;
		foreach (array_keys($writes) as $name) {
			self::assertSame('Minutes', $writes[$name]['subfolder']);
			if (str_ends_with($name, '.json') === true) {
				$jsonName = $name;
			}
		}

		self::assertNotNull($jsonName);
		$envelope = json_decode($writes[$jsonName]['content'], true);

		// The seal must verify: recompute sha256 over the canonical
		// (recursively key-sorted, compact) JSON of the package member.
		$canonical = json_encode(
			$this->ksortRecursive($envelope['package']),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		self::assertSame(hash('sha256', $canonical), $envelope['integrity']['hash']);
		self::assertSame($result['sha256'], $envelope['integrity']['hash']);

		// Evidence members.
		$package = $envelope['package'];
		self::assertSame('Raadsvergadering juni', $package['meeting']['title']);
		self::assertSame('Opening', $package['convocation']['agenda'][0]['title']);
		self::assertSame('Begroting 2027', $package['convocation']['agenda'][1]['title']);
		self::assertSame(2, $package['quorum']['present']);
		self::assertSame(10, $package['quorum']['required']);
		self::assertFalse($package['quorum']['met']);
		self::assertTrue($package['quorum']['attendanceRecorded']);
		self::assertSame(14, $package['votes'][0]['votesFor']);
		self::assertSame('Statutenwijziging', $package['decisions'][0]['title']);
		self::assertSame('Artikel 160 Gemeentewet', $package['decisions'][0]['legalBasis']);

	}//end testAssembleProducesVerifiableSealedPackage()

	/**
	 * Honesty contract: unrecorded attendance and missing convocation
	 * notice are reported as recorded:false — never fabricated.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAssembleReportsUnrecordedEvidenceHonestly(): void {
		$this->wireMeetingFixture(['id' => 'meeting-002', 'title' => 'Sync']);

		$this->participantResolver->method('resolveMeetingParticipants')->willReturn(
			[
				['displayName' => 'A', 'role' => 'chair'],
			]
		);

		$writes = [];
		$this->folderService->method('writeMeetingFile')->willReturnCallback(
			static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$writes): string {
				$writes[$fileName] = $content;
				return 'x/' . $fileName;
			}
		);

		$this->service->assemble(meetingId: 'meeting-002', generatedBy: 'S');

		$jsonContent = null;
		foreach ($writes as $name => $content) {
			if (str_ends_with($name, '.json') === true) {
				$jsonContent = $content;
			}
		}

		$package = json_decode($jsonContent, true)['package'];

		self::assertFalse($package['convocation']['noticeRecorded']);
		self::assertFalse($package['quorum']['attendanceRecorded']);
		self::assertFalse($package['quorum']['met']);

	}//end testAssembleReportsUnrecordedEvidenceHonestly()

	/**
	 * Unknown meeting throws MissingObjectException.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAssembleUnknownMeetingThrowsMissingObjectException(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(MissingObjectException::class);

		$this->service->assemble(meetingId: 'meeting-404', generatedBy: 'S');

	}//end testAssembleUnknownMeetingThrowsMissingObjectException()

	/**
	 * A failed file write becomes a RuntimeException (503 semantics).
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAssembleThrowsRuntimeExceptionWhenWriteFails(): void {
		$this->wireMeetingFixture(['id' => 'meeting-003', 'title' => 'Sync']);
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([]);
		$this->folderService->method('writeMeetingFile')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('could not be stored');

		$this->service->assemble(meetingId: 'meeting-003', generatedBy: 'S');

	}//end testAssembleThrowsRuntimeExceptionWhenWriteFails()

	/**
	 * OpenRegister unavailability surfaces as a RuntimeException.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAssembleThrowsRuntimeExceptionWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \Exception('Service not found'));

		$service = new ProofPackageService(
			logger: $this->createMock(LoggerInterface::class),
			participantResolver: $this->participantResolver,
			folderService: $this->folderService,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister ObjectService is not available');

		$service->assemble(meetingId: 'any', generatedBy: 'S');

	}//end testAssembleThrowsRuntimeExceptionWhenOpenRegisterUnavailable()

	/**
	 * Helper: recursively key-sort associative arrays (mirror of the
	 * service's canonicalisation, used to verify the seal independently).
	 *
	 * @param mixed $data The data to sort
	 *
	 * @return mixed
	 */
	private function ksortRecursive(mixed $data): mixed {
		if (is_array($data) === false) {
			return $data;
		}

		foreach ($data as $key => $value) {
			$data[$key] = $this->ksortRecursive($value);
		}

		if (array_is_list($data) === false) {
			ksort($data);
		}

		return $data;
	}//end ksortRecursive()
}//end class
