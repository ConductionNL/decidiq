<?php

/**
 * The register carries what a ranked preference round needs (issue #1419).
 *
 * Read through SettingsService::shippedRegisterDescriptor(), the same merge
 * the importer hands to OpenRegister, so a field declared in a register.d
 * fragment counts and a field the importer never sees does not.
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
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Service\VotingOpenRequestParser;
use PHPUnit\Framework\TestCase;

/**
 * Options, ranking and the ranked result on the merged register.
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md
 */
class RankedPreferenceBallotRegisterTest extends TestCase {

	/**
	 * The voting round declares its options and the ranked result, the vote
	 * declares its ranking, and `ranked` is a valid vote value, each with a
	 * title (REQ-PRF-001 to REQ-PRF-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	public function testTheRegisterCarriesTheRankedFields(): void {
		$schemas = SettingsService::shippedRegisterDescriptor()['components']['schemas'];

		foreach (['options', 'rankingResult', 'winningOption'] as $field) {
			self::assertArrayHasKey($field, $schemas['VotingRound']['properties'], "voting-round must declare {$field}");
			self::assertNotSame('', (string)($schemas['VotingRound']['properties'][$field]['title'] ?? ''));
		}

		self::assertArrayHasKey('ranking', $schemas['Vote']['properties'], 'vote must declare ranking');
		self::assertSame(['for', 'against', 'abstain', 'ranked'], $schemas['Vote']['properties']['value']['enum']);
		self::assertContains('ranked-choice', $schemas['VotingRound']['properties']['votingMethod']['enum']);
		self::assertContains('tied', $schemas['VotingRound']['properties']['result']['enum']);
	}//end testTheRegisterCarriesTheRankedFields()

	/**
	 * The open request passes the options through to the round.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	public function testTheOpenRequestCarriesTheOptions(): void {
		$options = [
			['key' => 'renoveren', 'label' => 'Renovate the clubhouse'],
			['key' => 'nieuwbouw', 'label' => 'Build a new clubhouse'],
		];

		$parsed = (new VotingOpenRequestParser())->parse(
			params: ['motionId' => 'motion-1', 'meetingId' => 'meeting-1', 'votingMethod' => 'ranked-choice', 'options' => $options]
		);

		self::assertNull($parsed['error']);
		self::assertSame('ranked-choice', $parsed['payload']['votingMethod']);
		self::assertSame($options, $parsed['payload']['options'] ?? null);
	}//end testTheOpenRequestCarriesTheOptions()
}//end class
