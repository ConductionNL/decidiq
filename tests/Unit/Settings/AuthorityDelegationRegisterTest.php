<?php

/**
 * The delegatie- en mandaatregister as it ships (delegatie-mandaatregister
 * tasks 4-6): the public read predicate honours publication and withdrawal on
 * the live object, a decision can name the delegation it was taken under, and
 * the pages wire the in-force view, the publish actions and both directions of
 * that link.
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
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-005-public-register-via-the-or-published-predicate-on-the-live-object
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-006-assistive-bevoegdheidsgrondslag-link-on-decision-never-enforcement
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\AnyOfFieldsGuard;
use OCA\Decidiq\Service\OndermandaatGuardService;
use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\SettingsService::shippedRegisterDescriptor
 * @uses   \OCA\Decidiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Decidiq\Service\AnyOfFieldsGuard
 */
class AuthorityDelegationRegisterTest extends TestCase {

	/**
	 * The shipped schemas, keyed by component name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		return (SettingsService::shippedRegisterDescriptor()['components']['schemas'] ?? []);
	}//end schemas()

	/**
	 * A page of the delegation register fragment by id.
	 *
	 * @param string $id The page id.
	 *
	 * @return array<string, mixed>
	 */
	private static function fragmentPage(string $id): array {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.d/delegatie-mandaatregister.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fragment['pages'] as $page) {
			if ($page['id'] === $id) {
				return $page['config'];
			}
		}

		self::fail('No page ' . $id);
	}//end fragmentPage()

	/**
	 * A widget of a page config by id.
	 *
	 * @param array<string, mixed> $config The page config.
	 * @param string               $id     The widget id.
	 *
	 * @return array<string, mixed>
	 */
	private static function widget(array $config, string $id): array {
		foreach ($config['widgets'] as $widget) {
			if ($widget['id'] === $id) {
				return $widget;
			}
		}

		self::fail('No widget ' . $id);
	}//end widget()

	/**
	 * The municipality example plants the five toedelingen of the design's seed table,
	 * every reference resolving to a seed object.
	 *
	 * @return void
	 */
	public function testTheExampleSeedsAChainAndATracedWithdrawal(): void {
		$doc     = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/municipality.json'), true, 512, JSON_THROW_ON_ERROR);
		$objects = $doc['x-openregister']['seedData']['objects'];
		$rows    = array_column($objects['authority-delegation'], null, 'slug');
		$slugs   = static fn (string $schema): array => array_column($objects[$schema], 'slug');

		$this->assertSame(
			['delegation', 'authorisation', 'mandate', 'mandate', 'power-of-attorney'],
			array_column($objects['authority-delegation'], 'type')
		);

		$child  = $rows['ondermandaat-subsidies-samenleving'];
		$parent = $rows[$child['parentAllocation']];
		$this->assertTrue($parent['subMandatePermitted']);
		$this->assertArrayNotHasKey('parentAllocation', $parent);
		$this->assertNotEmpty($child['publicationDate']);

		$this->assertSame('draft', $rows['volmacht-inkoop-teamleider']['status']);

		$withdrawn = $rows['machtiging-woo-vertegenwoordiging'];
		$this->assertSame('withdrawn', $withdrawn['status']);
		$this->assertContains($withdrawn['withdrawnBy'], $slugs('decision'));

		foreach ($rows as $slug => $row) {
			$this->assertContains($row['decision'], $slugs('decision'), $slug);
			foreach (['delegatingBody', 'delegateBody'] as $field) {
				if (isset($row[$field]) === true) {
					$this->assertContains($row[$field], $slugs('governance-body'), $slug . '.' . $field);
				}
			}

			$this->assertSame([], (new AnyOfFieldsGuard())->emptyGroups(row: $row, groups: OndermandaatGuardService::PARTY_GROUPS), $slug);
		}
	}//end testTheExampleSeedsAChainAndATracedWithdrawal()

	/**
	 * Public read holds only for a published record that has not been withdrawn: an
	 * empty or future depublication date, never a past one.
	 *
	 * @return void
	 */
	public function testThePublicReadsAPublishedToedelingUntilItIsWithdrawn(): void {
		$read   = self::schemas()['AuthorityDelegation']['authorization']['read'];
		$public = array_values(array_filter($read, static fn (mixed $rule): bool => is_array($rule) === true && ($rule['group'] ?? null) === 'public'));

		$this->assertSame(
			[
				['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$exists' => false]],
				['publicationDate' => ['$lte' => '$now'], 'depublicationDate' => ['$gt' => '$now']],
			],
			array_column($public, 'match')
		);
		$this->assertContains('authenticated', $read);
	}//end testThePublicReadsAPublishedToedelingUntilItIsWithdrawn()

	/**
	 * The schema is publishable by construction and says so.
	 *
	 * @return void
	 */
	public function testTheSchemaHasNoInternalOnlyFieldAndRecordsWhy(): void {
		$schema = self::schemas()['AuthorityDelegation'];

		$this->assertSame([], array_keys(array_filter($schema['properties'], static fn (array $property): bool => ($property['writeOnly'] ?? false) === true)));
		$this->assertStringContainsString('Publishable by construction', $schema['description']);
	}//end testTheSchemaHasNoInternalOnlyFieldAndRecordsWhy()

	/**
	 * A decision may name the delegation it was taken under and stays valid without one.
	 *
	 * @return void
	 */
	public function testADecisionMayNameTheDelegationItWasTakenUnder(): void {
		$decision = self::schemas()['Decision'];

		$this->assertSame(
			['title' => 'Taken under', 'type' => 'string', 'format' => 'uuid', '$ref' => 'authority-delegation', 'nullable' => true],
			array_intersect_key($decision['properties']['authorityBasis'], array_flip(['title', 'type', 'format', '$ref', 'nullable']))
		);
		$this->assertNotContains('authorityBasis', ($decision['required'] ?? []));
	}//end testADecisionMayNameTheDelegationItWasTakenUnder()

	/**
	 * The in-force view is one list query: in force, started, and not ended or open-ended.
	 *
	 * @return void
	 */
	public function testTheInForceTodayFilterIsOneQuery(): void {
		$filters = array_column(self::fragmentPage('AuthorityDelegations')['quickFilters'], 'filter', 'label');

		$this->assertSame(
			['status' => 'effective', 'validFrom[lte]' => '@today', 'validTo[gte]' => '@today', 'validTo[orNull]' => true],
			$filters['In force today']
		);
	}//end testTheInForceTodayFilterIsOneQuery()

	/**
	 * Publish and withdraw patch only the publication dates of the live object.
	 *
	 * @return void
	 */
	public function testPublishAndWithdrawPatchOnlyThePublicationDates(): void {
		$actions = array_column(self::fragmentPage('AuthorityDelegationDetail')['headerActions'], null, 'id');

		foreach (['publish' => ['publicationDate' => '@now', 'depublicationDate' => null], 'withdraw-publication' => ['depublicationDate' => '@now']] as $id => $payload) {
			$this->assertSame('api-call', $actions[$id]['type'], $id);
			$this->assertSame('PATCH', $actions[$id]['method'], $id);
			$this->assertSame('/apps/openregister/api/objects/decidiq/authority-delegation/@objectId', $actions[$id]['url'], $id);
			$this->assertSame($payload, $actions[$id]['payload'], $id);
			$this->assertTrue($actions[$id]['confirm'], $id);
		}
	}//end testPublishAndWithdrawPatchOnlyThePublicationDates()

	/**
	 * The delegation lists the decisions taken under it, and a decision links to its delegation.
	 *
	 * @return void
	 */
	public function testTheLinkIsShownInBothDirections(): void {
		$decisions = self::widget(self::fragmentPage('AuthorityDelegationDetail'), 'toedeling-decisions')['content'];
		$this->assertSame(['decision', ['authorityBasis' => '@objectId'], 'DecisionDetail'], [$decisions['schema'], $decisions['filter'], $decisions['rowRoute']]);

		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		$detail   = array_values(array_filter($manifest['pages'], static fn (array $page): bool => $page['id'] === 'DecisionDetail'))[0]['config'];
		$basis    = self::widget($detail, 'decision-authority-basis');

		$this->assertSame(['field' => 'authorityBasis', 'op' => 'notEmpty'], $basis['visibleWhen']);
		$this->assertSame(
			expected: ['authority-delegation', ['uuid' => '@object.authorityBasis'], 'AuthorityDelegationDetail'],
			actual: [$basis['content']['schema'], $basis['content']['filter'], $basis['content']['rowRoute']]
		);
		$this->assertContains('decision-authority-basis', array_column($detail['layout'], 'widgetId'));
		$this->assertContains('authorityBasis', self::widget($detail, 'decision-content')['content']['include']);
	}//end testTheLinkIsShownInBothDirections()
	/**
	 * A delegation cannot be stored without the decision it was granted by.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-001-bevoegdheidstoedeling-schema
	 */
	public function testADelegationNeedsItsGrantingDecision(): void {
		$schema = self::schemas()['AuthorityDelegation'];

		$this->assertContains(needle: 'decision', haystack: $schema['required']);
		$this->assertSame(expected: 'uuid', actual: $schema['properties']['decision']['format']);
	}//end testADelegationNeedsItsGrantingDecision()

	/**
	 * The lifecycle runs draft, effective, then withdrawn or lapsed, and nothing leaves a terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-002-declarative-status-lifecycle-with-intrekking-traced-to-a-revoking-decision
	 */
	public function testTheLifecycleEndsInWithdrawnOrLapsed(): void {
		$lifecycle = self::schemas()['AuthorityDelegation']['x-openregister-lifecycle'];

		$this->assertSame(expected: 'status', actual: $lifecycle['field']);
		$this->assertSame(expected: 'draft', actual: $lifecycle['initial']);
		$this->assertSame(expected: ['withdrawn', 'lapsed'], actual: $lifecycle['terminal']);
		$this->assertSame(
			expected: ['draft>effective', 'effective>withdrawn', 'effective>lapsed'],
			actual: array_map(static fn (array $edge): string => $edge['from'] . '>' . $edge['to'], $lifecycle['transitions'])
		);
		$this->assertSame(expected: [], actual: array_values(array_intersect(array_column($lifecycle['transitions'], 'from'), $lifecycle['terminal'])));
	}//end testTheLifecycleEndsInWithdrawnOrLapsed()

	/**
	 * The two expiry reminders compare validTo with a window: an in-force delegation
	 * ending within 60 or 14 days is reminded once per expiry date, named in the
	 * subject; an open-ended one has no validTo to match, and a terminal one fails
	 * the status clause.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-007-declarative-geldigheid-expiry-notifications
	 */
	public function testTheExpiryRemindersWatchTheEndDate(): void {
		$rules = self::schemas()['AuthorityDelegation']['x-openregister-notifications'];

		foreach (['bevoegdheidExpiry60' => 'P60D', 'bevoegdheidExpiry14' => 'P14D'] as $name => $window) {
			$trigger = $rules[$name]['trigger'];
			$this->assertSame(expected: 'scheduled', actual: $trigger['type'], message: $name);
			$this->assertSame(
				expected: ['status' => 'effective', 'validTo' => ['operator' => 'withinNext', 'value' => $window]],
				actual: $trigger['filter'],
				message: $name
			);
			$this->assertSame(expected: ['validTo'], actual: $trigger['dedupeFields'], message: $name);
			$this->assertLessThanOrEqual(expected: 86400, actual: $trigger['intervalSec'], message: $name . ' is evaluated at least daily');
			foreach (['nl', 'en'] as $locale) {
				$this->assertStringContainsString(needle: '{{validTo}}', haystack: $rules[$name]['subject'][$locale], message: $name . ' ' . $locale);
			}
		}
	}//end testTheExpiryRemindersWatchTheEndDate()
}//end class
