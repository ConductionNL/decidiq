<?php

/**
 * Decidiq Notifier
 *
 * Renders every notification decidiq sends, so it can be shown in the
 * Nextcloud notification bell.
 *
 * @category Notification
 * @package  OCA\Decidiq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Notification;

use OCA\Decidiq\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Prepares decidiq's notifications (REQ-ACN-001).
 *
 * Nextcloud's Manager::prepare() asks every registered notifier to render a
 * notification; without one for app `decidiq`, every notice decidiq created
 * (agenda, motion, proxy, email vote and the preference-aware generic one)
 * stayed unrenderable and never reached the bell (decidiq#1381). The notifier
 * declines other apps and unknown subjects with UnknownNotificationException,
 * as the interface requires, and throws nothing else.
 *
 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
 */
class Notifier implements INotifier {

	/**
	 * Subject key => [English sentence with %s for the named object, object page prefix].
	 *
	 * The sentences are the l10n source strings; `%s` is the meeting or motion
	 * title when the sender passed one.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const SUBJECTS = [
		'agenda_published'          => ['The agenda of %s was published', 'meetings/'],
		'agenda_revised'            => ['A revised agenda of %s was published', 'meetings/'],
		'agenda_revision_started'   => ['The agenda of %s is being revised', 'meetings/'],
		'agenda_changed'            => ['The agenda of %s changed', 'meetings/'],
		'co_sign_request'           => ['You are asked to co-sign the motion %s', 'motions/'],
		'motion_forwarded_approval' => ['The motion %s was forwarded to you for approval', 'motions/'],
		'proxy_granted'             => ['You received a proxy to vote for another member', ''],
		'email_vote_confirmed'      => ['Your vote by email was recorded', ''],
		'email_vote_abandoned'      => ['Your vote by email could not be read and was not counted', ''],
		'email_vote_reprompt'       => ['Your vote by email could not be read, please reply again', ''],
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory      $l10nFactory  Translations per recipient language
	 * @param IURLGenerator $urlGenerator Absolute links and the icon
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The notifier id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * The notifier name shown in Nextcloud's settings.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 */
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Decidiq');
	}//end getName()

	/**
	 * Render one decidiq notification in the recipient's language.
	 *
	 * @param INotification $notification The notification to render
	 * @param string        $languageCode The recipient's language
	 *
	 * @return INotification The rendered notification
	 *
	 * @throws UnknownNotificationException For another app or an unknown subject
	 *
	 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l10n = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$subject = $notification->getSubject();
		$params = $notification->getSubjectParameters();
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

		if ($subject === 'decidiq_message') {
			$notification->setParsedSubject((string)($params['title'] ?? $l10n->t('Decidiq')));
			$message = (string)($params['message'] ?? '');
			if ($message !== '') {
				$notification->setParsedMessage($message);
			}

			$notification->setLink($this->appLink(path: ltrim((string)($params['link'] ?? ''), '/')));
			return $notification;
		}

		if (isset(self::SUBJECTS[$subject]) === false) {
			throw new UnknownNotificationException();
		}

		[$sentence, $page] = self::SUBJECTS[$subject];
		$named = (string)($params['meetingTitle'] ?? $params['motionTitle'] ?? $params['title'] ?? '');
		if ($named === '') {
			$named = $l10n->t('this meeting');
			if ($page === 'motions/') {
				$named = $l10n->t('this motion');
			}
		}

		$notification->setParsedSubject($l10n->t($sentence, [$named]));
		$path = '';
		if ($page !== '' && $notification->getObjectId() !== '') {
			$path = $page . rawurlencode($notification->getObjectId());
		}

		$notification->setLink($this->appLink(path: $path));
		return $notification;
	}//end prepare()

	/**
	 * An absolute link into the decidiq app.
	 *
	 * @param string $path App-relative path without a leading slash, or ''
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agenda-change-notices-reach-members/specs/decidesk-notifications/spec.md#requirement-req-acn-001-every-notice-decidiq-sends-can-be-shown
	 */
	private function appLink(string $path): string {
		return rtrim($this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page'), '/') . '/' . $path;
	}//end appLink()
}//end class
