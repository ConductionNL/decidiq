<?php

/**
 * Unit tests for ProofPackageService.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProofPackageService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
	private ObjectServiceInterface&MockObject $objectService;

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
	 * The minutes rows the meeting carries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $minutesRows = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock OpenRegister's published contract so that named-argument calls
		// (find(id: ..., register: ...)) bind correctly. Since ADR-083 this
		// double must be INJECTED — the service no longer asks a container for
		// it, so one parked on a container mock would never be consulted.
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		$this->participantResolver = $this->createMock(ParticipantResolver::class);
		$this->folderService = $this->createMock(MeetingFolderService::class);

		$this->service = new ProofPackageService(
			logger: $this->createMock(LoggerInterface::class),
			participantResolver: $this->participantResolver,
			folderService: $this->folderService,
			objectService: $this->objectService,
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

				if ($schema === 'minutes') {
					return array_map(fn (array $row): ObjectEntity => $this->createEntityMock($row), $this->minutesRows);
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
				return 'Decidiq/x/' . $subfolder . '/' . $fileName;
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
	 * The result lists the JSON file before the Markdown file, both named after
	 * the same stamp; the package prefers the top-level meeting id over @self.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAssembleReturnsJsonThenMarkdownPathAndSelfId(): void {
		$this->wireMeetingFixture(['@self' => ['id' => 'self-007'], 'id' => 'meeting-007', 'title' => 'Sync']);
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([]);

		$writes = [];
		$this->folderService->method('writeMeetingFile')->willReturnCallback(
			static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$writes): string {
				$writes[] = ['subfolder' => $subfolder, 'fileName' => $fileName, 'content' => $content];
				return '/Meetings/Sync/' . $subfolder . '/' . $fileName;
			}
		);

		$result = $this->service->assemble(meetingId: 'meeting-007', generatedBy: 'Griffier');

		self::assertCount(2, $writes);
		self::assertMatchesRegularExpression('/^Proof package \d{4}-\d{2}-\d{2} \d{4}\.json$/', $writes[0]['fileName']);
		self::assertSame(substr($writes[0]['fileName'], 0, -5) . '.md', $writes[1]['fileName']);
		self::assertSame(
			['/Meetings/Sync/Minutes/' . $writes[0]['fileName'], '/Meetings/Sync/Minutes/' . $writes[1]['fileName']],
			$result['files']
		);

		$envelope = json_decode($writes[0]['content'], true);
		self::assertSame('meeting-007', $envelope['package']['meeting']['id']);
		self::assertSame('Griffier', $envelope['generatedBy']);
		self::assertSame($envelope['generatedAt'], $result['generatedAt']);
		self::assertSame(['package', 'integrity', 'generatedAt', 'generatedBy'], array_keys($envelope));
		self::assertSame(
			['meeting', 'convocation', 'quorum', 'votes', 'decisions', 'minutes', 'signatures'],
			array_keys($envelope['package'])
		);
		self::assertStringContainsString($result['sha256'], $writes[1]['content']);

	}//end testAssembleReturnsJsonThenMarkdownPathAndSelfId()

	/**
	 * A failed Markdown write after a successful JSON write still fails the
	 * whole package with 503 semantics.
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 *
	 * @return void
	 */
	public function testAssembleThrowsWhenOnlyMarkdownWriteFails(): void {
		$this->wireMeetingFixture(['id' => 'meeting-008', 'title' => 'Sync']);
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn([]);
		$this->folderService->expects(self::exactly(2))->method('writeMeetingFile')->willReturnCallback(
			static function (array $meeting, string $subfolder, string $fileName): ?string {
				return str_ends_with($fileName, '.json') === true ? '/x/' . $fileName : null;
			}
		);

		try {
			$this->service->assemble(meetingId: 'meeting-008', generatedBy: 'S');
			self::fail('Expected a RuntimeException.');
		} catch (\RuntimeException $e) {
			self::assertSame(503, $e->getCode());
			self::assertSame('The proof package could not be stored: the Files backend is unavailable.', $e->getMessage());
		}

	}//end testAssembleThrowsWhenOnlyMarkdownWriteFails()

	/*
	 * REMOVED: testAssembleThrowsRuntimeExceptionWhenOpenRegisterUnavailable().
	 *
	 * It asserted `RuntimeException: 'OpenRegister ObjectService is not
	 * available'` out of assemble(). ADR-083 deleted that failure mode: the
	 * service no longer resolves OpenRegister from the container at call time,
	 * it receives ObjectServiceInterface as a REQUIRED constructor argument.
	 * "OpenRegister is absent" is therefore a DI-construction failure now, not
	 * an assemble() outcome — the string it expected exists nowhere in lib/
	 * (`grep -rn 'ObjectService is not available' lib/` = 0 hits). No honest
	 * wiring of this test can produce it, so it is gone rather than weakened.
	 */

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

	/**
	 * Assemble the meeting fixture and return the written JSON package and markdown.
	 *
	 * @return array{0: array<string, mixed>, 1: string} The package member and the markdown.
	 */
	private function assembleWritten(): array {
		$this->wireMeetingFixture(['id' => 'meeting-010', 'title' => 'Raad', 'scheduledDate' => '2026-06-12']);
		$this->participantResolver->method('resolveMeetingParticipants')->willReturn(
			[['displayName' => 'A', 'role' => 'chair', 'attendanceStatus' => 'present']]
		);
		$writes = [];
		$this->folderService->method('writeMeetingFile')->willReturnCallback(
			static function (array $meeting, string $subfolder, string $fileName, string $content) use (&$writes): string {
				$writes[pathinfo($fileName, PATHINFO_EXTENSION)] = $content;
				return $fileName;
			}
		);

		$this->service->assemble(meetingId: 'meeting-010', generatedBy: 'Griffier');
		$envelope = json_decode($writes['json'], true);

		self::assertSame(
			hash('sha256', (string)json_encode($this->ksortRecursive($envelope['package']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
			$envelope['integrity']['hash'],
			'The minutes and signatures sit inside the hashed package.'
		);

		return [$envelope['package'], $writes['md']];
	}//end assembleWritten()

	/**
	 * The approved, signed minutes go into the hashed package with their signers,
	 * next to the round results and the attendance list.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/resolution-minutes/spec.md#requirement-a-meetings-evidence-is-bundled-into-one-proof-package
	 *
	 * @return void
	 */
	public function testSignedApprovedMinutesAreInTheHashedPackage(): void {
		$this->minutesRows = [
			['id' => 'min-1', 'title' => 'Notulen v1', 'lifecycle' => 'review', 'version' => 1, 'content' => 'oud'],
			['id' => 'min-2', 'title' => 'Notulen juni', 'lifecycle' => 'signed', 'version' => 2, 'approvedAt' => '2026-07-01T10:00:00Z', 'content' => 'De raad besluit.', 'signedBy' => [' Roos de Vries ', '', 'Jan Bakker']],
		];

		[$package, $markdown] = $this->assembleWritten();

		self::assertSame(
			['recorded' => true, 'approved' => true, 'state' => 'signed', 'id' => 'min-2', 'title' => 'Notulen juni', 'version' => 2, 'approvedAt' => '2026-07-01T10:00:00Z', 'content' => 'De raad besluit.'],
			$package['minutes']
		);
		self::assertSame(['signed' => true, 'signedBy' => ['Roos de Vries', 'Jan Bakker']], $package['signatures']);
		self::assertSame('A', $package['quorum']['participants'][0]['displayName']);
		self::assertSame('vr-1', $package['votes'][0]['id']);
		self::assertStringContainsString("De raad besluit.", $markdown);
		self::assertStringContainsString("- Roos de Vries\n- Jan Bakker", $markdown);
	}//end testSignedApprovedMinutesAreInTheHashedPackage()

	/**
	 * Draft minutes are named with their state, not included and not left out silently.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/resolution-minutes/spec.md#requirement-a-meetings-evidence-is-bundled-into-one-proof-package
	 *
	 * @return void
	 */
	public function testMinutesNotYetApprovedAreNamedWithTheirState(): void {
		$this->minutesRows = [['id' => 'min-1', 'lifecycle' => 'draft', 'version' => 1, 'content' => 'concept tekst', 'signedBy' => []]];

		[$package, $markdown] = $this->assembleWritten();

		self::assertSame(
			['recorded' => true, 'approved' => false, 'state' => 'draft', 'version' => 1, 'note' => 'The minutes are not approved; their current state is "draft".'],
			$package['minutes']
		);
		self::assertSame(['signed' => false, 'signedBy' => []], $package['signatures']);
		self::assertStringNotContainsString('concept tekst', $markdown);
		self::assertStringContainsString('_The minutes are not approved; their current state is "draft"._', $markdown);
		self::assertStringContainsString('_Geen ondertekening vastgelegd._', $markdown);
	}//end testMinutesNotYetApprovedAreNamedWithTheirState()

	/**
	 * A meeting without minutes says so.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/resolution-minutes/spec.md#requirement-a-meetings-evidence-is-bundled-into-one-proof-package
	 *
	 * @return void
	 */
	public function testAMeetingWithoutMinutesSaysSo(): void {
		[$package] = $this->assembleWritten();

		self::assertSame(['recorded' => false, 'approved' => false, 'state' => 'missing', 'note' => 'No minutes are recorded for this meeting.'], $package['minutes']);
		self::assertSame(['signed' => false, 'signedBy' => []], $package['signatures']);
	}//end testAMeetingWithoutMinutesSaysSo()

}//end class
