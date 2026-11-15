<?php

/**
 * The additive schema deltas of ori-adoption (REQ-ORIA-002), read through the real fragment merger.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\Settings\RegisterFragmentMerger;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The merged register carries every delta, and existing and new values both validate.
 *
 * @covers \OCA\Decidiq\Service\Settings\RegisterFragmentMerger
 */
final class OriAdoptionRegisterTest extends TestCase {

	/**
	 * The settings directory.
	 *
	 * @var string
	 */
	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * The merged schemas, as the app imports them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $schemas = [];

	/**
	 * Merge the base register and every fragment once, with the production merger.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$base = json_decode((string)file_get_contents(self::SETTINGS . 'decidesk_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->mergeDirectory(configData: $base, fragmentDir: self::SETTINGS . 'register.d');
		self::$schemas = $merged['components']['schemas'];
	}//end setUpBeforeClass()

	/**
	 * Validation errors of an object against one merged schema's properties (empty = valid).
	 *
	 * @param array<string, mixed> $data   The object.
	 * @param string               $schema The schema name.
	 *
	 * @return string The errors.
	 */
	private function errors(array $data, string $schema): string {
		$properties = [];
		foreach (array_keys($data) as $key) {
			$property = self::$schemas[$schema]['properties'][$key];
			unset($property['$ref']);
			if (isset($property['items']['$ref']) === true) {
				unset($property['items']['$ref']);
			}

			$properties[$key] = $property;
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties]))
		);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode((new ErrorFormatter())->format($result->error()));
	}//end errors()

	/**
	 * The new enum values are accepted and the existing ones still are, each listed once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md#scenario-new-enum-values-are-accepted
	 */
	public function testNewEnumValuesAreAcceptedBesideTheOldOnes(): void {
		$this->assertSame('', $this->errors(data: ['meetingType' => 'informational-session', 'lifecycle' => 'cancelled'], schema: 'Meeting'));
		$this->assertSame('', $this->errors(data: ['meetingType' => 'regular', 'lifecycle' => 'scheduled'], schema: 'Meeting'));
		$this->assertSame('', $this->errors(data: ['bodyType' => 'political-group', 'seatCount' => 9, 'coalitionRole' => 'coalition'], schema: 'GovernanceBody'));
		$this->assertSame('', $this->errors(data: ['bodyType' => 'legislative'], schema: 'GovernanceBody'));
		$this->assertNotSame('', $this->errors(data: ['coalitionRole' => 'coalitiepartij'], schema: 'GovernanceBody'), 'A Dutch value is translated on import, never stored.');

		foreach ([['Meeting', 'meetingType'], ['Meeting', 'lifecycle'], ['GovernanceBody', 'bodyType']] as [$schema, $property]) {
			$enum = self::$schemas[$schema]['properties'][$property]['enum'];
			$this->assertSame(array_values(array_unique($enum)), $enum, $schema . '.' . $property . ' lists each value once.');
		}
	}//end testNewEnumValuesAreAcceptedBesideTheOldOnes()

	/**
	 * The new properties exist with their declared shapes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function testTheNewPropertiesAreDeclared(): void {
		$this->assertSame('digital-document', self::$schemas['AgendaItem']['properties']['documents']['items']['$ref']);
		$this->assertSame('uri', self::$schemas['DigitalDocument']['properties']['url']['format']);
		$this->assertArrayHasKey('classification', self::$schemas['DigitalDocument']['properties']);
		$this->assertArrayHasKey('fileName', self::$schemas['DigitalDocument']['properties']);
		$this->assertSame('decision', self::$schemas['VotingRound']['properties']['subjectDecision']['$ref']);

		$this->assertSame(
			'',
			$this->errors(
				data: ['partyResults' => [['party' => 'Groen Links', 'value' => 'for', 'seatCount' => 7], ['party' => 'VVD', 'value' => 'against', 'seatCount' => 6]]],
				schema: 'VotingRound'
			)
		);
		$this->assertNotSame('', $this->errors(data: ['partyResults' => [['party' => 'VVD', 'value' => 'voor']]], schema: 'VotingRound'));
		$this->assertNotSame('', $this->errors(data: ['url' => 'not a uri'], schema: 'DigitalDocument'));
	}//end testTheNewPropertiesAreDeclared()

	/**
	 * Every schema the import writes carries the externalReference tag it writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function testEverySchemaTheImportWritesCarriesTheImportTag(): void {
		foreach (['GovernanceBody', 'Person', 'Membership', 'Meeting', 'DigitalDocument', 'AgendaItem', 'Decision', 'VotingRound'] as $schema) {
			$this->assertSame('string', self::$schemas[$schema]['properties']['externalReference']['type'], $schema);
			$this->assertSame('', $this->errors(data: ['externalReference' => 'ori:3f2a'], schema: $schema), $schema);
		}
	}//end testEverySchemaTheImportWritesCarriesTheImportTag()

	/**
	 * Nothing existing was removed: every base property of the five schemas is still there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md#scenario-existing-objects-remain-valid
	 */
	public function testNoPropertyWasRemoved(): void {
		$base = json_decode((string)file_get_contents(self::SETTINGS . 'decidesk_register.json'), true)['components']['schemas'];
		foreach (['Meeting', 'AgendaItem', 'DigitalDocument', 'VotingRound', 'GovernanceBody'] as $schema) {
			$this->assertSame([], array_diff(array_keys($base[$schema]['properties']), array_keys(self::$schemas[$schema]['properties'])), $schema);
		}
	}//end testNoPropertyWasRemoved()
}//end class
