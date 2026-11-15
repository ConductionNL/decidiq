<?php

/**
 * A post is a position type, holding it is a position hold, and no example set
 * seeds a post any more (configurable-types-domain-model task 3.5, the Post half).
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
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class PostsArePositionTypesRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const PROFILES = ['municipality', 'corporate', 'works-council', 'association'];

	/**
	 * The schema with this component name, merged over the base register and every fragment in load order.
	 *
	 * @param string $name The component name
	 *
	 * @return array<string, mixed> The merged schema.
	 */
	private function mergedSchema(string $name): array {
		$fragments = (glob(self::SETTINGS . 'register.d/*.json') ?: []);
		sort($fragments);
		$schema = [];
		foreach (array_merge([self::SETTINGS . 'decidesk_register.json'], $fragments) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			if (isset($doc['components']['schemas'][$name]) === true) {
				$schema = array_replace_recursive($schema, $doc['components']['schemas'][$name]);
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * An example set's seed objects per schema.
	 *
	 * @param string $profile The example set
	 *
	 * @return array<string, array<int, array<string, mixed>>> The seeds.
	 */
	private function seeds(string $profile): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/' . $profile . '.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * Post is superseded without losing its rows, and nothing writes Membership.post.
	 *
	 * @return void
	 */
	public function testThePostSchemaIsSupersededNonDestructively(): void {
		$post = $this->mergedSchema('Post');

		$this->assertFalse($post['x-openregister']['active']);
		$this->assertFalse($post['x-openregister']['hardDelete']);
		$this->assertSame('post', $post['slug']);
		$this->assertStringStartsWith('SUPERSEDED', $post['description']);
		$this->assertStringStartsWith('SUPERSEDED', $this->mergedSchema('Membership')['properties']['post']['description']);
	}

	/**
	 * An appointment targets position types, and a copied hold records its origin.
	 *
	 * @return void
	 */
	public function testAnAppointmentTargetsPositionTypes(): void {
		$targets = $this->mergedSchema('Decision')['properties']['targetPosts'];
		$this->assertSame('array', $targets['type']);
		$this->assertSame('position-type', $targets['items']['$ref']);

		$hold = $this->mergedSchema('PositionHold');
		$this->assertSame(['membership', 'position', 'holdType', 'startDate'], $hold['required']);
		$this->assertSame('string', $hold['properties']['migratedFromObject']['type']);
	}

	/**
	 * No example set seeds a post or a membership's post; each copied post is a position of a seeded body.
	 *
	 * @return void
	 */
	public function testNoExampleSetSeedsAPost(): void {
		foreach (self::PROFILES as $profile) {
			$seeds = $this->seeds($profile);
			$this->assertArrayNotHasKey('post', $seeds, $profile);
			foreach (($seeds['membership'] ?? []) as $membership) {
				$this->assertArrayNotHasKey('post', $membership, $profile . ' ' . $membership['slug']);
			}
		}

		$set = json_decode((string)file_get_contents(self::SETTINGS . 'example-sets/zuiddrecht.json'), true);
		$this->assertSame([], array_values(array_filter($set['objects'], static fn (array $o): bool => $o['schema'] === 'post' || isset($o['data']['post']))));
	}

	/**
	 * Every seeded hold sits on a membership of the position's own body, and every profile still counts its objects.
	 *
	 * @return void
	 */
	public function testEverySeededHoldIsOnTheMembershipsOwnBody(): void {
		foreach (self::PROFILES as $profile) {
			$seeds = $this->seeds($profile);
			$memberships = array_column(($seeds['membership'] ?? []), 'governanceBody', 'slug');
			$positions = array_column(($seeds['position-type'] ?? []), 'governanceBody', 'slug');
			foreach (($seeds['position-hold'] ?? []) as $hold) {
				$this->assertArrayHasKey($hold['membership'], $memberships, $profile . ' ' . $hold['slug']);
				$this->assertArrayHasKey($hold['position'], $positions, $profile . ' ' . $hold['slug']);
				$this->assertSame($memberships[$hold['membership']], $positions[$hold['position']], $profile . ' ' . $hold['slug']);
				$this->assertSame($hold['governanceBody'], $positions[$hold['position']], $profile . ' ' . $hold['slug']);
			}

			$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/' . $profile . '.json'), true);
			$this->assertSame(array_sum(array_map('count', $seeds)), $doc['x-openregister']['profile']['objectCount'], $profile);
		}

		$works = $this->seeds('works-council');
		$this->assertSame(
			['lidmaatschap-or-de-groot' => 'position-or-voorzitter-acme', 'lidmaatschap-or-benali' => 'position-or-secretaris-acme'],
			array_column($works['position-hold'], 'position', 'membership')
		);
	}
}
