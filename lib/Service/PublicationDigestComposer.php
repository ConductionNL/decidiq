<?php

/**
 * Decidiq Publication Digest Composer
 *
 * Writes one digest message: a title that names the body when there is
 * one, and the events grouped per body and meeting, with links for members.
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
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\AppInfo\Application;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Throwable;

/**
 * Composes the title and body of a publication digest.
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */
class PublicationDigestComposer {

	/**
	 * The app-relative page per object type, for the links in a member's message.
	 *
	 * @var array<string, string>
	 */
	private const PAGES = [
		'meeting'  => 'meetings',
		'decision' => 'decisions',
	];

	/**
	 * Construct the composer.
	 *
	 * @param ObjectServiceInterface $objectService Reads body names
	 * @param IURLGenerator          $urlGenerator  Absolute links in a member's message
	 * @param IFactory               $l10nFactory   Translations of the title
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * The title and message of one digest.
	 *
	 * @param array<int,array<string,mixed>> $events    The events, oldest first
	 * @param bool                           $withLinks Whether to add links (members only)
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function compose(array $events, bool $withLinks): array {
		$l10n      = $this->l10nFactory->get('decidiq');
		$bodyNames = [];
		$lines     = [];
		foreach ($this->grouped(events: $events) as $body => $meetings) {
			$bodyNames[$body] = $this->bodyName(id: $body);
			$lines[]          = $bodyNames[$body];
			if ($bodyNames[$body] === '') {
				$lines[(count($lines) - 1)] = $l10n->t('Other');
			}

			$lines   = array_merge($lines, self::meetingLines(meetings: $meetings, withLinks: $withLinks));
			$lines[] = '';
		}

		$body = '';
		if (count($bodyNames) === 1) {
			$body = reset($bodyNames);
		}

		return [self::title(l10n: $l10n, count: count($events), body: $body), rtrim(implode("\n", $lines))];
	}//end compose()

	/**
	 * The events grouped per body, then per meeting (or object when there is no meeting).
	 *
	 * @param array<int,array<string,mixed>> $events The events
	 *
	 * @return array<string,array<string,array{title: string, link: string, lines: array<int,string>}>>
	 */
	private function grouped(array $events): array {
		$groups = [];
		foreach ($events as $event) {
			$body  = (string)($event['governanceBody'] ?? '');
			$group = (string)($event['meeting'] ?? '');
			if ($group === '') {
				$group = (string)$event['objectId'];
			}

			$groups[$body][$group]['title']   = (string)($event['title'] ?? '');
			$groups[$body][$group]['link']    = $this->link(event: $event);
			$groups[$body][$group]['lines'][] = (string)($event['summary'] ?? '');
		}

		return $groups;
	}//end grouped()

	/**
	 * The lines of one body's meetings.
	 *
	 * @param array<string,array{title: string, link: string, lines: array<int,string>}> $meetings  The meetings
	 * @param bool                                                                       $withLinks Whether to add links
	 *
	 * @return array<int,string>
	 */
	private static function meetingLines(array $meetings, bool $withLinks): array {
		$lines = [];
		foreach ($meetings as $meeting) {
			$lines[] = '  ' . $meeting['title'];
			foreach ($meeting['lines'] as $line) {
				$lines[] = '  - ' . $line;
			}

			if ($withLinks === true && $meeting['link'] !== '') {
				$lines[] = '  ' . $meeting['link'];
			}
		}

		return $lines;
	}//end meetingLines()

	/**
	 * The title: plain t() per count, because the catalogues carry no plural forms.
	 *
	 * @param IL10N  $l10n  Translations
	 * @param int    $count The number of events
	 * @param string $body  The one body's name, or '' for several
	 *
	 * @return string
	 */
	private static function title(IL10N $l10n, int $count, string $body): string {
		if ($body === '' && $count === 1) {
			return $l10n->t('1 update from the bodies you follow');
		}

		if ($body === '') {
			return $l10n->t('%d updates from the bodies you follow', [$count]);
		}

		if ($count === 1) {
			return $l10n->t('1 update from %s', [$body]);
		}

		return $l10n->t('%1$d updates from %2$s', [$count, $body]);
	}//end title()

	/**
	 * The absolute link to the meeting or decision of an event, or ''.
	 *
	 * @param array<string,mixed> $event The event
	 *
	 * @return string
	 */
	private function link(array $event): string {
		$meeting = (string)($event['meeting'] ?? '');
		$path    = '';
		if ($meeting !== '') {
			$path = self::PAGES['meeting'] . '/' . $meeting;
		} else if (isset(self::PAGES[(string)$event['objectType']]) === true) {
			$path = self::PAGES[(string)$event['objectType']] . '/' . (string)$event['objectId'];
		}

		if ($path === '') {
			return '';
		}

		$base = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page');
		return rtrim($base, '/') . '/' . $path;
	}//end link()

	/**
	 * A body's name, or '' when it cannot be read.
	 *
	 * @param string $id The body
	 *
	 * @return string
	 */
	private function bodyName(string $id): string {
		if ($id === '') {
			return '';
		}

		try {
			$found = $this->objectService->find(id: $id, register: 'decidiq', schema: 'governance-body', _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return '';
		}

		return (string)($found?->getObject()['name'] ?? '');
	}//end bodyName()
}//end class
