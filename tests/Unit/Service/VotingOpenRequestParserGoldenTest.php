<?php

/**
 * Golden runs of VotingOpenRequestParser::parse().
 *
 * Pins the error or the payload for a full request, the defaults, every
 * refusal in the order the parser checks them (missing ids, each rule, the
 * subject type) and the loose and strict value readings. Pinned before
 * parse() was split into named steps.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude golden test of the open-round request parser, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\VotingOpenRequestParser;
use PHPUnit\Framework\TestCase;

final class VotingOpenRequestParserGoldenTest extends TestCase {

	/**
	 * Every request parses the same.
	 *
	 * @return void
	 */
	public function testEveryRequestParsesTheSame(): void {
		$parser = new VotingOpenRequestParser();
		$base = ['motionId' => 'm-1', 'meetingId' => 'mt-1'];
		$cases = [
			'full' => $base + [
				'votingMethod' => 'ranked-choice',
				'isSecret' => 'yes',
				'closedAt' => '2026-01-01T00:00:00Z',
				'presetParticipantIds' => ['p1', 'p2'],
				'voteThreshold' => 'qualified-majority-two-thirds',
				'abstentionHandling' => 'count',
				'tieBreakRule' => 'revote',
				'revoteOfRound' => 'r-0',
				'subjectType' => 'amendment',
				'governanceBody' => 'b-1',
				'options' => [['label' => 'A']],
			],
			'defaults' => $base,
			'empty values' => $base + ['closedAt' => '', 'voteThreshold' => '', 'revoteOfRound' => '', 'governanceBody' => 5, 'presetParticipantIds' => 'p1', 'options' => 'x'],
			'numeric rule' => $base + ['voteThreshold' => 7],
			'no motion' => ['meetingId' => 'mt-1'],
			'no meeting' => ['motionId' => 'm-1', 'meetingId' => ''],
			'bad threshold and tie rule' => $base + ['voteThreshold' => 'most', 'tieBreakRule' => 'coin'],
			'bad abstention' => $base + ['abstentionHandling' => 'ignore'],
			'bad tie rule' => $base + ['tieBreakRule' => 'coin'],
			'bad subject' => $base + ['subjectType' => 'budget'],
			'bad rule beats bad subject' => $base + ['subjectType' => 'budget', 'abstentionHandling' => 'x'],
			'voting group' => $base + ['votingGroup' => 'group-1'],
			'voting group and presets' => $base + ['votingGroup' => 'group-1', 'presetParticipantIds' => ['p1']],
		];
		$runs = [];
		foreach ($cases as $name => $params) {
			$runs[$name] = $parser->parse($params);
		}

		$golden = __DIR__ . '/../../fixtures/voting-open-request-golden.json';
		$json = json_encode($runs, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('DECIDIQ_WRITE_GOLDEN') === '1') {
			file_put_contents($golden, $json);
		}

		self::assertSame(file_get_contents($golden), $json);
		self::assertTrue($runs['full']['payload']['isSecret']);
	}//end testEveryRequestParsesTheSame()
}//end class
