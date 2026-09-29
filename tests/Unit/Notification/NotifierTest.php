<?php

/**
 * Tests for decidiq's notifier (change agenda-change-notices-reach-members).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Notification
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

namespace OCA\Decidiq\Tests\Unit\Notification;

use OCA\Decidiq\AppInfo\Registrar\PlatformIntegrationRegistrar;
use OCA\Decidiq\Notification\Notifier;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * Every notice decidiq sends can be shown; other apps' notices are declined.
 *
 * The notification double implements the real OCP INotification interface
 * (createMock of the interface adds no method it lacks) and records what the
 * notifier parses into it.
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
 */
class NotifierTest extends TestCase {

	/**
	 * What the notifier wrote into the notification.
	 *
	 * @var array<string, string>
	 */
	private array $parsed = [];

	/**
	 * Build the notifier with an English l10n that fills %s placeholders.
	 *
	 * @return Notifier
	 */
	private function notifier(): Notifier {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, array|string $params = []): string => vsprintf($text, (array)$params)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/decidiq/');
		$urls->method('imagePath')->willReturn('/apps/decidiq/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $url): string => 'https://cloud.example' . $url);

		return new Notifier(l10nFactory: $factory, urlGenerator: $urls);

	}//end notifier()

	/**
	 * A notification double for the given app, subject and object.
	 *
	 * @param string               $app        App id
	 * @param string               $subject    Subject key
	 * @param array<string, mixed> $parameters Subject parameters
	 * @param string               $objectType Object type
	 * @param string               $objectId   Object id
	 *
	 * @return INotification
	 */
	private function notification(string $app, string $subject, array $parameters=[], string $objectType='meeting', string $objectId='m-1'): INotification {
		$this->parsed = [];
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn($app);
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($parameters);
		$n->method('getObjectType')->willReturn($objectType);
		$n->method('getObjectId')->willReturn($objectId);
		foreach (['setParsedSubject', 'setParsedMessage', 'setLink', 'setIcon'] as $setter) {
			$n->method($setter)->willReturnCallback(
				function (string $value) use ($n, $setter): INotification {
					$this->parsed[$setter] = $value;
					return $n;
				}
			);
		}

		return $n;

	}//end notification()

	/**
	 * An agenda change reads as a sentence naming the meeting and links to it.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 *
	 * @return void
	 */
	public function testAgendaChangedNamesTheMeetingAndLinksToIt(): void {
		$this->notifier()->prepare(
			$this->notification(app: 'decidiq', subject: 'agenda_changed', parameters: ['meetingId' => 'm-1', 'meetingTitle' => 'Raadsvergadering 14 oktober']),
			'en'
		);

		self::assertSame('The agenda of Raadsvergadering 14 oktober changed', $this->parsed['setParsedSubject']);
		self::assertSame('https://cloud.example/apps/decidiq/meetings/m-1', $this->parsed['setLink']);
		self::assertSame('https://cloud.example/apps/decidiq/img/app-dark.svg', $this->parsed['setIcon']);

	}//end testAgendaChangedNamesTheMeetingAndLinksToIt()

	/**
	 * Every subject decidiq sends is prepared, not refused.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 *
	 * @return void
	 */
	public function testEverySubjectDecidiqSendsIsPrepared(): void {
		$sent = [
			['agenda_published', 'meeting'],
			['agenda_revised', 'meeting'],
			['agenda_revision_started', 'meeting'],
			['agenda_changed', 'meeting'],
			['co_sign_request', 'motion'],
			['motion_forwarded_approval', 'motion'],
			['proxy_granted', 'voting-round'],
			['email_vote_confirmed', 'voting-round'],
			['email_vote_abandoned', 'voting-round'],
			['email_vote_reprompt', 'voting-round'],
		];
		foreach ($sent as [$subject, $type]) {
			$this->notifier()->prepare($this->notification(app: 'decidiq', subject: $subject, objectType: $type), 'en');
			self::assertNotSame('', ($this->parsed['setParsedSubject'] ?? ''), $subject);
			self::assertStringNotContainsString('_', $this->parsed['setParsedSubject'], $subject . ' must not render as its key');
			self::assertStringStartsWith('https://cloud.example/apps/decidiq/', $this->parsed['setLink'], $subject);
		}

	}//end testEverySubjectDecidiqSendsIsPrepared()

	/**
	 * The generic message carries its own title, text and link.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-002-preference-aware-in-app-notices-are-sent-as-decidiq
	 *
	 * @return void
	 */
	public function testGenericMessageRendersItsParameters(): void {
		$this->notifier()->prepare(
			$this->notification(app: 'decidiq', subject: 'decidiq_message', parameters: ['title' => 'Voting opened', 'message' => 'Vote now on Groen dak.', 'link' => '/motions/x-1'], objectType: 'decidiq', objectId: 'x-1'),
			'en'
		);

		self::assertSame('Voting opened', $this->parsed['setParsedSubject']);
		self::assertSame('Vote now on Groen dak.', $this->parsed['setParsedMessage']);
		self::assertSame('https://cloud.example/apps/decidiq/motions/x-1', $this->parsed['setLink']);

	}//end testGenericMessageRendersItsParameters()

	/**
	 * Another app's notice is declined.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 *
	 * @return void
	 */
	public function testAnotherAppIsDeclined(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($this->notification(app: 'openregister', subject: 'agenda_changed'), 'en');

	}//end testAnotherAppIsDeclined()

	/**
	 * An unknown decidiq subject is declined too.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 *
	 * @return void
	 */
	public function testUnknownSubjectIsDeclined(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($this->notification(app: 'decidiq', subject: 'no_such_subject'), 'en');

	}//end testUnknownSubjectIsDeclined()

	/**
	 * The notifier is registered, or Nextcloud never asks it (a notifier with
	 * tests and no registration renders nothing).
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 *
	 * @return void
	 */
	public function testTheRegistrarRegistersTheNotifier(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerNotifierService')->with(Notifier::class);

		(new PlatformIntegrationRegistrar())->register($context);

	}//end testTheRegistrarRegistersTheNotifier()

	/**
	 * The meeting notices name the meeting, link to it and say when.
	 *
	 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-mrd-001-meeting-notices-follow-the-member-switches
	 *
	 * @return void
	 */
	public function testMeetingNoticesNameTheMeetingAndWhen(): void {
		$cases = [
			['meeting_scheduled', ['meetingTitle' => 'Raad', 'startsAt' => '2026-10-08 19:30'], 'The meeting Raad was scheduled', 'It starts on 2026-10-08 19:30.'],
			['meeting_reminder', ['meetingTitle' => 'Raad', 'startsAt' => '2026-10-08 19:30'], 'The meeting Raad is coming up', 'It starts on 2026-10-08 19:30.'],
			['submission_deadline', ['meetingTitle' => 'Raad', 'deadline' => '2026-10-02 12:00'], 'The submission deadline of Raad is coming up', 'Motions and amendments can be submitted until 2026-10-02 12:00.'],
		];
		foreach ($cases as [$subject, $params, $sentence, $message]) {
			$this->notifier()->prepare($this->notification(app: 'decidiq', subject: $subject, parameters: $params), 'en');
			self::assertSame($sentence, $this->parsed['setParsedSubject'], $subject);
			self::assertSame($message, ($this->parsed['setParsedMessage'] ?? ''), $subject);
			self::assertSame('https://cloud.example/apps/decidiq/meetings/m-1', $this->parsed['setLink'], $subject);
		}

	}//end testMeetingNoticesNameTheMeetingAndWhen()
}//end class
