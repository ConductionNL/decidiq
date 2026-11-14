<?php

/**
 * The shortened-notice deviation of an emergency convocation.
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
 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Service\ShortenedNoticeService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Extraordinary only, a configured body, the floor, and the reason.
 *
 * @covers \OCA\Decidiq\Service\ShortenedNoticeService
 */
class ShortenedNoticeServiceTest extends TestCase {

	/**
	 * The service at 2026-06-01 10:00 UTC, for a body whose floor is 48 hours.
	 *
	 * @param array<string, mixed>|null $policy    The body's urgency policy.
	 * @param list<array>               $decisions The urgent decisions on the meeting.
	 * @param array|null                $asked     Receives the decision query.
	 *
	 * @return ShortenedNoticeService
	 */
	private function service(?array $policy = ['minimumNoticeFloorHours' => 48], array $decisions = [], ?array &$asked = null): ShortenedNoticeService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($decisions, &$asked): array {
				$asked = $config;
				return $decisions;
			}
		);
		$templates = $this->createMock(ProcessTemplateServiceInterface::class);
		$templates->method('resolveUrgencyPolicyForBody')->willReturnCallback(static fn (?string $body): ?array => $body === 'body-board' ? $policy : null);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-01T10:00:00+00:00'));

		return new ShortenedNoticeService(objectService: $objects, templates: $templates, timeFactory: $time);
	}//end service()

	/**
	 * An extraordinary meeting.
	 *
	 * @param string $start Its start.
	 *
	 * @return array<string, mixed>
	 */
	private static function meeting(string $start): array {
		return ['meetingType' => 'extraordinary', 'governanceBody' => 'body-board', 'scheduledDate' => $start];
	}//end meeting()

	/**
	 * 72 hours out with a 48-hour floor: the deviation is recorded with the reason given.
	 *
	 * @return void
	 */
	public function testA72HourNoticeAboveTheFloorIsRecorded(): void {
		$deviation = $this->service()->deviationFor(meetingId: 'meet-1', meeting: self::meeting('2026-06-04T12:00:00+02:00'), reason: ' Spoedvergadering ');

		$this->assertSame(['shortenedNotice' => true, 'actualNoticeHours' => 72, 'noticeDeviationReason' => 'Spoedvergadering'], $deviation);
	}//end testA72HourNoticeAboveTheFloorIsRecorded()

	/**
	 * 24 hours out with a 48-hour floor: refused naming the floor.
	 *
	 * @return void
	 */
	public function testANoticeBelowTheFloorIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The convocation was not sent: the notice may not be shorter than 48 hours.');

		$this->service()->deviationFor(meetingId: 'meet-1', meeting: self::meeting('2026-06-02T10:00:00+00:00'), reason: 'x');
	}//end testANoticeBelowTheFloorIsRefused()

	/**
	 * Exactly at the floor is allowed; a meeting without a start is refused.
	 *
	 * @return void
	 */
	public function testTheFloorIsInclusiveAndAStartIsNeeded(): void {
		$this->assertSame(48, $this->service()->deviationFor(meetingId: 'meet-1', meeting: self::meeting('2026-06-03T10:00:00+00:00'), reason: 'x')['actualNoticeHours']);

		$this->expectException(InvalidArgumentException::class);
		$this->service()->deviationFor(meetingId: 'meet-1', meeting: ['meetingType' => 'extraordinary', 'governanceBody' => 'body-board'], reason: 'x');
	}//end testTheFloorIsInclusiveAndAStartIsNeeded()

	/**
	 * A regular meeting cannot record a deviation.
	 *
	 * @return void
	 */
	public function testARegularMeetingCannotDeviate(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only an extraordinary meeting can be convened at shortened notice.');

		$this->service()->deviationFor(meetingId: 'meet-1', meeting: ['meetingType' => 'regular', 'governanceBody' => 'body-board', 'scheduledDate' => '2026-06-10T10:00:00+00:00'], reason: 'x');
	}//end testARegularMeetingCannotDeviate()

	/**
	 * A body without an urgency policy cannot shorten its notice.
	 *
	 * @return void
	 */
	public function testABodyWithoutAPolicyCannotDeviate(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The urgent procedure is not configured for this body, so its notice cannot be shortened.');

		$this->service(policy: null)->deviationFor(meetingId: 'meet-1', meeting: self::meeting('2026-06-10T10:00:00+00:00'), reason: 'x');
	}//end testABodyWithoutAPolicyCannotDeviate()

	/**
	 * Without a reason the urgency reason of the urgent decision on the meeting is used.
	 *
	 * @return void
	 */
	public function testTheReasonDefaultsToTheUrgencyReason(): void {
		$service = $this->service(decisions: [['urgencyReason' => ''], ['urgencyReason' => 'Spoedbesluit noodopvang']], asked: $asked);

		$deviation = $service->deviationFor(meetingId: 'meet-1', meeting: self::meeting('2026-06-04T10:00:00+00:00'), reason: '');

		$this->assertSame('Spoedbesluit noodopvang', $deviation['noticeDeviationReason']);
		$this->assertSame(['filters' => ['register' => 'decidiq', 'schema' => 'decision', 'meeting' => 'meet-1', 'isUrgent' => true]], $asked);
	}//end testTheReasonDefaultsToTheUrgencyReason()

	/**
	 * Without any reason the deviation is refused.
	 *
	 * @return void
	 */
	public function testWithoutAnyReasonTheDeviationIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('noticeDeviationReason is required for a shortened notice.');

		$this->service()->deviationFor(meetingId: 'meet-1', meeting: self::meeting('2026-06-04T10:00:00+00:00'), reason: '');
	}//end testWithoutAnyReasonTheDeviationIsRefused()
}//end class
