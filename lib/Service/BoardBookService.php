<?php

/**
 * Decidiq Board Book Service
 *
 * Compiles a meeting pack (vergaderbundel): one PDF of the cover, the table
 * of contents and every agenda item with its attachments, written as a new
 * version into the meeting's Files folder, recorded as a MeetingPack object
 * and shared with the attendees. Without filinq it says so and assembles the
 * folder package instead; it never pretends a PDF was made.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Decidiq\Kernel\Contracts\BoardBookServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingPackageServiceInterface;
use OCA\Decidiq\Service\BoardBook\BoardBookAgenda;
use OCA\Decidiq\Service\BoardBook\BoardBookComposer;
use OCA\Decidiq\Service\BoardBook\BoardBookDelivery;
use OCA\Decidiq\Service\BoardBook\BoardBookRenderer;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Compiles, versions and delivers a meeting pack.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
 */
class BoardBookService implements BoardBookServiceInterface {

	/**
	 * The meeting subfolder the packs are written to.
	 *
	 * @var string
	 */
	public const SUBFOLDER = 'Vergaderbundel';

	/**
	 * The longest change note kept.
	 *
	 * @var int
	 */
	public const MAX_CHANGE_NOTE = 1000;

	/**
	 * What the result says when filinq cannot render the pack.
	 *
	 * @var string
	 */
	public const NO_PDF = 'filinq is not available on this instance: no PDF was compiled, the folder package was assembled instead.';

	/**
	 * Constructor.
	 *
	 * @param BoardBookAgenda                $agenda       Reads the meeting, its agenda and its packs.
	 * @param BoardBookRenderer              $renderer     Renders the pack PDF.
	 * @param BoardBookComposer              $composer     File name and fingerprint.
	 * @param BoardBookDelivery              $delivery     Shares the pack with the attendees.
	 * @param MeetingFolderService           $folders      Writes into the meeting's Files folder.
	 * @param MeetingPackageServiceInterface $packages     The folder package, without filinq.
	 * @param ParticipantResolver            $participants The meeting's participants.
	 * @param LoggerInterface                $logger       The logger.
	 * @param ITimeFactory|null              $time         The clock; the system clock when absent.
	 */
	public function __construct(
		private readonly BoardBookAgenda $agenda,
		private readonly BoardBookRenderer $renderer,
		private readonly BoardBookComposer $composer,
		private readonly BoardBookDelivery $delivery,
		private readonly MeetingFolderService $folders,
		private readonly MeetingPackageServiceInterface $packages,
		private readonly ParticipantResolver $participants,
		private readonly LoggerInterface $logger,
		private readonly ?ITimeFactory $time=null,
	) {
	}//end __construct()

	/**
	 * Whether a compilation request may go ahead: the meeting is readable,
	 * its agenda is not empty, and from version 2 a change note is given.
	 *
	 * @param string $meetingId  UUID of the meeting
	 * @param string $changeNote The change note sent with the request
	 *
	 * @return array{ok: bool, code: string, message: string, nextVersion: int}
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking
	 */
	public function precheck(string $meetingId, string $changeNote): array {
		if ($this->agenda->meeting(meetingId: $meetingId) === null) {
			return $this->verdict(code: 'not_found', message: 'Meeting not found.');
		}

		if ($this->agenda->items(meetingId: $meetingId) === []) {
			return $this->verdict(code: 'empty_agenda', message: BoardBookComposer::EMPTY_AGENDA);
		}

		$next = ($this->latestVersion(packs: $this->agenda->packs(meetingId: $meetingId)) + 1);
		if ($next > 1 && trim($changeNote) === '') {
			return $this->verdict(code: 'change_note_required', message: 'Geef aan wat er in deze versie is veranderd.', nextVersion: $next);
		}

		return ['ok' => true, 'code' => 'ok', 'message' => '', 'nextVersion' => $next];
	}//end precheck()

	/**
	 * Compile the next version of a meeting's pack and deliver it.
	 *
	 * @param string $meetingId  UUID of the meeting
	 * @param string $userId     Who compiles it
	 * @param string $changeNote Why this version differs; required from version 2
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
	 */
	public function compile(string $meetingId, string $userId, string $changeNote=''): array {
		$check = $this->precheck(meetingId: $meetingId, changeNote: $changeNote);
		if ($check['ok'] === false) {
			return ['success' => false, 'code' => $check['code'], 'message' => $check['message']];
		}

		$meeting = (array)$this->agenda->meeting(meetingId: $meetingId);
		$items = $this->agenda->items(meetingId: $meetingId);
		$attachments = $this->agenda->attachments(items: $items);
		$bodyName = $this->agenda->bodyName(meeting: $meeting);
		$rendered = $this->renderer->render(meeting: $meeting, bodyName: $bodyName, items: $items, attachments: $attachments);
		if ($rendered === null) {
			return $this->folderPackage(meetingId: $meetingId, userId: $userId);
		}

		$version = $check['nextVersion'];
		$fileName = $this->composer->fileName(meeting: $meeting, version: $version);
		if ($this->folders->meetingFile(meeting: $meeting, subfolder: self::SUBFOLDER, fileName: $fileName) !== null) {
			// Never overwrite an earlier version's file.
			$message = sprintf('%s already exists; it is not overwritten.', $fileName);
			return ['success' => false, 'code' => 'version_exists', 'message' => $message];
		}

		$path = $this->folders->writeMeetingFile(meeting: $meeting, subfolder: self::SUBFOLDER, fileName: $fileName, content: $rendered['pdf']);
		if ($path === null) {
			return ['success' => false, 'code' => 'files_unavailable', 'message' => 'The meeting pack could not be written to Files.'];
		}

		$pack = [
			'meeting' => $meetingId,
			'version' => $version,
			'changeNote' => mb_substr(trim($changeNote), 0, self::MAX_CHANGE_NOTE),
			'generatedAt' => $this->now(),
			'generatedBy' => $userId,
			'fingerprint' => $this->composer->fingerprint(items: $items, attachments: $attachments['rows']),
			'filePath' => $path,
			'pageCount' => $this->composer->pageCount(pdf: $rendered['pdf']),
			'skipped' => $rendered['skipped'],
			'deliveryFailures' => $this->deliver(meeting: $meeting, fileName: $fileName, userId: $userId),
		];
		$this->agenda->savePack(pack: $pack);
		$this->logger->info('Decidiq: meeting pack compiled', ['meetingId' => $meetingId, 'version' => $version, 'skipped' => count($pack['skipped'])]);

		$message = sprintf('Vergaderbundel versie %d is samengesteld.', $version);
		return ['success' => true, 'code' => 'compiled', 'pdf' => true, 'message' => $message] + $pack;
	}//end compile()

	/**
	 * The latest pack's version, fingerprint and reports, and whether the
	 * agenda changed since it was compiled.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator
	 */
	public function freshness(string $meetingId): ?array {
		if ($this->agenda->meeting(meetingId: $meetingId) === null) {
			return null;
		}

		$items = $this->agenda->items(meetingId: $meetingId);
		$current = $this->composer->fingerprint(items: $items, attachments: $this->agenda->attachments(items: $items)['rows']);
		$latest = ($this->agenda->packs(meetingId: $meetingId)[0] ?? null);
		if ($latest === null) {
			return [
				'latestVersion' => 0,
				'latestFingerprint' => null,
				'currentFingerprint' => $current,
				'outdated' => false,
				'skipped' => [],
				'deliveryFailures' => [],
			];
		}

		$latestFingerprint = (string)($latest['fingerprint'] ?? '');

		return [
			'latestVersion' => (int)($latest['version'] ?? 0),
			'latestFingerprint' => $latestFingerprint,
			'currentFingerprint' => $current,
			'outdated' => $latestFingerprint !== $current,
			'skipped' => array_values((array)($latest['skipped'] ?? [])),
			'deliveryFailures' => array_values((array)($latest['deliveryFailures'] ?? [])),
		];
	}//end freshness()

	/**
	 * The folder package, said plainly to be no PDF.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId    Who compiles it
	 *
	 * @return array<string, mixed>
	 */
	private function folderPackage(string $meetingId, string $userId): array {
		$package = $this->packages->assemble(meetingId: $meetingId, userId: $userId);

		return [
			'success' => ($package['success'] ?? false) === true,
			'code' => 'folder_package',
			'pdf' => false,
			'path' => ($package['path'] ?? null),
			'skipped' => ($package['skipped'] ?? []),
			'message' => trim(self::NO_PDF . ' ' . (string)($package['message'] ?? '')),
		];
	}//end folderPackage()

	/**
	 * Share the written pack with the attendees; the failures by attendee.
	 *
	 * @param array<string, mixed> $meeting  The meeting.
	 * @param string               $fileName The pack's file name.
	 * @param string               $userId   Who compiled it.
	 *
	 * @return array<int, string>
	 */
	private function deliver(array $meeting, string $fileName, string $userId): array {
		$file = $this->folders->meetingFile(meeting: $meeting, subfolder: self::SUBFOLDER, fileName: $fileName);
		if ($file === null) {
			return ['every attendee (the pack file could not be read back)'];
		}

		return $this->delivery->deliver(
			file: $file,
			participants: $this->participants->resolveMeetingParticipants(meetingId: (string)$meeting['id']),
			sharedBy: $userId
		);
	}//end deliver()

	/**
	 * The highest version among the packs, 0 for none.
	 *
	 * @param array<int, array<string, mixed>> $packs The packs.
	 *
	 * @return int
	 */
	private function latestVersion(array $packs): int {
		$latest = 0;
		foreach ($packs as $pack) {
			$latest = max($latest, (int)($pack['version'] ?? 0));
		}

		return $latest;
	}//end latestVersion()

	/**
	 * A refusing precheck.
	 *
	 * @param string $code        The refusal code.
	 * @param string $message     The message.
	 * @param int    $nextVersion The version it would have been.
	 *
	 * @return array{ok: bool, code: string, message: string, nextVersion: int}
	 */
	private function verdict(string $code, string $message, int $nextVersion=0): array {
		return ['ok' => false, 'code' => $code, 'message' => $message, 'nextVersion' => $nextVersion];
	}//end verdict()

	/**
	 * Now, as an ISO 8601 UTC date-time.
	 *
	 * @return string
	 */
	private function now(): string {
		$seconds = time();
		if ($this->time !== null) {
			$seconds = $this->time->getTime();
		}

		return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end now()
}//end class
