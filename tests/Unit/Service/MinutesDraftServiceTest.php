<?php

/**
 * Unit tests for MinutesDraftService.
 *
 * Covers AI provider-absent state (hides generation), per-agenda-item vs
 * whole-meeting section assembly with provenance, and the suggestion
 * cross-check (match links / no-match unverified flags).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MinutesDraftService;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MinutesDraftService.
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
 */
class MinutesDraftServiceTest extends TestCase
{

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private ContainerInterface $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

    }//end setUp()


    /**
     * Build the service under test.
     *
     * @return MinutesDraftService
     */
    private function service(): MinutesDraftService
    {
        return new MinutesDraftService($this->container, $this->logger);

    }//end service()


    /**
     * Test that AI provider absence hides generation (manager missing from DI).
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testProviderUnavailableWhenManagerAbsent(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('no manager'));
        self::assertFalse($this->service()->isProviderAvailable());

    }//end testProviderUnavailableWhenManagerAbsent()


    /**
     * Test that generate() refuses (503) when no AI provider is available.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testGenerateRefusedWithoutProvider(): void
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('hasProviders')->willReturn(false);
        $this->container->method('get')->with(IManager::class)->willReturn($manager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(503);
        $this->service()->generate(transcriptId: 't1');

    }//end testGenerateRefusedWithoutProvider()


    /**
     * Test per-agenda-item section assembly with provenance on every section.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testBuildSectionsPerAgendaItemWithProvenance(): void
    {
        // MinutesDraftService resolves its section composer from the container;
        // the composer is pure (no collaborators), so wire the real one.
        $this->container->method('get')
            ->with(\OCA\Decidesk\Service\MinutesDraftComposer::class)
            ->willReturn(new \OCA\Decidesk\Service\MinutesDraftComposer());

        $segments = [
            ['agendaItem' => 'A', 'speakerLabel' => 'Speaker 1', 'text' => 'discuss A'],
            ['agendaItem' => 'B', 'speakerLabel' => 'Speaker 1', 'text' => 'discuss B'],
        ];
        $agendaItems = [
            ['id' => 'A', 'title' => 'Budget 2026'],
            ['id' => 'B', 'title' => 'Roadmap'],
        ];

        $runner = static fn (string $prompt): string => 'Samenvatting voor het agendapunt.';

        $sections = $this->service()->buildSections(
            segments: $segments,
            agendaItems: $agendaItems,
            votingRounds: [],
            decisions: [],
            providerId: 'whisper:llm',
            generatedAt: '2026-06-15T00:00:00Z',
            runner: $runner
        );

        self::assertCount(2, $sections);
        self::assertSame('A', $sections[0]['agendaItem']);
        self::assertTrue($sections[0]['provenance']['aiGenerated']);
        self::assertSame('whisper:llm', $sections[0]['provenance']['providerId']);
        self::assertNotSame('', $sections[0]['summary']);

    }//end testBuildSectionsPerAgendaItemWithProvenance()


    /**
     * Test whole-meeting fallback when no timeline alignment exists.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testBuildSectionsFlatFallback(): void
    {
        // MinutesDraftService resolves its section composer from the container;
        // the composer is pure (no collaborators), so wire the real one.
        $this->container->method('get')
            ->with(\OCA\Decidesk\Service\MinutesDraftComposer::class)
            ->willReturn(new \OCA\Decidesk\Service\MinutesDraftComposer());

        $segments = [
            ['speakerLabel' => 'Speaker 1', 'text' => 'whole meeting'],
        ];

        $runner = static fn (string $prompt): string => 'Samenvatting hele vergadering.';

        $sections = $this->service()->buildSections(
            segments: $segments,
            agendaItems: [['id' => 'A', 'title' => 'Budget']],
            votingRounds: [],
            decisions: [],
            providerId: 'p',
            generatedAt: 'now',
            runner: $runner
        );

        self::assertCount(1, $sections);
        self::assertSame('', $sections[0]['agendaItem']);

    }//end testBuildSectionsFlatFallback()


    /**
     * Test the cross-check links a matched decision and flags an unmatched one.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testCrossCheckMatchesAndFlags(): void
    {
        // MinutesDraftService resolves its section composer from the container;
        // the composer is pure (no collaborators), so wire the real one.
        $this->container->method('get')
            ->with(\OCA\Decidesk\Service\MinutesDraftComposer::class)
            ->willReturn(new \OCA\Decidesk\Service\MinutesDraftComposer());

        $summary = 'De raad nam het besluit Woningbouwplan Oost aan. '
            .'Er werd ook iets over een onbekend voorstel gezegd.';

        $decisions = [
            ['id' => 'd1', 'title' => 'Woningbouwplan Oost'],
            ['id' => 'd2', 'title' => 'Niet genoemd besluit'],
        ];

        $suggestions = $this->service()->crossCheck(summary: $summary, votingRounds: [], decisions: $decisions);

        self::assertCount(2, $suggestions);

        $byTitle = [];
        foreach ($suggestions as $suggestion) {
            $byTitle[$suggestion['title']] = $suggestion;
        }

        // Matched decision links to the record and is verified.
        self::assertSame('d1', $byTitle['Woningbouwplan Oost']['linkedId']);
        self::assertFalse($byTitle['Woningbouwplan Oost']['unverified']);

        // Unmatched decision is flagged unverified with no link.
        self::assertSame('', $byTitle['Niet genoemd besluit']['linkedId']);
        self::assertTrue($byTitle['Niet genoemd besluit']['unverified']);

    }//end testCrossCheckMatchesAndFlags()
}//end class
