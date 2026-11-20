<?php

/**
 * An emergency convocation records its shortened notice, or is not sent at all.
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
use OCA\Decidiq\Service\AgendaNotices;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\PublicationEventRecorder;
use OCA\Decidiq\Service\ShortenedNoticeService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The deviation is saved with the convocation; a refused one saves and sends nothing.
 *
 * @covers \OCA\Decidiq\Service\AgendaService
 * @uses   \OCA\Decidiq\Service\AgendaNotices
 * @uses   \OCA\Decidiq\Service\AgendaRules
 * @uses   \OCA\Decidiq\Service\AgendaInvitation
 * @uses   \OCA\Decidiq\Service\ShortenedNoticeService
 */
class AgendaShortenedNoticeTest extends TestCase {

	/**
	 * Meetings saved.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Members notified.
	 *
	 * @var list<string>
	 */
	private array $notified = [];

	/**
	 * The agenda service over an emergency board meeting 72 hours out (floor 48 hours).
	 *
	 * @param array<string, mixed> $meeting Overrides on the meeting.
	 *
	 * @return AgendaService
	 */
	private function service(array $meeting = []): AgendaService {
		$meetingData = array_merge(
			['title' => 'Spoedvergadering', 'meetingType' => 'extraordinary', 'governanceBody' => 'body-board', 'scheduledDate' => '2026-06-04T10:00:00+00:00', 'lifecycle' => 'scheduled'],
			$meeting
		);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(fn (): ObjectEntity => $this->entity($meetingData));
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => (($config['filters']['schema'] ?? '') === 'agenda-item' ? [$this->entity(['id' => 'item-1', 'title' => 'Opvang', 'orderNumber' => 1])] : [])
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return $this->entity($object);
			}
		);
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveMeetingParticipants')->willReturn([['nextcloudUserId' => 'anna', 'leftAt' => null], ['nextcloudUserId' => 'bram', 'leftAt' => null]]);
		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->method('dispatch')->willReturnCallback(
			function (string $personId): int {
				$this->notified[] = $personId;
				return 1;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array|string $params = []): string => vsprintf($text, (array)$params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$templates = $this->createMock(ProcessTemplateServiceInterface::class);
		$templates->method('resolveUrgencyPolicyForBody')->willReturn(['minimumNoticeFloorHours' => 48]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-06-01T10:00:00+00:00'));
		$logger = new NullLogger();

		return new AgendaService(
			objectService: $objectService,
			notices: new AgendaNotices(preferences: $preferences, l10nFactory: $factory, logger: $logger),
			logger: $logger,
			participantResolver: $resolver,
			eventRecorder: $this->createMock(PublicationEventRecorder::class),
			shortenedNotice: new ShortenedNoticeService(objectService: $objectService, templates: $templates, timeFactory: $time),
		);
	}//end service()

	/**
	 * An entity double over a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * Confirmed shortened notice: members invited, deviation saved with the convocation.
	 *
	 * @return void
	 */
	public function testTheDeviationIsRecordedWithTheConvocation(): void {
		$this->service()->publishAgenda('meet-1', 'Spoedbesluit noodopvang');

		$this->assertSame(['anna', 'bram'], $this->notified);
		$this->assertCount(1, $this->saved);
		$this->assertTrue($this->saved[0]['shortenedNotice']);
		$this->assertSame(72, $this->saved[0]['actualNoticeHours']);
		$this->assertSame('Spoedbesluit noodopvang', $this->saved[0]['noticeDeviationReason']);
		$this->assertNotEmpty($this->saved[0]['convocationSentAt']);
	}//end testTheDeviationIsRecordedWithTheConvocation()

	/**
	 * Below the floor: refused, nothing saved and nobody notified.
	 *
	 * @return void
	 */
	public function testBelowTheFloorNothingIsSentOrSaved(): void {
		try {
			$this->service(['scheduledDate' => '2026-06-02T10:00:00+00:00'])->publishAgenda('meet-1', 'Spoed');
			$this->fail('expected the floor to refuse the send');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('The convocation was not sent: the notice may not be shorter than 48 hours.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->notified);
	}//end testBelowTheFloorNothingIsSentOrSaved()

	/**
	 * A regular convocation records no deviation fields.
	 *
	 * @return void
	 */
	public function testARegularConvocationRecordsNoDeviation(): void {
		$this->service(['meetingType' => 'regular'])->publishAgenda('meet-1');

		$this->assertArrayNotHasKey('shortenedNotice', $this->saved[0]);
		$this->assertArrayNotHasKey('actualNoticeHours', $this->saved[0]);
	}//end testARegularConvocationRecordsNoDeviation()
}//end class
