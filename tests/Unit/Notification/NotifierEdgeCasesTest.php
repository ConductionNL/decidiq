<?php

/**
 * Edge cases of the Decidiq notifier: fallback names, missing parameters and links.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Notification
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Notification;

use OCA\Decidiq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the notifier's less common paths.
 *
 * @covers \OCA\Decidiq\Notification\Notifier
 *
 * @spec openspec/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
 */
class NotifierEdgeCasesTest extends TestCase {
	/**
	 * Values handed to the notification's setters, by setter name.
	 *
	 * @var array<string, string>
	 */
	private array $parsed = [];

	private IFactory&MockObject $factory;

	private function notifier(): Notifier {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $params = []): string => vsprintf($text, (array)$params)
		);
		$this->factory = $this->createMock(IFactory::class);
		$this->factory->method('get')->willReturn($l10n);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->with('decidiq.dashboard.page')->willReturn('https://cloud.example/apps/decidiq/');
		$urls->method('imagePath')->with('decidiq', 'app-dark.svg')->willReturn('/apps/decidiq/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example' . $url);

		return new Notifier(l10nFactory: $this->factory, urlGenerator: $urls);
	}

	private function notification(string $subject, array $parameters = [], string $objectId = 'm-1'): INotification {
		$this->parsed = [];
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('decidiq');
		$n->method('getSubject')->willReturn($subject);
		$n->method('getSubjectParameters')->willReturn($parameters);
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
	}

	private function prepare(string $subject, array $parameters = [], string $objectId = 'm-1'): INotification {
		$notification = $this->notification($subject, $parameters, $objectId);

		return $this->notifier()->prepare($notification, 'nl');
	}

	public function testIdAndName(): void {
		$notifier = $this->notifier();

		$this->assertSame('decidiq', $notifier->getID());
		$this->assertSame('Decidiq', $notifier->getName());
	}

	public function testPrepareReturnsTheSameNotificationInTheRequestedLanguage(): void {
		$notifier     = $this->notifier();
		$notification = $this->notification('agenda_changed');
		$this->factory->expects($this->once())->method('get')->with('decidiq', 'nl');

		$this->assertSame($notification, $notifier->prepare($notification, 'nl'));
	}

	public function testAMeetingWithoutTitleIsCalledThisMeeting(): void {
		$this->prepare('agenda_published');

		$this->assertSame('The agenda of this meeting was published', $this->parsed['setParsedSubject']);
	}

	public function testAMotionWithoutTitleIsCalledThisMotion(): void {
		$this->prepare('co_sign_request', [], 'mo-1');

		$this->assertSame('You are asked to co-sign the motion this motion', $this->parsed['setParsedSubject']);
		$this->assertSame('https://cloud.example/apps/decidiq/motions/mo-1', $this->parsed['setLink']);
	}

	public function testTheMeetingTitleWinsOverTheMotionTitleAndTitle(): void {
		$this->prepare('motion_forwarded_approval', ['meetingTitle' => 'A', 'motionTitle' => 'B', 'title' => 'C']);
		$this->assertSame('The motion A was forwarded to you for approval', $this->parsed['setParsedSubject']);

		$this->prepare('motion_forwarded_approval', ['motionTitle' => 'B', 'title' => 'C']);
		$this->assertSame('The motion B was forwarded to you for approval', $this->parsed['setParsedSubject']);

		$this->prepare('minutes_available', ['title' => 'C']);
		$this->assertSame('The minutes of C are available', $this->parsed['setParsedSubject']);
	}

	public function testTheObjectIdIsUrlEncodedInTheLink(): void {
		$this->prepare('minutes_available', ['title' => 'x'], 'a b/c');

		$this->assertSame('https://cloud.example/apps/decidiq/minutes/a%20b%2Fc', $this->parsed['setLink']);
	}

	public function testWithoutObjectIdTheLinkIsTheAppRoot(): void {
		$this->prepare('meeting_scheduled', ['meetingTitle' => 'Raad'], '');

		$this->assertSame('https://cloud.example/apps/decidiq/', $this->parsed['setLink']);
	}

	public function testASubjectWithoutPageLinksToTheAppRoot(): void {
		$this->prepare('proxy_granted', [], 'round-1');

		$this->assertSame('You received a proxy to vote for another member', $this->parsed['setParsedSubject']);
		$this->assertSame('https://cloud.example/apps/decidiq/', $this->parsed['setLink']);
		$this->assertArrayNotHasKey('setParsedMessage', $this->parsed);
	}

	public function testADetailWithoutItsValueSetsNoMessage(): void {
		$this->prepare('meeting_reminder', ['meetingTitle' => 'Raad', 'startsAt' => '']);

		$this->assertSame('The meeting Raad is coming up', $this->parsed['setParsedSubject']);
		$this->assertArrayNotHasKey('setParsedMessage', $this->parsed);
	}

	public function testAMessageWithoutTitleOrTextFallsBackToTheAppName(): void {
		$this->prepare('decidiq_message', ['link' => 'motions/x']);

		$this->assertSame('Decidiq', $this->parsed['setParsedSubject']);
		$this->assertArrayNotHasKey('setParsedMessage', $this->parsed);
		$this->assertSame('https://cloud.example/apps/decidiq/motions/x', $this->parsed['setLink']);
	}

	public function testAMessageWithoutLinkLinksToTheAppRoot(): void {
		$this->prepare('decidiq_message', ['title' => 'T']);

		$this->assertSame('https://cloud.example/apps/decidiq/', $this->parsed['setLink']);
	}

	public function testAReadyBundleWithoutFileLinksToTheExportsFolder(): void {
		$this->prepare('export_bundle_ready', ['title' => 'Motions.pdf']);

		$this->assertSame('Motions.pdf is ready in your Decidiq exports folder', $this->parsed['setParsedSubject']);
		$this->assertSame('https://cloud.example/index.php/apps/files/?dir=%2FDecidiq%20exports', $this->parsed['setLink']);
	}

	public function testAFailedBundleLinksToTheAppRoot(): void {
		$this->prepare('export_bundle_failed', ['title' => 'Motions.pdf', 'fileId' => '9']);

		$this->assertSame('https://cloud.example/apps/decidiq/', $this->parsed['setLink']);
	}

	public function testTheBundleFileIdIsUrlEncoded(): void {
		$this->prepare('export_bundle_ready', ['title' => 'x', 'fileId' => '7/8']);

		$this->assertSame('https://cloud.example/index.php/f/7%2F8', $this->parsed['setLink']);
	}
}
