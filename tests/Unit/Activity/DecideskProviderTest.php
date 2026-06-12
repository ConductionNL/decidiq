<?php

/**
 * Unit tests for DecideskProvider (Activity).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Activity
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

namespace OCA\Decidesk\Tests\Unit\Activity;

use OCA\Decidesk\Activity\DecideskProvider;
use OCA\Decidesk\Activity\GovernanceFilter;
use OCA\Decidesk\Activity\GovernanceSetting;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests subject parsing, deep links, and foreign-event rejection.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class DecideskProviderTest extends TestCase
{

    /**
     * Provider under test.
     *
     * @var DecideskProvider
     */
    private DecideskProvider $provider;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $params=[]): string {
                return vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $text), $params);
            }
        );

        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l10n);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/img/decidesk/app-dark.svg');
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://cloud.example'.$path
        );

        $this->provider = new DecideskProvider(
            languageFactory: $factory,
            urlGenerator: $urlGenerator,
        );

    }//end setUp()

    /**
     * Build an IEvent mock for a decidesk governance subject.
     *
     * @param string               $subject Subject id
     * @param array<string,mixed>  $params  Subject parameters
     *
     * @return IEvent&MockObject
     */
    private function event(string $subject, array $params): IEvent&MockObject
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn('decidesk');
        $event->method('getType')->willReturn(GovernanceSetting::TYPE_GOVERNANCE);
        $event->method('getSubject')->willReturn($subject);
        $event->method('getSubjectParameters')->willReturn($params);
        return $event;

    }//end event()

    /**
     * Foreign apps/types are rejected with UnknownActivityException.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testForeignEventThrows(): void
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn('files');
        $event->method('getType')->willReturn('file_changed');

        $this->expectException(UnknownActivityException::class);
        $this->provider->parse('en', $event);

    }//end testForeignEventThrows()

    /**
     * Unknown decidesk subjects are rejected too.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testUnknownSubjectThrows(): void
    {
        $event = $this->event(subject: 'something_else', params: []);

        $this->expectException(UnknownActivityException::class);
        $this->provider->parse('en', $event);

    }//end testUnknownSubjectThrows()

    /**
     * Each known subject parses to a sensible plain subject + deep link.
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testKnownSubjectsParse(): void
    {
        $cases = [
            [DecideskProvider::SUBJECT_DECISION_RECORDED, 'Decision "Budget" was recorded'],
            [DecideskProvider::SUBJECT_DECISION_PUBLISHED, 'Decision "Budget" was published'],
            [DecideskProvider::SUBJECT_MEETING_TRANSITION, 'Meeting "Budget" moved to "opened"'],
            [DecideskProvider::SUBJECT_VOTE_INITIATED, 'Voting opened on "Budget"'],
            [DecideskProvider::SUBJECT_RESOLUTION_ADOPTED, 'Resolution "Budget" was adopted'],
        ];

        foreach ($cases as [$subject, $expectedPlain]) {
            $parsedSubject = null;
            $link          = null;

            $event = $this->event(
                subject: $subject,
                params: [
                    'title'   => 'Budget',
                    'status'  => 'opened',
                    'uuid'    => 'uuid-1',
                    'segment' => 'decisions',
                ]
            );
            $event->method('setParsedSubject')->willReturnCallback(
                static function (string $plain) use (&$parsedSubject, $event) {
                    $parsedSubject = $plain;
                    return $event;
                }
            );
            $event->method('setRichSubject')->willReturnSelf();
            $event->method('setLink')->willReturnCallback(
                static function (string $url) use (&$link, $event) {
                    $link = $url;
                    return $event;
                }
            );
            $event->method('setIcon')->willReturnSelf();

            $this->provider->parse('en', $event);

            self::assertSame(expected: $expectedPlain, actual: $parsedSubject, message: "plain subject for $subject");
            self::assertSame(
                expected: 'https://cloud.example/apps/decidesk/#/decisions/uuid-1',
                actual: $link,
                message: "deep link for $subject"
            );
        }//end foreach

    }//end testKnownSubjectsParse()

    /**
     * Setting + filter identity contracts (type, identifier, allowed apps).
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    public function testSettingAndFilterIdentity(): void
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $setting = new GovernanceSetting(l10n: $l10n);
        self::assertSame(expected: GovernanceSetting::TYPE_GOVERNANCE, actual: $setting->getIdentifier());
        self::assertTrue(condition: $setting->isDefaultEnabledNotification());

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/img/decidesk/app-dark.svg');
        $urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

        $filter = new GovernanceFilter(l10n: $l10n, urlGenerator: $urlGenerator);
        self::assertSame(expected: 'decidesk', actual: $filter->getIdentifier());
        self::assertSame(expected: ['decidesk'], actual: $filter->allowedApps());
        self::assertSame(
            expected: [GovernanceSetting::TYPE_GOVERNANCE],
            actual: $filter->filterTypes(['files', GovernanceSetting::TYPE_GOVERNANCE])
        );

    }//end testSettingAndFilterIdentity()
}//end class
