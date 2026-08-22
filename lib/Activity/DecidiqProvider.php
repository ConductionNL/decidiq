<?php

/**
 * Decidiq Activity Provider
 *
 * Renders Decidiq governance activity events (decision recorded/published,
 * meeting lifecycle transitions, vote initiation, resolution adoption) for
 * the Nextcloud Activity stream.
 *
 * @category Activity
 * @package  OCA\Decidiq\Activity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Activity;

use OCA\Decidiq\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Parses Decidiq governance events for the Activity stream.
 *
 * Registered via appinfo/info.xml <activity><providers>. Subject parameters
 * are produced by {@see \OCA\Decidiq\Service\ActivityPublisherService} and
 * carry the object title, status, OR uuid, and frontend route segment so the
 * provider can render without re-fetching OpenRegister objects.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class DecidiqProvider implements IProvider {

	/**
	 * Subject identifier: a decision was recorded in a live meeting.
	 *
	 * @var string
	 */
	public const SUBJECT_DECISION_RECORDED = 'decision_recorded';

	/**
	 * Subject identifier: a decision status changed to published.
	 *
	 * @var string
	 */
	public const SUBJECT_DECISION_PUBLISHED = 'decision_published';

	/**
	 * Subject identifier: a meeting lifecycle transition completed.
	 *
	 * @var string
	 */
	public const SUBJECT_MEETING_TRANSITION = 'meeting_transition';

	/**
	 * Subject identifier: a voting round or board resolution vote opened.
	 *
	 * @var string
	 */
	public const SUBJECT_VOTE_INITIATED = 'vote_initiated';

	/**
	 * Subject identifier: a resolution concluded as adopted.
	 *
	 * @var string
	 */
	public const SUBJECT_RESOLUTION_ADOPTED = 'resolution_adopted';

	/**
	 * Constructor.
	 *
	 * @param IFactory $languageFactory L10N factory used to translate in the event's language
	 * @param IURLGenerator $urlGenerator URL generator for icons and deep links
	 */
	public function __construct(
		private readonly IFactory $languageFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Parse a Decidiq governance event into a rendered activity entry.
	 *
	 * @param string $language The language to translate into, e.g. "en"
	 * @param IEvent $event The event to parse
	 * @param IEvent|null $previousEvent A potential previous event (unused — no merging)
	 *
	 * @return IEvent
	 *
	 * @throws UnknownActivityException When the event does not belong to Decidiq
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $previousEvent required by the IProvider interface.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null) {
		if ($event->getApp() !== Application::APP_ID
			|| $event->getType() !== GovernanceSetting::TYPE_GOVERNANCE
		) {
			throw new UnknownActivityException('Not a Decidiq governance event');
		}

		$l10n = $this->languageFactory->get(Application::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$title = (string)($params['title'] ?? '');
		$status = (string)($params['status'] ?? '');

		[$plain, $rich] = match ($event->getSubject()) {
			self::SUBJECT_DECISION_RECORDED => [
				$l10n->t('Decision "%1$s" was recorded', [$title]),
				$l10n->t('Decision {object} was recorded'),
			],
			self::SUBJECT_DECISION_PUBLISHED => [
				$l10n->t('Decision "%1$s" was published', [$title]),
				$l10n->t('Decision {object} was published'),
			],
			self::SUBJECT_MEETING_TRANSITION => [
				$l10n->t('Meeting "%1$s" moved to "%2$s"', [$title, $status]),
				$l10n->t('Meeting {object} moved to "%1$s"', [$status]),
			],
			self::SUBJECT_VOTE_INITIATED => [
				$l10n->t('Voting opened on "%1$s"', [$title]),
				$l10n->t('Voting opened on {object}'),
			],
			self::SUBJECT_RESOLUTION_ADOPTED => [
				$l10n->t('Resolution "%1$s" was adopted', [$title]),
				$l10n->t('Resolution {object} was adopted'),
			],
			default => throw new UnknownActivityException(
				'Unknown Decidiq activity subject: ' . $event->getSubject()
			),
		};// End match.

		$link = $this->buildDeepLink(params: $params);

		$event->setParsedSubject($plain);
		$event->setRichSubject(
			$rich,
			[
				'object' => [
					'type' => 'highlight',
					'id' => (string)($params['uuid'] ?? ''),
					'name' => $title,
					'link' => $link,
				],
			]
		);

		if ($link !== '') {
			$event->setLink($link);
		}

		$event->setIcon(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
			)
		);

		return $event;
	}//end parse()

	/**
	 * Build the absolute deep link into the Decidiq SPA for an event.
	 *
	 * @param array<string,mixed> $params The event subject parameters (segment + uuid)
	 *
	 * @return string Absolute URL, or empty string when the event carries no target
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	private function buildDeepLink(array $params): string {
		$segment = (string)($params['segment'] ?? '');
		$uuid = (string)($params['uuid'] ?? '');
		if ($segment === '' || $uuid === '') {
			return '';
		}

		return $this->urlGenerator->getAbsoluteURL(
			'/apps/' . Application::APP_ID . '/#/' . $segment . '/' . $uuid
		);

	}//end buildDeepLink()
}//end class
