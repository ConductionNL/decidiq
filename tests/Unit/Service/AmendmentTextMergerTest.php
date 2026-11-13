<?php

/**
 * Unit tests for AmendmentTextMerger.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/motion-amendment/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentTextMerger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for merging an adopted amendment into its motion's text.
 *
 * @covers \OCA\Decidiq\Service\AmendmentTextMerger
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class AmendmentTextMergerTest extends TestCase {
	public function testReplacesTheTargetPassage(): void {
		$result = (new AmendmentTextMerger())->merge(
			['text' => 'The council funds the library.'],
			['targetPassage' => 'the library', 'proposedText' => 'the new library'],
			'am-1'
		);

		$this->assertSame('The council funds the new library.', $result['text']);
		$this->assertSame('The council funds the library.', $result['originalText']);
		$this->assertCount(1, $result['amendmentHistory']);
		$entry = $result['amendmentHistory'][0];
		$this->assertSame('am-1', $entry['amendment']);
		$this->assertSame('passage', $entry['mode']);
		$this->assertSame('The council funds the library.', $entry['before']);
		$this->assertSame('The council funds the new library.', $entry['after']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $entry['appliedAt']);
	}

	public function testReplacesOnlyTheFirstOccurrenceAndHandlesMultibyteText(): void {
		$result = (new AmendmentTextMerger())->merge(
			['text' => 'één, twee, één'],
			['targetPassage' => 'één', 'proposedText' => 'drie'],
			'am-1'
		);

		$this->assertSame('drie, twee, één', $result['text']);
	}

	public function testAnEmptyProposedTextDeletesThePassage(): void {
		$result = (new AmendmentTextMerger())->merge(
			['text' => 'Keep this, drop this.'],
			['targetPassage' => ' drop this', 'proposedText' => ''],
			'am-1'
		);

		$this->assertSame('Keep this,.', $result['text']);
	}

	public function testWithoutPassageTheProposedTextReplacesEverything(): void {
		$result = (new AmendmentTextMerger())->merge(['text' => 'Old'], ['proposedText' => 'New'], 'am-1');

		$this->assertSame('New', $result['text']);
		$this->assertSame('full', $result['amendmentHistory'][0]['mode']);
	}

	public function testWithoutPassageOrProposedTextTheAmendmentTextIsUsed(): void {
		$result = (new AmendmentTextMerger())->merge(['text' => 'Old'], ['text' => 'From amendment'], 'am-1');

		$this->assertSame('From amendment', $result['text']);
	}

	public function testRefusesAnAmendmentWithoutReplacementText(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Amendment am-9 carries no replacement text');
		(new AmendmentTextMerger())->merge(['text' => 'Old'], [], 'am-9');
	}

	public function testRefusesAPassageThatDoesNotOccur(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Amendment am-9: the passage to replace does not occur in the motion text');
		(new AmendmentTextMerger())->merge(['text' => 'Old'], ['targetPassage' => 'missing', 'proposedText' => 'x'], 'am-9');
	}

	public function testKeepsAnExistingOriginalTextAndAppendsHistory(): void {
		$motion = [
			'text' => 'Second',
			'originalText' => 'First',
			'amendmentHistory' => ['k' => ['amendment' => 'am-1', 'mode' => 'full']],
		];

		$result = (new AmendmentTextMerger())->merge($motion, ['proposedText' => 'Third'], 'am-2');

		$this->assertSame('First', $result['originalText']);
		$this->assertSame(['am-1', 'am-2'], array_column($result['amendmentHistory'], 'amendment'));
		$this->assertSame([0, 1], array_keys($result['amendmentHistory']));
	}

	public function testAnAlreadyAppliedAmendmentReturnsNull(): void {
		$motion = ['text' => 'x', 'amendmentHistory' => ['junk', ['amendment' => 'am-1']]];

		$this->assertNull((new AmendmentTextMerger())->merge($motion, ['targetPassage' => 'nope'], 'am-1'));
	}

	public function testAMissingMotionTextIsTreatedAsEmpty(): void {
		$result = (new AmendmentTextMerger())->merge([], ['proposedText' => 'New'], 'am-1');

		$this->assertSame('', $result['originalText']);
		$this->assertSame('', $result['amendmentHistory'][0]['before']);
	}
}
