<?php

/**
 * The meeting recording plays in the browser and seeks: byte ranges, and who
 * may listen (live-recording-jump-to-item, matrix row liv-08).
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
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\RecordingRange;
use OCA\Decidiq\Service\TranscriptionStaffGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Byte ranges for seeking, and the listener guard.
 */
class RecordingPlaybackTest extends TestCase {

	/**
	 * A browser that seeks asks for the rest of the file from a byte.
	 *
	 * @return void
	 */
	public function testAnOpenRangeRunsToTheEnd(): void {
		$this->assertSame([1000, 9999], (new RecordingRange())->parse(header: 'bytes=1000-', size: 10000));
	}//end testAnOpenRangeRunsToTheEnd()

	/**
	 * A closed range is served as asked, and one past the end is clipped.
	 *
	 * @return void
	 */
	public function testAClosedRangeIsServedAsAsked(): void {
		$range = new RecordingRange();
		$this->assertSame([0, 1], $range->parse(header: 'bytes=0-1', size: 10000));
		$this->assertSame([9000, 9999], $range->parse(header: 'bytes=9000-20000', size: 10000));
		$this->assertSame([9900, 9999], $range->parse(header: 'bytes=-100', size: 10000));
	}//end testAClosedRangeIsServedAsAsked()

	/**
	 * No header or one this does not understand serves the whole file; a
	 * start past the end cannot be served.
	 *
	 * @return void
	 */
	public function testNoRangeIsTheWholeFileAndAnImpossibleOneIsRefused(): void {
		$range = new RecordingRange();
		$this->assertNull($range->parse(header: null, size: 10000));
		$this->assertNull($range->parse(header: 'bytes=0-10,20-30', size: 10000));
		$this->assertNull($range->parse(header: 'items=1-2', size: 10000));
		$this->assertFalse($range->parse(header: 'bytes=20000-', size: 10000));
	}//end testNoRangeIsTheWholeFileAndAnImpossibleOneIsRefused()

	/**
	 * A guard over a transcript of meeting m-14 for the given caller.
	 *
	 * @param bool $participant Whether the caller takes part in m-14.
	 *
	 * @return TranscriptionStaffGuard
	 */
	private function guard(bool $participant): TranscriptionStaffGuard {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 't-1', 'meeting' => 'm-14']);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($entity);
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('isParticipant')->with('m-14', 'pieter')->willReturn($participant);
		$resolver->method('hasRole')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new TranscriptionStaffGuard(objectService: $objectService, participantResolver: $resolver, userSession: $session, groupManager: $groups);
	}//end guard()

	/**
	 * Scenario "A member replays a debate": member Pieter, not chair or
	 * secretary, may play the recording of his meeting.
	 *
	 * @return void
	 */
	public function testAMemberOfTheMeetingMayListen(): void {
		$this->assertNull($this->guard(participant: true)->forTranscriptListener(transcriptId: 't-1'));
	}//end testAMemberOfTheMeetingMayListen()

	/**
	 * Someone who does not take part in the meeting may not.
	 *
	 * @return void
	 */
	public function testAnOutsiderMayNotListen(): void {
		$denied = $this->guard(participant: false)->forTranscriptListener(transcriptId: 't-1');
		$this->assertNotNull($denied);
		$this->assertSame(Http::STATUS_FORBIDDEN, $denied->getStatus());
	}//end testAnOutsiderMayNotListen()
}//end class
