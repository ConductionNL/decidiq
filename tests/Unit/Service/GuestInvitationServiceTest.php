<?php

/**
 * Tests for GuestInvitationService (meeting-ad-hoc-with-guests, pla-20).
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
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\GuestInvitationRefusedException;
use OCA\Decidiq\Service\GuestInvitationService;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The organiser of an ad hoc meeting invites a guest from outside by email.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
class GuestInvitationServiceTest extends TestCase {

	private const MEETING = '0f5b8a8e-6a3c-4c1b-9d3e-1a2b3c4d5e61';
	private const LINK = 'https://cloud.example.org/s/Ab12Cd34';

	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface $objects;

	/** @var FileService&MockObject */
	private FileService $files;

	/** @var IMailer&MockObject */
	private IMailer $mailer;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var array<int, array{schema: string, object: array<string, mixed>}> */
	private array $saved = [];

	/** @var array<int, IMessage> */
	private array $sent = [];

	/** @var array<string, mixed> */
	private array $meeting = [];

	protected function setUp(): void {
		$this->meeting = [
			'title' => 'Kick-off windpark',
			'scheduledDate' => '2026-11-03T14:00:00+00:00',
			'location' => 'Stadhuis, kamer 2',
			'@self' => ['id' => self::MEETING, 'owner' => 'anna'],
		];

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willReturnCallback(fn (): ?ObjectEntity => $this->entity(data: $this->meeting, uuid: self::MEETING));
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null): ObjectEntity {
				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];
				return $this->entity(data: $object, uuid: 'saved-' . count($this->saved));
			}
		);
		$this->objects->method('findAll')->willReturn([
			$this->entity(data: ['title' => 'Afspraken en vervolg', 'orderNumber' => 3], uuid: 'a3'),
			$this->entity(data: ['title' => 'Opening en kennismaking', 'orderNumber' => 1], uuid: 'a1'),
			$this->entity(data: ['title' => 'Stand van zaken', 'orderNumber' => 2], uuid: 'a2'),
		]);

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/decidiq/files/Open Registers/Decidiq/Kick-off windpark');
		$this->files = $this->createMock(FileService::class);
		$this->files->method('getObjectFolder')->willReturn($folder);
		$this->files->method('createShareLink')->willReturn(self::LINK);

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createMessage')->willReturnCallback(fn (): IMessage => $this->createMock(IMessage::class));
		$this->mailer->method('send')->willReturnCallback(
			function (IMessage $message): array {
				$this->sent[] = $message;
				return [];
			}
		);

		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
	}//end setUp()

	/**
	 * An ObjectEntity carrying the data and uuid.
	 *
	 * @param array<string, mixed> $data The data
	 * @param string               $uuid The uuid
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()

	/**
	 * The service under test, with a l10n that formats like English.
	 *
	 * @return GuestInvitationService
	 */
	private function service(): GuestInvitationService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('l')->willReturn('3 November 2026 14:00');
		return new GuestInvitationService(
			objectService: $this->objects,
			fileService: $this->files,
			mailer: $this->mailer,
			groupManager: $this->groups,
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end service()

	public function testTheOrganiserInvitesAnOutsideAdvisor(): void {
		$result = $this->service()->invite(meetingId: self::MEETING, name: 'Advisor', email: 'advisor@example.org', userId: 'anna');

		self::assertCount(2, $this->saved);
		self::assertSame('participant', $this->saved[0]['schema']);
		self::assertSame(['displayName' => 'Advisor', 'role' => 'guest', 'email' => 'advisor@example.org'], $this->saved[0]['object']);
		self::assertSame([], MergedRegisterSchema::violations(slug: 'participant', payload: $this->saved[0]['object']));
		self::assertSame('meeting-attendance', $this->saved[1]['schema']);
		self::assertSame(['meeting' => self::MEETING, 'participant' => 'saved-1'], $this->saved[1]['object']);
		self::assertSame([], MergedRegisterSchema::violations(slug: 'meeting-attendance', payload: $this->saved[1]['object']));
		self::assertCount(1, $this->sent);
		self::assertSame(['participant' => 'saved-1', 'attendance' => 'saved-2', 'mailed' => true], $result);
	}//end testTheOrganiserInvitesAnOutsideAdvisor()

	public function testThePapersAreSharedReadOnlyAsAPublicLink(): void {
		$this->files->expects(self::once())->method('createShareLink')
			->with('/decidiq/files/Open Registers/Decidiq/Kick-off windpark', 3, 1)
			->willReturn(self::LINK);
		$this->service()->invite(meetingId: self::MEETING, name: 'Advisor', email: 'advisor@example.org', userId: 'anna');
	}//end testThePapersAreSharedReadOnlyAsAPublicLink()

	public function testTheInvitationCarriesTheDateTheAgendaAndTheLink(): void {
		$body = $this->service()->invitationBody(
			meeting: $this->meeting,
			agenda: ['Opening en kennismaking', 'Stand van zaken'],
			name: 'Advisor',
			link: self::LINK
		);
		self::assertStringContainsString('You are invited to the meeting Kick-off windpark.', $body);
		self::assertStringContainsString('When: 3 November 2026 14:00', $body);
		self::assertStringContainsString('Where: Stadhuis, kamer 2', $body);
		self::assertStringContainsString("1. Opening en kennismaking\n2. Stand van zaken", $body);
		self::assertStringContainsString(self::LINK, $body);
	}//end testTheInvitationCarriesTheDateTheAgendaAndTheLink()

	public function testSomeoneWhoDidNotOrganiseTheMeetingIsRefused(): void {
		try {
			$this->service()->invite(meetingId: self::MEETING, name: 'Advisor', email: 'advisor@example.org', userId: 'pieter');
			self::fail('Pieter was allowed to invite');
		} catch (GuestInvitationRefusedException $e) {
			self::assertSame(403, $e->getStatus());
		}

		self::assertSame([], $this->saved);
		self::assertSame([], $this->sent);
	}//end testSomeoneWhoDidNotOrganiseTheMeetingIsRefused()

	public function testADecidiqAdministratorMayInvite(): void {
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => $group === 'decidiq-administrators');
		$this->service()->invite(meetingId: self::MEETING, name: '', email: 'advisor@example.org', userId: 'griffie');
		self::assertSame('advisor@example.org', $this->saved[0]['object']['displayName']);
	}//end testADecidiqAdministratorMayInvite()

	public function testAMeetingOfAGoverningBodyTakesNoGuests(): void {
		$this->meeting['governanceBody'] = '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85';
		$this->expectException(GuestInvitationRefusedException::class);
		$this->expectExceptionMessage('without a governing body');
		$this->service()->invite(meetingId: self::MEETING, name: 'Advisor', email: 'advisor@example.org', userId: 'anna');
	}//end testAMeetingOfAGoverningBodyTakesNoGuests()

	public function testAnInvalidEmailIsRefusedBeforeAnythingIsWritten(): void {
		try {
			$this->service()->invite(meetingId: self::MEETING, name: 'Advisor', email: 'not-an-address', userId: 'anna');
			self::fail('An invalid address was accepted');
		} catch (GuestInvitationRefusedException $e) {
			self::assertSame(422, $e->getStatus());
		}

		self::assertSame([], $this->saved);
	}//end testAnInvalidEmailIsRefusedBeforeAnythingIsWritten()

	public function testAFailedShareIsRefusedAndNothingIsWritten(): void {
		$this->files = $this->createMock(FileService::class);
		$this->files->method('getObjectFolder')->willReturn(null);
		try {
			$this->service()->invite(meetingId: self::MEETING, name: 'Advisor', email: 'advisor@example.org', userId: 'anna');
			self::fail('An invitation without a link was sent');
		} catch (GuestInvitationRefusedException $e) {
			self::assertSame(422, $e->getStatus());
		}

		self::assertSame([], $this->saved);
		self::assertSame([], $this->sent);
	}//end testAFailedShareIsRefusedAndNothingIsWritten()

	public function testAShareErrorMessageIsNotMailedAsALink(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/x');
		$this->files = $this->createMock(FileService::class);
		$this->files->method('getObjectFolder')->willReturn($folder);
		$this->files->method('createShareLink')->willReturn('File not found at x');
		$this->expectException(GuestInvitationRefusedException::class);
		$this->service()->invite(meetingId: self::MEETING, name: 'Advisor', email: 'advisor@example.org', userId: 'anna');
	}//end testAShareErrorMessageIsNotMailedAsALink()

	public function testAMeetingTheCallerCannotSeeAnswers404(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willReturn(null);
		try {
			$this->service()->requireOrganiserOf(meetingId: self::MEETING, userId: 'anna');
			self::fail('An unseen meeting was found');
		} catch (GuestInvitationRefusedException $e) {
			self::assertSame(404, $e->getStatus());
		}
	}//end testAMeetingTheCallerCannotSeeAnswers404()
}//end class
