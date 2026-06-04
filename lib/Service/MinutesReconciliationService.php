<?php
/**
 * Decidesk Minutes Reconciliation Service
 *
 * Compares the structured content of parallel-language board minutes and reports
 * discrepancies (different resolution counts, missing sections) to the secretary.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Structural reconciliation of multilingual minutes.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
 */
class MinutesReconciliationService
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Extract structural elements from minutes content.
     *
     * Counts resolution references (R-YYYY-NNN) and markdown/HTML headings as a
     * stable, language-agnostic structural fingerprint.
     *
     * @param string $content Minutes content (markdown/HTML/plain).
     *
     * @return array{resolutionCount:int,sectionCount:int,resolutionNumbers:array<int,string>}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
     */
    public function extractStructure(string $content): array
    {
        $resolutionNumbers = [];
        if (preg_match_all('/R-\d{4}-\d{3}/', $content, $matches) > 0) {
            $resolutionNumbers = array_values(array_unique($matches[0]));
        }

        $sectionCount = 0;
        if (preg_match_all('/^#{1,6}\s|<h[1-6][\s>]/mi', $content, $headings) > 0) {
            $sectionCount = count($headings[0]);
        }

        sort($resolutionNumbers);

        return [
            'resolutionCount'   => count($resolutionNumbers),
            'sectionCount'      => $sectionCount,
            'resolutionNumbers' => $resolutionNumbers,
        ];

    }//end extractStructure()

    /**
     * Reconcile two minutes contents and report discrepancies.
     *
     * @param string $contentA First-language content.
     * @param string $contentB Second-language content.
     *
     * @return array{discrepancies:array<int,string>,severity:string}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
     */
    public function reconcileContents(string $contentA, string $contentB): array
    {
        $a = $this->extractStructure(content: $contentA);
        $b = $this->extractStructure(content: $contentB);

        $discrepancies = [];
        if ($a['resolutionCount'] !== $b['resolutionCount']) {
            $discrepancies[] = 'resolution-count-mismatch';
        }

        if ($a['resolutionNumbers'] !== $b['resolutionNumbers']) {
            $discrepancies[] = 'resolution-number-mismatch';
        }

        if ($a['sectionCount'] !== $b['sectionCount']) {
            $discrepancies[] = 'section-count-mismatch';
        }

        $severity = 'ok';
        if (in_array('resolution-count-mismatch', $discrepancies, true) === true
            || in_array('resolution-number-mismatch', $discrepancies, true) === true
        ) {
            $severity = 'error';
        } else if ($discrepancies !== []) {
            $severity = 'warning';
        }

        return ['discrepancies' => $discrepancies, 'severity' => $severity];

    }//end reconcileContents()

    /**
     * Reconcile two persisted minutes records by UUID.
     *
     * @param string $minutesAId First minutes UUID.
     * @param string $minutesBId Second minutes UUID.
     *
     * @return array{discrepancies:array<int,string>,severity:string}
     *
     * @throws \RuntimeException When a minutes record is missing.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.2
     */
    public function reconcile(string $minutesAId, string $minutesBId): array
    {
        $objectService = $this->objectService();
        $minutesA      = $objectService->find(id: $minutesAId, register: self::REGISTER, schema: 'board-minutes');
        $minutesB      = $objectService->find(id: $minutesBId, register: self::REGISTER, schema: 'board-minutes');
        if ($minutesA === null || $minutesB === null) {
            throw new \RuntimeException('Minutes record not found');
        }

        $result = $this->reconcileContents(
            contentA: (string) ($minutesA->jsonSerialize()['content'] ?? ''),
            contentB: (string) ($minutesB->jsonSerialize()['content'] ?? '')
        );

        if ($result['discrepancies'] !== []) {
            $data = $minutesA->jsonSerialize();
            $data['reconciliationNotes'] = implode('; ', $result['discrepancies']);
            $objectService->saveObject(register: self::REGISTER, schema: 'board-minutes', object: $data, uuid: $minutesAId);
        }

        return $result;

    }//end reconcile()
}//end class
