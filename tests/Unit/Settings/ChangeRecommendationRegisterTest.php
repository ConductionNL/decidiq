<?php

/**
 * The change-recommendation schema in the merged register.
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
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The schema, its lifecycle and who may write it.
 *
 * @coversNothing
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording
 */
class ChangeRecommendationRegisterTest extends TestCase {

	/**
	 * The settings directory.
	 */
	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * The schema as the register merges it.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(): array {
		$files = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === 'change-recommendation') {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * Every property is titled, status defaults to pending, the register lists the schema.
	 *
	 * @return void
	 */
	public function testTheSchemaIsDeclaredAndTitled(): void {
		$schema = $this->mergedSchema();
		self::assertNotSame([], $schema);
		self::assertSame(['motion', 'targetPassage', 'proposedText', 'status'], $schema['required']);
		self::assertSame('decision', $schema['properties']['motion']['$ref']);
		self::assertSame('pending', $schema['properties']['status']['default']);
		self::assertSame(['pending', 'accepted', 'rejected'], $schema['properties']['status']['enum']);
		foreach ($schema['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), $key . ' has a title');
		}

		$listed = [];
		foreach ((glob(self::SETTINGS . 'register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$listed = array_merge($listed, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		self::assertContains('change-recommendation', $listed);
	}//end testTheSchemaIsDeclaredAndTitled()

	/**
	 * A recommendation is decided once: pending to accepted or to rejected, nothing else.
	 *
	 * @return void
	 */
	public function testTheLifecycleOnlyDecidesAPendingRecommendation(): void {
		$lifecycle = $this->mergedSchema()['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('pending', $lifecycle['initial']);
		self::assertSame(['accepted', 'rejected'], $lifecycle['terminal']);
		self::assertSame(
			[['from' => 'pending', 'to' => 'accepted'], ['from' => 'pending', 'to' => 'rejected']],
			$lifecycle['transitions']
		);
	}//end testTheLifecycleOnlyDecidesAPendingRecommendation()

	/**
	 * Only the secretariat and administrators write; signed-in users read.
	 *
	 * @return void
	 */
	public function testOnlyTheSecretariatAndAdministratorsWrite(): void {
		$authorization = $this->mergedSchema()['authorization'];
		foreach (['create', 'update', 'delete'] as $verb) {
			self::assertSame(['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'], $authorization[$verb], $verb);
		}

		self::assertSame(['authenticated'], $authorization['read']);
		self::assertSame(['authenticated'], $authorization['list']);
	}//end testOnlyTheSecretariatAndAdministratorsWrite()

	/**
	 * A well-formed recommendation validates; one without a passage or with an unknown status does not.
	 *
	 * @return void
	 */
	public function testTheSchemaValidatesARecommendation(): void {
		$schema = $this->mergedSchema();
		$properties = $schema['properties'];
		unset($properties['motion']['$ref']);
		$document = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties]));
		$validator = new Validator();

		$valid = ['motion' => '0b7c3c2e-1f1a-4c8e-9a51-3c1d2e4f5a6b', 'lineFrom' => 4, 'lineTo' => 5, 'targetPassage' => 'Een krediet van 640.000 euro', 'proposedText' => 'Een krediet van 645.000 euro', 'status' => 'pending'];
		self::assertTrue($validator->validate(json_decode((string)json_encode($valid)), $document)->isValid());

		$noPassage = array_merge($valid, ['targetPassage' => '']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($noPassage)), $document)->isValid());

		$unknownStatus = array_merge($valid, ['status' => 'maybe']);
		self::assertFalse($validator->validate(json_decode((string)json_encode($unknownStatus)), $document)->isValid());
	}//end testTheSchemaValidatesARecommendation()
}//end class
