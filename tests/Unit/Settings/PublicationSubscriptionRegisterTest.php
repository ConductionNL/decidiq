<?php

/**
 * Publication subscriptions and events as register data (publication-subscriptions-and-daily-digest, REQ-PSD-001).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The register carries publication-subscription and publication-event, with read rules that keep
 * a member to his own subscriptions, and the example sets validate against the merged schemas.
 *
 * @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
 *
 * @coversNothing
 */
final class PublicationSubscriptionRegisterTest extends TestCase {

	/**
	 * The register as the importer merges it.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		return SettingsService::shippedRegisterDescriptor();
	}//end register()

	/**
	 * The merged schema with this slug.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function schema(string $slug): array {
		foreach ($this->register()['components']['schemas'] as $schema) {
			if (($schema['slug'] ?? '') === $slug) {
				return $schema;
			}
		}

		self::fail("The register has no schema {$slug}.");
	}//end schema()

	/**
	 * Both schemas are imported into the decidiq register.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesBothSchemas(): void {
		$listed = $this->register()['components']['registers']['decidiq']['schemas'];

		self::assertContains('publication-subscription', $listed);
		self::assertContains('publication-event', $listed);
		self::assertSame(['agenda', 'paper', 'decision', 'minutes'], $this->schema('publication-subscription')['properties']['kinds']['items']['enum']);
		self::assertSame(['immediate', 'daily', 'weekly'], $this->schema('publication-subscription')['properties']['frequency']['enum']);
		self::assertSame(['agenda', 'paper', 'decision', 'minutes'], $this->schema('publication-event')['properties']['kind']['enum']);
	}//end testTheRegisterCarriesBothSchemas()

	/**
	 * A member lists only his own subscriptions: read is scoped to subscriberUserId, never plain `authenticated`.
	 *
	 * @return void
	 */
	public function testAMemberReadsOnlyHisOwnSubscriptions(): void {
		$block = $this->schema('publication-subscription')['authorization'];
		$own   = ['group' => 'authenticated', 'match' => ['subscriberUserId' => '$userId']];

		foreach (['read', 'update', 'delete'] as $action) {
			self::assertContains($own, $block[$action], "{$action} must be scoped to the subscriber's own account");
			self::assertNotContains('authenticated', $block[$action], "{$action} must not be open to every member");
			self::assertNotContains('public', $block[$action], "{$action} must not be open to anonymous visitors");
		}

		self::assertSame(['authenticated'], $block['create']);
	}//end testAMemberReadsOnlyHisOwnSubscriptions()

	/**
	 * Events are written by decidiq only and read by administrators.
	 *
	 * @return void
	 */
	public function testEventsAreServiceOwned(): void {
		$block = $this->schema('publication-event')['authorization'];

		self::assertSame(['read'], array_keys($block));
		self::assertNotContains('authenticated', $block['read']);
		self::assertNotContains('public', $block['read']);
	}//end testEventsAreServiceOwned()

	/**
	 * The municipality example set carries subscriptions of a member and a resident and four events,
	 * and each validates against the merged schema.
	 *
	 * @return void
	 */
	public function testTheExampleSetValidates(): void {
		$profile = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/municipality.json'), true);
		$objects = $profile['x-openregister']['seedData']['objects'];

		$subscriptions = ($objects['publication-subscription'] ?? []);
		$events        = ($objects['publication-event'] ?? []);
		self::assertCount(2, $subscriptions);
		self::assertCount(4, $events);
		self::assertSame(['admin', ''], array_map(static fn(array $row): string => (string)($row['subscriberUserId'] ?? ''), $subscriptions));
		self::assertSame(['', 'example-resident'], array_map(static fn(array $row): string => (string)($row['subscriberRef'] ?? ''), $subscriptions));

		foreach ($subscriptions as $row) {
			$this->assertValid(slug: 'publication-subscription', row: $row);
		}

		foreach ($events as $row) {
			$this->assertValid(slug: 'publication-event', row: $row);
		}
	}//end testTheExampleSetValidates()

	/**
	 * The demo register carries three objects of each new schema.
	 *
	 * @return void
	 */
	public function testTheDemoRegisterCarriesThreeOfEach(): void {
		$mock   = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/decidiq_mock_register.json'), true);
		$counts = ['PublicationSubscription' => 0, 'PublicationEvent' => 0];
		foreach ($mock['components']['objects'] as $object) {
			$name = (string)($object['@self']['schema'] ?? '');
			if (array_key_exists($name, $counts) === true) {
				$counts[$name]++;
			}
		}

		self::assertSame(['PublicationSubscription' => 3, 'PublicationEvent' => 3], $counts);
	}//end testTheDemoRegisterCarriesThreeOfEach()

	/**
	 * Validate one example row with Opis against the merged schema; slugs stand in for uuids in seeds.
	 *
	 * @param string              $slug The schema slug
	 * @param array<string,mixed> $row  The example object
	 *
	 * @return void
	 */
	private function assertValid(string $slug, array $row): void {
		$schema = $this->schema($slug);
		unset($row['@self'], $row['slug']);

		$properties = array_map(
			static function (array $property): array {
				unset($property['$ref']);
				if (isset($property['items']) === true) {
					unset($property['items']['$ref'], $property['items']['format']);
				}

				if (($property['format'] ?? '') === 'uuid') {
					unset($property['format']);
				}

				return $property;
			},
			$schema['properties']
		);

		$result = (new Validator())->validate(
			json_decode((string)json_encode($row)),
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'required' => $schema['required'], 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), "{$slug} example must validate: " . json_encode($result->error()?->args()));
	}//end assertValid()
}//end class
