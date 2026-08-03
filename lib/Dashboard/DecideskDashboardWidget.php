<?php

/**
 * Decidesk Nextcloud Dashboard Widget
 *
 * Registers a "Decidesk" widget on the Nextcloud main dashboard (the Hub) via
 * the platform OCP\Dashboard\IWidget API. Shows the current user's pending
 * votes count and their next upcoming meeting, deep-linking back into the
 * Decidesk app. Per-user (session-scoped, no IDOR) and fail-soft.
 *
 * @category Dashboard
 * @package  OCA\Decidesk\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Dashboard;

use DateTimeImmutable;
use OCA\Decidesk\Service\DashboardWidgetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * The Decidesk Hub dashboard widget.
 *
 * Implements the base {@see \OCP\Dashboard\IWidget} contract plus
 * {@see \OCP\Dashboard\IIconWidget} (icon url), {@see \OCP\Dashboard\IButtonWidget}
 * (an "Open Decidesk" deep-link button) and {@see \OCP\Dashboard\IAPIWidgetV2}
 * (the NC32 pure-backend item path — no JS bundle required). Item data is
 * resolved per-user by {@see DashboardWidgetService}.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DecideskDashboardWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget
{
    /**
     * Constructor.
     *
     * @param IL10N                  $l10n          App-scoped translation
     * @param IURLGenerator          $urlGenerator  URL generator (deep links)
     * @param ITimeFactory           $timeFactory   Clock source
     * @param DashboardWidgetService $widgetService Per-user summary resolver
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
        private readonly ITimeFactory $timeFactory,
        private readonly DashboardWidgetService $widgetService,
    ) {
    }//end __construct()

    /**
     * Unique widget id.
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The widget id
     */
    public function getId(): string
    {
        return 'decidesk';

    }//end getId()

    /**
     * Human-readable widget title.
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The translated title
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Decidesk');

    }//end getTitle()

    /**
     * Sort order among dashboard widgets.
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return int The order weight
     */
    public function getOrder(): int
    {
        return 20;

    }//end getOrder()

    /**
     * CSS icon class (rendered before the icon url loads).
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The icon class
     */
    public function getIconClass(): string
    {
        return 'icon-decidesk';

    }//end getIconClass()

    /**
     * Absolute icon url for the widget header.
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The icon url
     */
    public function getIconUrl(): string
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('decidesk', 'app-dark.svg')
        );

    }//end getIconUrl()

    /**
     * Deep link opened when the widget header is clicked.
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string|null The Decidesk app url
     */
    public function getUrl(): ?string
    {
        return $this->appUrl();

    }//end getUrl()

    /**
     * No server-side asset loading is required for this widget.
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return void
     */
    public function load(): void
    {
        // The IAPIWidgetV2 path renders items server-side; no JS/CSS to load.
    }//end load()

    /**
     * Header buttons — a single "Open Decidesk" deep-link button.
     *
     * @param string $userId Current Nextcloud user id (unused — link is static)
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @return WidgetButton[] The widget buttons
     */
    public function getWidgetButtons(string $userId): array
    {
        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                $this->appUrl(),
                $this->l10n->t('Open Decidesk')
            ),
        ];

    }//end getWidgetButtons()

    /**
     * Per-user widget items: pending votes count + next upcoming meeting.
     *
     * Fail-soft: the underlying service never throws, so a broken or absent
     * register yields an empty item set with an empty-content message.
     *
     * @param string      $userId Current Nextcloud user id (platform-resolved)
     * @param string|null $since  Pagination cursor (unused — fixed summary)
     * @param int         $limit  Max items (unused — at most two summary items)
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @return WidgetItems The widget items
     */
    public function getItemsV2(string $userId, ?string $since=null, int $limit=7): WidgetItems
    {
        $summary = $this->widgetService->getUserSummary(
            userId: $userId,
            now: $this->timeFactory->getTime()
        );

        $appUrl  = $this->appUrl();
        $iconUrl = $this->getIconUrl();
        $items   = [];

        $pending = $summary['pendingVotes'];
        $items[] = new WidgetItem(
            $this->l10n->t('Pending votes: %s', [(string) $pending]),
            $this->l10n->t('Decisions awaiting your vote'),
            $appUrl,
            $iconUrl,
            'decidesk-pending-votes'
        );

        $nextMeeting = ($summary['nextMeeting'] ?? null);
        if (is_array($nextMeeting) === true) {
            $title    = (string) ($nextMeeting['title'] ?? ($nextMeeting['name'] ?? $this->l10n->t('Next meeting')));
            $subtitle = $this->formatMeetingSubtitle(scheduledDate: (string) ($nextMeeting['scheduledDate'] ?? ''));
            $items[]  = new WidgetItem(
                $title,
                $subtitle,
                $appUrl,
                $iconUrl,
                'decidesk-next-meeting'
            );
        } else {
            $items[] = new WidgetItem(
                $this->l10n->t('No upcoming meetings'),
                '',
                $appUrl,
                $iconUrl,
                'decidesk-next-meeting'
            );
        }//end if

        return new WidgetItems(
            $items,
            $this->l10n->t('You are all caught up'),
        );

    }//end getItemsV2()

    /**
     * Absolute url to the Decidesk app root (in-app dashboard).
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The deep-link url
     */
    private function appUrl(): string
    {
        try {
            return $this->urlGenerator->linkToRouteAbsolute('decidesk.dashboard.page');
        } catch (\Throwable) {
            return $this->urlGenerator->getAbsoluteURL('/apps/decidesk/');
        }

    }//end appUrl()

    /**
     * Format the next-meeting subtitle from its scheduledDate.
     *
     * @param string $scheduledDate ISO-8601 scheduled date
     *
     * @spec openspec/specs/dashboard/spec.md
     *
     * @return string The subtitle (formatted date, or a fallback label)
     */
    private function formatMeetingSubtitle(string $scheduledDate): string
    {
        if ($scheduledDate === '') {
            return $this->l10n->t('Your next meeting');
        }

        try {
            $dt = new DateTimeImmutable($scheduledDate);
        } catch (\Throwable) {
            return $this->l10n->t('Your next meeting');
        }

        return $dt->format('Y-m-d H:i');

    }//end formatMeetingSubtitle()
}//end class
