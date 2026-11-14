<?php

/**
 * Unit tests for DecidiqProvider (branch behaviour).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Activity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Activity;

use OCA\Decidiq\Activity\DecidiqProvider;
use OCA\Decidiq\Activity\GovernanceSetting;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Branches of DecidiqProvider::parse() the subject tests do not reach:
 * the wrong-type rejection, the language passed to the factory, rich
 * parameter shape, the no-deep-link path and the event icon.
 *
 * @covers \OCA\Decidiq\Activity\DecidiqProvider
 */
class DecidiqProviderBehaviourTest extends TestCase {
	/**
	 * Language factory mock.
	 *
	 * @var IFactory&MockObject
	 */
	private IFactory&MockObject $factory;

	/**
	 * URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * Subject under test.
	 *
	 * @var DecidiqProvider
	 */
	private DecidiqProvider $provider;

	/**
	 * Build the provider with an IL10N that formats sprintf placeholders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		$this->factory = $this->createMock(IFactory::class);
		$this->factory->method('get')->willReturn($l10n);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('imagePath')->willReturn('/img/app-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example' . $path
		);
		$this->provider = new DecidiqProvider(languageFactory: $this->factory, urlGenerator: $this->urlGenerator);
	}//end setUp()

	/**
	 * Build an event mock for the given app, type, subject and parameters.
	 *
	 * @param string $app     App id
	 * @param string $type    Activity type
	 * @param string $subject Subject key
	 * @param array  $params  Subject parameters
	 *
	 * @return IEvent&MockObject
	 */
	private function event(string $app, string $type, string $subject, array $params): IEvent&MockObject {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn($app);
		$event->method('getType')->willReturn($type);
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($params);
		return $event;
	}//end event()

	/**
	 * A Decidiq event of another type is rejected before any translation.
	 *
	 * @return void
	 */
	public function testDecidiqEventOfOtherTypeIsRejected(): void {
		$event = $this->event('decidiq', 'files', DecidiqProvider::SUBJECT_DECISION_RECORDED, []);
		$this->factory->expects($this->never())->method('get');
		$event->expects($this->never())->method('setParsedSubject');

		$this->expectException(UnknownActivityException::class);
		$this->expectExceptionMessage('Not a Decidiq governance event');
		$this->provider->parse('nl', $event);
	}//end testDecidiqEventOfOtherTypeIsRejected()

	/**
	 * The unknown-subject exception names the subject.
	 *
	 * @return void
	 */
	public function testUnknownSubjectMessageNamesTheSubject(): void {
		$event = $this->event('decidiq', GovernanceSetting::TYPE_GOVERNANCE, 'quorum_lost', []);

		$this->expectException(UnknownActivityException::class);
		$this->expectExceptionMessage('Unknown Decidiq activity subject: quorum_lost');
		$this->provider->parse('en', $event);
	}//end testUnknownSubjectMessageNamesTheSubject()

	/**
	 * The l10n is fetched for the app in the viewer's language.
	 *
	 * @return void
	 */
	public function testTranslatorIsFetchedForAppAndLanguage(): void {
		$factory = $this->createMock(IFactory::class);
		$l10n    = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory->expects($this->once())->method('get')->with('decidiq', 'de')->willReturn($l10n);
		$provider = new DecidiqProvider(languageFactory: $factory, urlGenerator: $this->urlGenerator);

		$event = $this->event('decidiq', GovernanceSetting::TYPE_GOVERNANCE, DecidiqProvider::SUBJECT_VOTE_INITIATED, ['title' => 'Motie 4']);

		$this->assertSame($event, $provider->parse('de', $event));
	}//end testTranslatorIsFetchedForAppAndLanguage()

	/**
	 * With segment and uuid, the deep link is set on the event and in the rich object.
	 *
	 * @return void
	 */
	public function testDeepLinkIsSetAndCarriedInRichObject(): void {
		$event = $this->event(
			'decidiq',
			GovernanceSetting::TYPE_GOVERNANCE,
			DecidiqProvider::SUBJECT_MEETING_TRANSITION,
			['title' => 'Raad 12 nov', 'status' => 'closed', 'segment' => 'meetings', 'uuid' => 'abc-123']
		);
		$link = 'https://cloud.example/apps/decidiq/#/meetings/abc-123';

		$event->expects($this->once())->method('setParsedSubject')->with('Meeting "Raad 12 nov" moved to "closed"');
		$event->expects($this->once())->method('setRichSubject')->with(
			'Meeting {object} moved to "closed"',
			['object' => ['type' => 'highlight', 'id' => 'abc-123', 'name' => 'Raad 12 nov', 'link' => $link]]
		);
		$event->expects($this->once())->method('setLink')->with($link);
		$event->expects($this->once())->method('setIcon')->with('https://cloud.example/img/app-dark.svg');

		$this->provider->parse('en', $event);
	}//end testDeepLinkIsSetAndCarriedInRichObject()

	/**
	 * Without a uuid no link is set and the rich object has empty id and link.
	 *
	 * @return void
	 */
	public function testMissingUuidSetsNoLink(): void {
		$event = $this->event(
			'decidiq',
			GovernanceSetting::TYPE_GOVERNANCE,
			DecidiqProvider::SUBJECT_RESOLUTION_ADOPTED,
			['title' => 'Besluit A', 'segment' => 'resolutions']
		);

		$event->expects($this->never())->method('setLink');
		$event->expects($this->once())->method('setRichSubject')->with(
			'Resolution {object} was adopted',
			['object' => ['type' => 'highlight', 'id' => '', 'name' => 'Besluit A', 'link' => '']]
		);
		$event->expects($this->once())->method('setIcon');

		$this->provider->parse('en', $event);
	}//end testMissingUuidSetsNoLink()

	/**
	 * Without a segment no link is set either, even with a uuid.
	 *
	 * @return void
	 */
	public function testMissingSegmentSetsNoLink(): void {
		$event = $this->event(
			'decidiq',
			GovernanceSetting::TYPE_GOVERNANCE,
			DecidiqProvider::SUBJECT_DECISION_PUBLISHED,
			['uuid' => 'u-9']
		);

		$event->expects($this->never())->method('setLink');
		$event->expects($this->once())->method('setParsedSubject')->with('Decision "" was published');
		$event->expects($this->once())->method('setRichSubject')->with(
			'Decision {object} was published',
			['object' => ['type' => 'highlight', 'id' => 'u-9', 'name' => '', 'link' => '']]
		);

		$this->provider->parse('en', $event);
	}//end testMissingSegmentSetsNoLink()
}//end class
