<?php

/**
 * Unit tests for EmailReferenceExtractor.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\EmailReferenceExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Tests the stateless decision-reference extraction helper that feeds the
 * email integration leaf's link-suggestion (migrate-email-links-to-email-leaf).
 */
class EmailReferenceExtractorTest extends TestCase
{

    /**
     * The system under test.
     *
     * @var EmailReferenceExtractor
     */
    private EmailReferenceExtractor $extractor;

    /**
     * Set up the extractor before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new EmailReferenceExtractor();
    }//end setUp()

    /**
     * An empty string yields no references.
     *
     * @return void
     */
    public function testEmptyTextReturnsEmptyArray(): void
    {
        self::assertSame(expected: [], actual: $this->extractor->extract(text: ''));
    }//end testEmptyTextReturnsEmptyArray()

    /**
     * A Dutch besluit reference in a subject is extracted.
     *
     * @return void
     */
    public function testExtractsBesluitReference(): void
    {
        self::assertContains(
            needle: 'Besluit-2026-031',
            haystack: $this->extractor->extract(text: 'Re: Besluit-2026-031 begroting')
        );
    }//end testExtractsBesluitReference()

    /**
     * A short "B-YYYY-NN" form is extracted.
     *
     * @return void
     */
    public function testExtractsShortDecisionForm(): void
    {
        self::assertContains(
            needle: 'B-2026-031',
            haystack: $this->extractor->extract(text: 'Vraag over B-2026-031')
        );
    }//end testExtractsShortDecisionForm()

    /**
     * A motie reference is extracted.
     *
     * @return void
     */
    public function testExtractsMotieReference(): void
    {
        self::assertContains(
            needle: 'Motie 2025 01',
            haystack: $this->extractor->extract(text: 'Motie 2025 01 duurzaamheid')
        );
    }//end testExtractsMotieReference()

    /**
     * Duplicate references are de-duplicated.
     *
     * @return void
     */
    public function testDeduplicatesReferences(): void
    {
        self::assertSame(
            expected: ['Besluit-2026-031'],
            actual: $this->extractor->extract(text: 'Besluit-2026-031 ... zie Besluit-2026-031 nogmaals')
        );
    }//end testDeduplicatesReferences()

    /**
     * Text without any recognised reference yields an empty array.
     *
     * @return void
     */
    public function testNoMatchReturnsEmptyArray(): void
    {
        self::assertSame(expected: [], actual: $this->extractor->extract(text: 'Lunch op vrijdag?'));
    }//end testNoMatchReturnsEmptyArray()

    /**
     * Multiple distinct references in one body are all returned.
     *
     * @return void
     */
    public function testExtractsMultipleDistinctReferences(): void
    {
        $result = $this->extractor->extract(text: 'Zie Besluit-2026-031 en Motie-2025-04 in de bijlage.');
        self::assertCount(expectedCount: 2, haystack: $result);
        self::assertContains(needle: 'Besluit-2026-031', haystack: $result);
        self::assertContains(needle: 'Motie-2025-04', haystack: $result);
    }//end testExtractsMultipleDistinctReferences()
}//end class
