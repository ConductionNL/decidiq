<?php

/**
 * Unit tests for BoardBookService.
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
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Kernel\Contracts\MeetingPackageServiceInterface;
use OCA\Decidiq\Service\BoardBook\BoardBookAgenda;
use OCA\Decidiq\Service\BoardBook\BoardBookComposer;
use OCA\Decidiq\Service\BoardBook\BoardBookDelivery;
use OCA\Decidiq\Service\BoardBook\BoardBookRenderer;
use OCA\Decidiq\Service\BoardBookService;
use OCA\Decidiq\Service\MeetingFolderService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Compiling, versioning, the folder-package fallback and the outdated check.
 */
#[CoversClass(BoardBookService::class)]
#[UsesClass(BoardBookComposer::class)]
class BoardBookServiceTest extends TestCase {

	private BoardBookAgenda&MockObject $agenda;

	private BoardBookRenderer&MockObject $renderer;

	private MeetingFolderService&MockObject $folders;

	private MeetingPackageServiceInterface&MockObject $packages;

	private BoardBookDelivery&MockObject $delivery;

	/**
	 * The meeting, two items and earlier packs as given.
	 *
	 * @param array<int, array<string, mixed>> $packs The earlier packs, newest first.
	 * @param array<int, array<string, mixed>> $items The agenda items.
	 *
	 * @return BoardBookService
	 */
	private function service(array $packs=[], ?array $items=null): BoardBookService {
		$this->agenda = $this->createMock(BoardBookAgenda::class);
		$this->agenda->method('meeting')->willReturnCallback(static fn (string $id): ?array => $id === 'm1' ? ['id' => 'm1', 'title' => 'Raad', 'scheduledDate' => '2025-01-15'] : null);
		$this->agenda->method('items')->willReturn($items ?? [['id' => 'a', 'orderNumber' => 1, 'title' => 'Opening'], ['id' => 'b', 'orderNumber' => 2, 'title' => 'Begroting']]);
		$this->agenda->method('packs')->willReturn($packs);
		$this->agenda->method('attachments')->willReturn(['rows' => ['a' => [], 'b' => [['id' => '9', 'etag' => 'e']]], 'nodes' => ['a' => [], 'b' => []]]);
		$this->agenda->method('bodyName')->willReturn('Gemeenteraad');
		$this->renderer = $this->createMock(BoardBookRenderer::class);
		$this->folders = $this->createMock(MeetingFolderService::class);
		$this->packages = $this->createMock(MeetingPackageServiceInterface::class);
		$this->delivery = $this->createMock(BoardBookDelivery::class);
		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('resolveMeetingParticipants')->willReturn([['nextcloudUserId' => 'jan']]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1736935200);

		return new BoardBookService(
			agenda: $this->agenda,
			renderer: $this->renderer,
			composer: new BoardBookComposer(),
			delivery: $this->delivery,
			folders: $this->folders,
			packages: $this->packages,
			participants: $participants,
			logger: $this->createMock(LoggerInterface::class),
			time: $time,
		);
	}//end service()

	/**
	 * A meeting without agenda items gets no pack and the refusal in words.
	 *
	 * @return void
	 */
	public function testAnEmptyAgendaIsRefused(): void {
		$service = $this->service(items: []);
		$this->renderer->expects($this->never())->method('render');
		$this->agenda->expects($this->never())->method('savePack');

		$result = $service->compile(meetingId: 'm1', userId: 'griffier');

		$this->assertSame(expected: [false, 'empty_agenda', BoardBookComposer::EMPTY_AGENDA], actual: [$result['success'], $result['code'], $result['message']]);
		$this->assertSame(expected: 'not_found', actual: $service->precheck(meetingId: 'other', changeNote: '')['code']);
	}//end testAnEmptyAgendaIsRefused()

	/**
	 * A second version without a change note is refused; with one it is version 2.
	 *
	 * @return void
	 */
	public function testRecompilingNeedsAChangeNote(): void {
		$service = $this->service(packs: [['version' => 1]]);

		$this->assertSame(expected: ['ok' => false, 'code' => 'change_note_required'], actual: array_intersect_key($service->precheck(meetingId: 'm1', changeNote: '  '), ['ok' => 1, 'code' => 1]));
		$this->assertSame(expected: ['ok' => true, 'nextVersion' => 2], actual: array_intersect_key($service->precheck(meetingId: 'm1', changeNote: 'Bijlage toegevoegd'), ['ok' => 1, 'nextVersion' => 1]));
		$this->assertSame(expected: 1, actual: $this->service()->precheck(meetingId: 'm1', changeNote: '')['nextVersion']);
	}//end testRecompilingNeedsAChangeNote()

	/**
	 * Version 2 is written under its own name, delivered and recorded with every field.
	 *
	 * @return void
	 */
	public function testCompilesTheNextVersionAndRecordsIt(): void {
		$service = $this->service(packs: [['version' => 1, 'fingerprint' => 'old']]);
		$this->renderer->method('render')->willReturn(['pdf' => '%PDF << /Type /Page >> << /Type /Page >>', 'skipped' => ['02 - Begroting/x.docx (non-PDF attachment)']]);
		$file = $this->createMock(File::class);
		$this->folders->method('meetingFile')->willReturnOnConsecutiveCalls(null, $file);
		$this->folders->expects($this->once())->method('writeMeetingFile')
			->with(['id' => 'm1', 'title' => 'Raad', 'scheduledDate' => '2025-01-15'], BoardBookService::SUBFOLDER, 'Vergaderbundel Raad 2025-01-15 v2.pdf', $this->anything())
			->willReturn('Vergaderingen/Raad/Vergaderbundel/Vergaderbundel Raad 2025-01-15 v2.pdf');
		$this->delivery->expects($this->once())->method('deliver')->with($file, [['nextcloudUserId' => 'jan']], 'griffier')->willReturn(['Piet (no Nextcloud account)']);
		$saved = null;
		$this->agenda->expects($this->once())->method('savePack')->willReturnCallback(
			static function (array $pack) use (&$saved): array {
				$saved = $pack;
				return $pack;
			}
		);

		$result = $service->compile(meetingId: 'm1', userId: 'griffier', changeNote: ' Bijlage begroting toegevoegd ');

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(
			expected: [
				'meeting' => 'm1',
				'version' => 2,
				'changeNote' => 'Bijlage begroting toegevoegd',
				'generatedAt' => '2025-01-15T10:00:00Z',
				'generatedBy' => 'griffier',
				'fingerprint' => (new BoardBookComposer())->fingerprint(items: [['id' => 'a', 'orderNumber' => 1, 'title' => 'Opening'], ['id' => 'b', 'orderNumber' => 2, 'title' => 'Begroting']], attachments: ['a' => [], 'b' => [['id' => '9', 'etag' => 'e']]]),
				'filePath' => 'Vergaderingen/Raad/Vergaderbundel/Vergaderbundel Raad 2025-01-15 v2.pdf',
				'pageCount' => 2,
				'skipped' => ['02 - Begroting/x.docx (non-PDF attachment)'],
				'deliveryFailures' => ['Piet (no Nextcloud account)'],
			],
			actual: $saved
		);
	}//end testCompilesTheNextVersionAndRecordsIt()

	/**
	 * An existing file of that version is never overwritten.
	 *
	 * @return void
	 */
	public function testAnExistingVersionFileIsNotOverwritten(): void {
		$service = $this->service();
		$this->renderer->method('render')->willReturn(['pdf' => '%PDF', 'skipped' => []]);
		$this->folders->method('meetingFile')->willReturn($this->createMock(File::class));
		$this->folders->expects($this->never())->method('writeMeetingFile');
		$this->agenda->expects($this->never())->method('savePack');

		$this->assertSame(expected: 'version_exists', actual: $service->compile(meetingId: 'm1', userId: 'griffier')['code']);
	}//end testAnExistingVersionFileIsNotOverwritten()

	/**
	 * Without filinq the folder package is assembled and the answer says no PDF was made.
	 *
	 * @return void
	 */
	public function testWithoutFilinqTheFolderPackageIsMadeAndSaidToBeNoPdf(): void {
		$service = $this->service();
		$this->renderer->method('render')->willReturn(null);
		$this->packages->expects($this->once())->method('assemble')->with('m1', 'griffier')
			->willReturn(['success' => true, 'path' => 'Vergaderingen/Raad/Meeting package', 'items' => 2, 'files' => 1, 'skipped' => [], 'message' => 'Meeting package assembled.']);
		$this->agenda->expects($this->never())->method('savePack');

		$result = $service->compile(meetingId: 'm1', userId: 'griffier');

		$this->assertSame(expected: [true, false, 'folder_package'], actual: [$result['success'], $result['pdf'], $result['code']]);
		$this->assertStringStartsWith(prefix: BoardBookService::NO_PDF, string: $result['message']);
	}//end testWithoutFilinqTheFolderPackageIsMadeAndSaidToBeNoPdf()

	/**
	 * The latest pack is outdated when the agenda's fingerprint moved, fresh when it did not.
	 *
	 * @return void
	 */
	public function testFreshnessComparesTheFingerprints(): void {
		$current = $this->service()->freshness(meetingId: 'm1')['currentFingerprint'];

		$fresh = $this->service(packs: [['version' => 3, 'fingerprint' => $current, 'skipped' => ['x'], 'deliveryFailures' => []]])->freshness(meetingId: 'm1');
		$stale = $this->service(packs: [['version' => 3, 'fingerprint' => 'sha256:old']])->freshness(meetingId: 'm1');

		$this->assertSame(expected: [3, false, ['x']], actual: [$fresh['latestVersion'], $fresh['outdated'], $fresh['skipped']]);
		$this->assertTrue(condition: $stale['outdated']);
		$this->assertSame(expected: [0, false], actual: [$this->service()->freshness(meetingId: 'm1')['latestVersion'], $this->service()->freshness(meetingId: 'm1')['outdated']]);
		$this->assertNull(actual: $this->service()->freshness(meetingId: 'other'));
	}//end testFreshnessComparesTheFingerprints()
}//end class
