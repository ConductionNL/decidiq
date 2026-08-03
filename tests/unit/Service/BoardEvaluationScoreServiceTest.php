<?php

/**
 * Unit tests for BoardEvaluationScoreService.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\BoardEvaluationScoreService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests per-dimension mean, overall weighted score, small-body suppression
 * (below / at-and-above the threshold boundary), free-text theme grouping,
 * and the anonymity invariant (no member identity recoverable from response
 * content; completion tracking is independent of content).
 *
 * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
 */
class BoardEvaluationScoreServiceTest extends TestCase
{

    /**
     * DI container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Service under test.
     *
     * @var BoardEvaluationScoreService
     */
    private BoardEvaluationScoreService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->service    = new BoardEvaluationScoreService(
            container: $this->container,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Build a response payload with the given per-dimension Likert answers.
     *
     * @param array<string, int> $likertByDimension Dimension => Likert value
     * @param array<string, string> $freeTextByDimension Dimension => free-text answer
     *
     * @return array<string, mixed>
     */
    private function response(array $likertByDimension, array $freeTextByDimension=[]): array
    {
        $answers = [];
        foreach ($likertByDimension as $dimension => $value) {
            $answers[] = ['questionId' => $dimension.'-likert', 'dimension' => $dimension, 'likertValue' => $value];
        }

        foreach ($freeTextByDimension as $dimension => $text) {
            $answers[] = ['questionId' => $dimension.'-text', 'dimension' => $dimension, 'freeText' => $text];
        }

        return ['answers' => $answers];

    }//end response()

    /**
     * Per-dimension mean is the average of that dimension's Likert answers
     * across all responses.
     *
     * @return void
     *
     * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
     */
    public function testPerDimensionMean(): void
    {
        $responses = [
            $this->response(['strategy-and-oversight' => 4, 'board-composition' => 2]),
            $this->response(['strategy-and-oversight' => 2, 'board-composition' => 4]),
            $this->response(['strategy-and-oversight' => 3, 'board-composition' => 3]),
        ];

        $summary = $this->service->computeScoreSummary(responses: $responses, invitedMemberCount: 5, minRespondents: 3);

        self::assertTrue($summary['thresholdMet']);
        self::assertSame(3.0, $summary['dimensionScores']['strategy-and-oversight']);
        self::assertSame(3.0, $summary['dimensionScores']['board-composition']);

    }//end testPerDimensionMean()

    /**
     * The overall score is the equal-weight mean across dimension means.
     *
     * @return void
     *
     * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
     */
    public function testOverallWeightedScore(): void
    {
        $responses = [
            $this->response(['strategy-and-oversight' => 5, 'chair-effectiveness' => 3]),
            $this->response(['strategy-and-oversight' => 5, 'chair-effectiveness' => 1]),
            $this->response(['strategy-and-oversight' => 5, 'chair-effectiveness' => 2]),
        ];

        $summary = $this->service->computeScoreSummary(responses: $responses, invitedMemberCount: 3, minRespondents: 3);

        // strategy-and-oversight mean = 5.0, chair-effectiveness mean = 2.0 -> overall = 3.5
        self::assertSame(5.0, $summary['dimensionScores']['strategy-and-oversight']);
        self::assertSame(2.0, $summary['dimensionScores']['chair-effectiveness']);
        self::assertSame(3.5, $summary['overallScore']);

    }//end testOverallWeightedScore()

    /**
     * Below the minimum-respondent threshold, only the aggregate overall
     * score is present — per-dimension and free-text breakdowns are
     * suppressed (not merely hidden: they are null in the materialised
     * structure).
     *
     * @return void
     *
     * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
     */
    public function testThresholdSuppressionBelowBoundary(): void
    {
        $responses = [
            $this->response(['strategy-and-oversight' => 4], ['strategy-and-oversight' => 'More scenario planning please']),
            $this->response(['strategy-and-oversight' => 2], ['strategy-and-oversight' => 'More scenario planning needed']),
        ];

        // 2 respondents, threshold 3 -> below boundary.
        $summary = $this->service->computeScoreSummary(responses: $responses, invitedMemberCount: 7, minRespondents: 3);

        self::assertFalse($summary['thresholdMet']);
        self::assertTrue($summary['suppressed']);
        self::assertNull($summary['dimensionScores']);
        self::assertNull($summary['themes']);
        // The aggregate overall score is still available.
        self::assertSame(3.0, $summary['overallScore']);
        self::assertSame(2, $summary['respondentCount']);

    }//end testThresholdSuppressionBelowBoundary()

    /**
     * At and above the minimum-respondent threshold, breakdowns are shown.
     *
     * @return void
     *
     * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
     */
    public function testThresholdBoundaryAtAndAboveShowsBreakdown(): void
    {
        $responses = [
            $this->response(['strategy-and-oversight' => 4]),
            $this->response(['strategy-and-oversight' => 4]),
            $this->response(['strategy-and-oversight' => 4]),
        ];

        // Exactly at the threshold (3 respondents, threshold 3).
        $summary = $this->service->computeScoreSummary(responses: $responses, invitedMemberCount: 5, minRespondents: 3);

        self::assertTrue($summary['thresholdMet']);
        self::assertFalse($summary['suppressed']);
        self::assertIsArray($summary['dimensionScores']);
        self::assertSame(4.0, $summary['dimensionScores']['strategy-and-oversight']);

        // One more respondent (above threshold) still shows the breakdown.
        $responses[] = $this->response(['strategy-and-oversight' => 4]);
        $summaryAbove = $this->service->computeScoreSummary(responses: $responses, invitedMemberCount: 5, minRespondents: 3);
        self::assertTrue($summaryAbove['thresholdMet']);
        self::assertIsArray($summaryAbove['dimensionScores']);

    }//end testThresholdBoundaryAtAndAboveShowsBreakdown()

    /**
     * Free-text answers are grouped into a lightweight per-dimension theme
     * list (recurring non-stopword tokens).
     *
     * @return void
     *
     * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-004-per-dimension-and-overall-board-effectiveness-scores
     */
    public function testFreeTextThemeGrouping(): void
    {
        $responses = [
            $this->response(['board-dynamics' => 4], ['board-dynamics' => 'meetings meetings meetings run long and dynamics suffer']),
            $this->response(['board-dynamics' => 3], ['board-dynamics' => 'meetings often run long, dynamics need work']),
            $this->response(['board-dynamics' => 3], ['board-dynamics' => 'shorter meetings would help dynamics']),
        ];

        $summary = $this->service->computeScoreSummary(responses: $responses, invitedMemberCount: 3, minRespondents: 3);

        self::assertTrue($summary['thresholdMet']);
        self::assertArrayHasKey('board-dynamics', $summary['themes']);
        $words = array_column($summary['themes']['board-dynamics'], 'word');
        self::assertContains('meetings', $words);
        self::assertContains('dynamics', $words);

    }//end testFreeTextThemeGrouping()

    /**
     * Anonymity invariant: the response payload the scoring service consumes
     * carries no member identity field, and computing the summary never
     * requires or derives one. Completion counting (respondentCount) is
     * independent of any identity — it is simply count(responses).
     *
     * @return void
     *
     * @spec openspec/changes/board-self-evaluation/specs/board-self-evaluation/spec.md#requirement-req-eval-003-responses-are-anonymous-and-untraceable-to-the-member
     */
    public function testAnonymityNoMemberIdentityRecoverable(): void
    {
        $responses = [
            $this->response(['information-quality' => 5]),
            $this->response(['information-quality' => 3]),
            $this->response(['information-quality' => 4]),
        ];

        // None of the response payloads carry a member/participant field.
        foreach ($responses as $response) {
            self::assertArrayNotHasKey('participantId', $response);
            self::assertArrayNotHasKey('nextcloudUserId', $response);
            self::assertArrayNotHasKey('member', $response);
        }

        $summary = $this->service->computeScoreSummary(responses: $responses, invitedMemberCount: 7, minRespondents: 3);

        // Completion (respondentCount) is derived purely from array count —
        // it carries no per-member linkage, and the summary itself contains
        // no identity field anywhere.
        self::assertSame(3, $summary['respondentCount']);
        self::assertStringNotContainsString('participantId', json_encode($summary));
        self::assertStringNotContainsString('nextcloudUserId', json_encode($summary));

    }//end testAnonymityNoMemberIdentityRecoverable()
}//end class
