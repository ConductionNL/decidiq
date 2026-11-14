<?php

/**
 * The ori-adoption register fragment: additive deltas only (REQ-ORIA-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OriAdoptionRegisterFragmentTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The base register with every fragment merged, in load order.
	 *
	 * @param bool $withOri Whether to merge the ori-adoption fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function merged(bool $withOri): array {
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$config = json_decode((string)file_get_contents(self::SETTINGS . '/decidesk_register.json'), true);
		$files = glob(self::SETTINGS . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			if ($withOri === false && basename($file) === '89-ori-adoption.json') {
				continue;
			}

			$config = $merge->invoke(null, $config, json_decode((string)file_get_contents($file), true));
		}

		return $config['components']['schemas'];
	}

	public function testTheFragmentIsValidJson(): void {
		json_decode((string)file_get_contents(self::SETTINGS . '/register.d/89-ori-adoption.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->addToAssertionCount(1);
	}

	public function testTheNewEnumValuesAreAddedToTheExistingOnes(): void {
		$before = self::merged(withOri: false);
		$after = self::merged(withOri: true);

		$this->assertSame(
			array_merge($before['Meeting']['properties']['meetingType']['enum'], ['informational-session']),
			$after['Meeting']['properties']['meetingType']['enum']
		);
		$this->assertSame(
			array_merge($before['Meeting']['properties']['lifecycle']['enum'], ['cancelled']),
			$after['Meeting']['properties']['lifecycle']['enum']
		);
		$this->assertSame(
			array_merge($before['GovernanceBody']['properties']['bodyType']['enum'], ['political-group']),
			$after['GovernanceBody']['properties']['bodyType']['enum']
		);
	}

	public function testTheNewPropertiesAreDeclared(): void {
		$after = self::merged(withOri: true);

		$this->assertSame('DigitalDocument', $after['AgendaItem']['properties']['documents']['items']['$ref']);
		$this->assertSame('uri', $after['DigitalDocument']['properties']['url']['format']);
		$this->assertSame('string', $after['DigitalDocument']['properties']['classification']['type']);
		$this->assertSame('string', $after['DigitalDocument']['properties']['fileName']['type']);
		$this->assertSame(
			['party', 'value', 'seatCount'],
			array_keys($after['VotingRound']['properties']['partyResults']['items']['properties'])
		);
		$this->assertSame(['for', 'against', 'abstain'], $after['VotingRound']['properties']['partyResults']['items']['properties']['value']['enum']);
		$this->assertSame('Decision', $after['VotingRound']['properties']['subjectDecision']['$ref']);
		$this->assertSame('integer', $after['GovernanceBody']['properties']['seatCount']['type']);
		$this->assertSame(['coalition', 'opposition'], $after['GovernanceBody']['properties']['coalitionRole']['enum']);

		foreach (['Meeting', 'AgendaItem', 'DigitalDocument', 'VotingRound', 'GovernanceBody', 'Person', 'Membership', 'Decision'] as $schema) {
			$this->assertSame('string', $after[$schema]['properties']['externalReference']['type'], $schema . ' carries the import tag');
		}
	}

	public function testNothingIsRenamedRemovedOrMadeRequired(): void {
		$before = self::merged(withOri: false);
		$after = self::merged(withOri: true);

		$this->assertSame(array_keys($before), array_keys($after));
		foreach ($before as $name => $schema) {
			$this->assertSame($schema['required'] ?? null, $after[$name]['required'] ?? null, $name . ' required list');
			foreach ($schema['properties'] ?? [] as $property => $definition) {
				$this->assertArrayHasKey($property, $after[$name]['properties'], $name . '.' . $property);
				$this->assertSame($definition['type'] ?? null, $after[$name]['properties'][$property]['type'] ?? null, $name . '.' . $property . ' type');
			}
		}
	}
}
