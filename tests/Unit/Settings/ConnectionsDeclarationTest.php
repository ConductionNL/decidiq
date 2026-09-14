<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of decidiq's Integrations page. Nothing in decidiq reads it at runtime,
 * so a broken file fails nowhere in this repo: integriq skips it whole and the
 * page goes empty on some other instance. Every assertion here is a way that
 * file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-001-decidiq-declares-its-outside-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\ConnectionReportService;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against design D2 of connection-registry.
 *
 * The rules mirror integriq's `lib/Settings/connections.schema.json` field for
 * field, including the hydra#673 amendments (`adapter.jsonPath`,
 * `adapter.simulatedValues`, `reportedOnly`). That schema is not a dependency
 * of this repo, so the rules are restated here.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * The fields the schema allows on one connection, with their JSON type.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'key' => 'string',
		'title' => 'string',
		'description' => 'string',
		'order' => 'integer',
		'settingsUrl' => 'string',
		'requiredConfig' => 'array',
		'adapter' => 'array',
		'reportedOnly' => 'boolean',
		'available' => 'boolean',
		'unavailableMessage' => 'string',
		'unconfiguredMessage' => 'string',
		'sourceTemplate' => 'string',
	];

	/**
	 * The fields the schema allows on an adapter block.
	 *
	 * @var array<int, string>
	 */
	private const ADAPTER_FIELDS = ['configKey', 'jsonPath', 'simulatedValues', 'simulatedMessage'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[(string)$connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The file names the app it ships in, and nothing else at the top level.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$declaration = $this->declaration();
		$infoXml = simplexml_load_file($this->root() . '/appinfo/info.xml');

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string)$infoXml->id, actual: $declaration['app']);
		$this->assertSame(expected: ['app', 'connections'], actual: array_keys($declaration));
	}//end testTheFileNamesThisApp()

	/**
	 * The three connections, in order, each key once.
	 *
	 * A row is keyed by app and key, so a second entry with the same key would
	 * overwrite the first, and a renamed key orphans a row.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueAndTheOnesDecidiqReports(): void {
		$keys = array_column($this->declaration()['connections'], 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys);
		$this->assertSame(expected: ['ori', 'eidas', 'translation'], actual: $keys);
	}//end testTheKeysAreUniqueAndTheOnesDecidiqReports()

	/**
	 * Every entry uses only schema fields with the schema's types, and a rising order.
	 *
	 * @return void
	 */
	public function testEveryEntryHasTheShapeIntegriqValidates(): void {
		$previousOrder = 0;
		foreach ($this->declaration()['connections'] as $connection) {
			$key = (string)$connection['key'];

			$this->assertSame(
				expected: [],
				actual: array_diff(array_keys($connection), array_keys(self::FIELD_TYPES)),
				message: $key . ' carries a field the schema does not allow'
			);
			foreach ($connection as $field => $value) {
				$this->assertSame(
					expected: self::FIELD_TYPES[$field],
					actual: $this->jsonType(value: $value),
					message: $key . '.' . $field . ' has the wrong type'
				);
			}

			$this->assertMatchesRegularExpression(pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/', string: $key);
			$this->assertNotSame(expected: '', actual: trim((string)($connection['title'] ?? '')), message: $key . ' has no title');
			$this->assertGreaterThan(expected: $previousOrder, actual: $connection['order'], message: $key . ' breaks the page order');
			$previousOrder = $connection['order'];

			$adapterFields = array_keys(($connection['adapter'] ?? []));
			$this->assertSame(expected: [], actual: array_diff($adapterFields, self::ADAPTER_FIELDS), message: $key . '.adapter');
		}
	}//end testEveryEntryHasTheShapeIntegriqValidates()

	/**
	 * No text a reader sees carries an em-dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: '--', haystack: $this->raw());
	}//end testNoTextCarriesAnEmDash()

	/**
	 * Every settings link lands on a section the admin page really has.
	 *
	 * A link into a section that does not exist opens the page at the top and
	 * logs nothing. Gate 116 checks the same rule on the whole of `src/`.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtAnExistingSection(): void {
		$settingsPage = (string)file_get_contents($this->root() . '/src/views/settings/Settings.vue');
		$linked = [];

		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string)$connection['settingsUrl'];
			$this->assertStringStartsWith(prefix: '/settings/admin/decidiq#section-', string: $url, message: $connection['key']);
			$anchor = substr($url, (strpos($url, '#') + 1));
			$this->assertStringContainsString(
				needle: 'id="' . $anchor . '"',
				haystack: $settingsPage,
				message: $connection['key'] . ' links to a missing section'
			);
			$linked[] = $connection['key'];
		}

		$this->assertSame(expected: ['ori'], actual: $linked);
	}//end testEverySettingsLinkPointsAtAnExistingSection()

	/**
	 * ORI requires the endpoint only, and the endpoint is a key the settings save writes.
	 *
	 * `OriPublicationService::publish()` skips the post without an endpoint and
	 * omits the Authorization header without a secret. Requiring the secret
	 * would keep a working endpoint without auth on Not configured.
	 *
	 * @return void
	 */
	public function testOriRequiresTheEndpointTheSaveWrites(): void {
		$ori = $this->connectionsByKey()['ori'];
		$settingsService = (string)file_get_contents($this->root() . '/lib/Service/SettingsService.php');
		$publisher = (string)file_get_contents($this->root() . '/lib/Service/OriPublicationService.php');

		$this->assertSame(expected: ['ori_endpoint'], actual: $ori['requiredConfig']);
		$this->assertStringContainsString(needle: "'ori_endpoint',", haystack: $settingsService);
		$this->assertStringContainsString(needle: "'ori_endpoint', ''", haystack: $publisher);
		$this->assertArrayNotHasKey(key: 'reportedOnly', array: $ori);
	}//end testOriRequiresTheEndpointTheSaveWrites()

	/**
	 * Signing and translation are reported, never judged from settings.
	 *
	 * A DI binding decides what answers for both. A `requiredConfig` or an
	 * `adapter` block would let integriq guess from values that decide nothing.
	 *
	 * @return void
	 */
	public function testBindingChosenConnectionsAreReportedOnly(): void {
		$byKey = $this->connectionsByKey();

		foreach (['eidas', 'translation'] as $key) {
			$this->assertTrue(condition: $byKey[$key]['reportedOnly'], message: $key);
			$this->assertArrayNotHasKey(key: 'requiredConfig', array: $byKey[$key]);
			$this->assertArrayNotHasKey(key: 'adapter', array: $byKey[$key]);
			$this->assertArrayNotHasKey(key: 'settingsUrl', array: $byKey[$key]);
			$this->assertArrayNotHasKey(key: 'available', array: $byKey[$key], message: $key . ' is called, so it is available');
		}
	}//end testBindingChosenConnectionsAreReportedOnly()

	/**
	 * The reported rows are exactly the ones the report service sends.
	 *
	 * A reportedOnly row nobody reports stays on Not checked yet forever, and a
	 * report for a key the file does not declare is refused by integriq.
	 *
	 * @return void
	 */
	public function testTheReportServiceSendsExactlyTheReportedRows(): void {
		$reported = array_keys(
			array_filter(
				$this->connectionsByKey(),
				static fn (array $connection): bool => ($connection['reportedOnly'] ?? false) === true
			)
		);

		$this->assertSame(expected: ConnectionReportService::REPORTED_KEYS, actual: $reported);
	}//end testTheReportServiceSendsExactlyTheReportedRows()

	/**
	 * A save refreshes every connection with required settings, on at least those keys.
	 *
	 * Integriq decides from `requiredConfig`. A save that changed one of those
	 * keys without a refresh would leave the row stale until the hourly job.
	 *
	 * @return void
	 */
	public function testTheRefreshMapCoversTheRequiredSettings(): void {
		foreach ($this->connectionsByKey() as $key => $connection) {
			if (isset($connection['requiredConfig']) === false) {
				$this->assertArrayNotHasKey(key: $key, array: ConnectionReportService::REFRESH_KEYS);
				continue;
			}

			$this->assertArrayHasKey(key: $key, array: ConnectionReportService::REFRESH_KEYS);
			$this->assertSame(
				expected: [],
				actual: array_diff($connection['requiredConfig'], ConnectionReportService::REFRESH_KEYS[$key]),
				message: $key . ' has a required key a save does not refresh'
			);
		}
	}//end testTheRefreshMapCoversTheRequiredSettings()

	/**
	 * The JSON type name of a decoded value.
	 *
	 * @param mixed $value The decoded value.
	 *
	 * @return string
	 */
	private function jsonType(mixed $value): string {
		return match (true) {
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_string($value) => 'string',
			is_array($value) => 'array',
			default => get_debug_type($value),
		};
	}//end jsonType()
}//end class
