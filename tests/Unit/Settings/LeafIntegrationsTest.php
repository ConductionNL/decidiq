<?php

/**
 * The Nextcloud leaves decidiq offers, as the register and the manifest declare them.
 *
 * Calendar on the meeting, contacts on people and bodies, an advisory straw
 * poll and an intake form, and the Mail sidebar's link and create-from-email
 * surfaces. Read from the register as it ships (base plus every register.d
 * fragment, merged as the importer merges it) and from the manifest with its
 * fragments, so a later fragment cannot quietly undo a declaration.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-005-mail-sidebar-linking-shall-be-declared-on-the-schemas-that-carry-email-surfaces
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @uses \OCA\Decidiq\Service\SettingsService
 * @uses \OCA\Decidiq\Service\Settings\RegisterFragmentMerger
 */
final class LeafIntegrationsTest extends TestCase {

	/**
	 * The schemas the Mail sidebar may link a mail to.
	 */
	private const MAIL_LINKABLE = ['ActionItem', 'AgendaItem', 'Decision', 'Meeting'];

	/**
	 * Every schema of the register as it ships, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		return SettingsService::shippedRegisterDescriptor()['components']['schemas'];
	}//end schemas()

	/**
	 * Every page of the manifest and its fragments, by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pages(): array {
		$root = __DIR__ . '/../../../src/';
		$files = array_merge([$root . 'manifest.json'], (glob($root . 'manifest.d/*.json') ?: []));
		$pages = [];
		foreach ($files as $file) {
			$manifest = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach (($manifest['pages'] ?? []) as $page) {
				$pages[(string)$page['id']] = $page;
			}
		}

		return $pages;
	}//end pages()

	/**
	 * The ids of the integration leaves a page renders.
	 *
	 * @param string $pageId The page.
	 *
	 * @return array<int, string>
	 */
	private static function leavesOn(string $pageId): array {
		$leaves = [];
		foreach ((self::pages()[$pageId]['config']['widgets'] ?? []) as $widget) {
			if (($widget['type'] ?? '') === 'integration') {
				$leaves[] = (string)$widget['integrationId'];
			}
		}

		return $leaves;
	}//end leavesOn()

	/**
	 * Every widget of every page offering this leaf, as page id => widget.
	 *
	 * @param string $leaf The integration id.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private static function widgetsOf(string $leaf): array {
		$found = [];
		foreach (self::pages() as $id => $page) {
			foreach (($page['config']['widgets'] ?? []) as $widget) {
				if (($widget['type'] ?? '') === 'integration' && ($widget['integrationId'] ?? '') === $leaf) {
					$found[] = [$id, $widget];
				}
			}
		}

		return $found;
	}//end widgetsOf()

	/**
	 * Exactly the four schemas with an email surface name `mail` in linkedTypes.
	 *
	 * @return void
	 */
	public function testExactlyTheFourSchemasWithAnEmailSurfaceAreMailLinkable(): void {
		$linkable = [];
		foreach (self::schemas() as $name => $schema) {
			if (in_array('mail', ($schema['configuration']['linkedTypes'] ?? []), true) === true) {
				$linkable[] = $name;
			}
		}

		sort($linkable);
		self::assertSame(self::MAIL_LINKABLE, $linkable);
	}//end testExactlyTheFourSchemasWithAnEmailSurfaceAreMailLinkable()

	/**
	 * Every declared linked type is one OpenRegister accepts, so the import cannot refuse it.
	 *
	 * @return void
	 */
	public function testEveryLinkedTypeIsOneOpenRegisterAccepts(): void {
		$accepted = ['files', 'mail', 'contacts', 'notes', 'todos', 'calendar', 'talk', 'deck'];
		foreach (self::MAIL_LINKABLE as $name) {
			$types = self::schemas()[$name]['configuration']['linkedTypes'];
			self::assertSame($types, array_values(array_unique($types)), $name . ' names a linked type twice');
			self::assertSame([], array_diff($types, $accepted), $name . ' names a linked type OpenRegister refuses');
		}
	}//end testEveryLinkedTypeIsOneOpenRegisterAccepts()

	/**
	 * Only Decision can be created from a mail, and the mail makes a draft resolution.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-006-create-from-email-shall-exist-for-draft-decisions-only
	 */
	public function testOnlyADraftDecisionCanBeCreatedFromAMail(): void {
		$templated = [];
		foreach (self::schemas() as $name => $schema) {
			if (isset($schema['configuration']['mailObjectTemplate']) === true) {
				$templated[] = $name;
			}
		}

		self::assertSame(['Decision'], $templated, 'ActionItem is a read-only projection: a create button there could never save.');
		self::assertSame(
			[
				'title' => '{{subject}}',
				'text' => '{{preview}}',
				'externalReference' => '{{mailRef}}',
				'lifecycle' => 'draft',
				'decisionType' => 'resolution',
			],
			self::schemas()['Decision']['configuration']['mailObjectTemplate']
		);
	}//end testOnlyADraftDecisionCanBeCreatedFromAMail()

	/**
	 * The template fills every property a Decision requires, and only properties it has.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-006-create-from-email-shall-exist-for-draft-decisions-only
	 */
	public function testTheTemplateFillsADecisionThatCanBeSaved(): void {
		$decision = self::schemas()['Decision'];
		$template = $decision['configuration']['mailObjectTemplate'];

		self::assertSame([], array_diff($decision['required'], array_keys($template)), 'a required property is left empty');
		self::assertSame([], array_diff(array_keys($template), array_keys($decision['properties'])), 'the template names a property Decision lacks');
		self::assertContains('draft', $decision['properties']['lifecycle']['enum']);
	}//end testTheTemplateFillsADecisionThatCanBeSaved()

	/**
	 * The calendar leaf sits on the meeting's integrations page, once in the whole manifest.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-001-the-meeting-detail-shall-offer-a-calendar-leaf
	 */
	public function testTheMeetingOffersTheOnlyCalendarLeaf(): void {
		$calendars = self::widgetsOf('calendar');

		self::assertCount(1, $calendars);
		self::assertSame('MeetingIntegrations', $calendars[0][0]);
		self::assertSame(['deck', 'talk', 'files', 'notes', 'calendar'], self::leavesOn('MeetingIntegrations'));
	}//end testTheMeetingOffersTheOnlyCalendarLeaf()

	/**
	 * Contacts sit on the participant and the governance body, and nowhere else.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-002-people-and-body-pages-shall-offer-a-contacts-leaf
	 */
	public function testPeopleAndBodiesOfferTheContactsLeaf(): void {
		$pages = array_map(static fn (array $found): string => $found[0], self::widgetsOf('contacts'));
		sort($pages);

		self::assertSame(['GovernanceBodyDetail', 'ParticipantDetail'], $pages);
	}//end testPeopleAndBodiesOfferTheContactsLeaf()

	/**
	 * The poll is a straw poll on the consultation and the decision, and says so in its title.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-003-straw-polls-shall-be-offered-as-an-advisory-polls-leaf-upstream-of-formal-voting
	 */
	public function testThePollIsAnAdvisoryStrawPoll(): void {
		$polls = self::widgetsOf('polls');
		$pages = array_map(static fn (array $found): string => $found[0], $polls);
		sort($pages);

		self::assertSame(['ConsultationDetail', 'DecisionIntegrations'], $pages);
		foreach ($polls as [$page, $widget]) {
			self::assertSame('Straw poll', $widget['title'], $page . ' does not say the poll is advisory');
		}
	}//end testThePollIsAnAdvisoryStrawPoll()

	/**
	 * The consultation offers the forms leaf as its intake channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function testTheConsultationOffersAnIntakeForm(): void {
		self::assertContains('forms', self::leavesOn('ConsultationDetail'));
	}//end testTheConsultationOffersAnIntakeForm()

	/**
	 * Every new widget is placed in its page's layout, so it renders.
	 *
	 * @return void
	 */
	public function testEveryNewLeafIsLaidOut(): void {
		foreach (['calendar', 'contacts', 'polls', 'forms'] as $leaf) {
			foreach (self::widgetsOf($leaf) as [$page, $widget]) {
				$placed = array_column((self::pages()[$page]['config']['layout'] ?? []), 'widgetId');
				self::assertContains($widget['id'], $placed, $widget['id'] . ' on ' . $page . ' has no place in the layout');
			}
		}
	}//end testEveryNewLeafIsLaidOut()

	/**
	 * The new widget titles have a Dutch translation.
	 *
	 * @return void
	 */
	public function testTheWidgetTitlesAreTranslated(): void {
		$root = __DIR__ . '/../../../l10n/';
		$en = json_decode((string)file_get_contents($root . 'en.json'), true)['translations'];
		$nl = json_decode((string)file_get_contents($root . 'nl.json'), true)['translations'];
		foreach (['Meeting calendar', 'Contacts', 'Straw poll', 'Intake form'] as $title) {
			self::assertSame($title, ($en[$title] ?? null));
			self::assertNotSame($title, ($nl[$title] ?? $title), $title . ' has no Dutch translation');
		}
	}//end testTheWidgetTitlesAreTranslated()
}//end class
