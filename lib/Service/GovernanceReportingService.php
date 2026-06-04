<?php
/**
 * Decidesk Governance Reporting Service
 *
 * Generates Code-mandated annual governance statistics (attendance,
 * independence ratio, meeting frequency, conflict trends) and compliance flags.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Computes annual governance statistics and Code compliance flags.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
 */
class GovernanceReportingService
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Minimum meetings per year per the Code (illustrative default).
     *
     * @var int
     */
    private const MIN_MEETINGS_PER_YEAR = 4;

    /**
     * Minimum independence ratio per the Code (majority independent).
     *
     * @var float
     */
    private const MIN_INDEPENDENCE_RATIO = 0.5;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
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
     * Normalise an object-or-array into an associative array.
     *
     * @param mixed $item ObjectEntity or array.
     *
     * @return array<string,mixed>
     */
    private function serialize(mixed $item): array
    {
        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        if (is_array($item) === true) {
            return $item;
        }

        return [];

    }//end serialize()

    /**
     * Fetch all objects of a schema as arrays.
     *
     * @param string              $schema  Schema slug.
     * @param array<string,mixed> $filters Optional filters.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(string $schema, array $filters=[]): array
    {
        $objectService = $this->objectService();
        $objectService->setRegister(register: self::REGISTER);
        $objectService->setSchema(schema: $schema);
        $config = [];
        if ($filters !== []) {
            $config = ['filters' => $filters];
        }

        $result  = $objectService->findAll(config: $config);
        $objects = [];
        foreach (($result['results'] ?? $result) as $item) {
            $objects[] = $this->serialize(item: $item);
        }

        return $objects;

    }//end fetchAll()

    /**
     * Compute the independence ratio for a set of board members.
     *
     * @param array<int,array<string,mixed>> $members Board members as arrays.
     *
     * @return float Ratio in [0,1]; 0 when there are no members.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     */
    public function independenceRatio(array $members): float
    {
        $total = count($members);
        if ($total === 0) {
            return 0.0;
        }

        $independent = 0;
        foreach ($members as $member) {
            if (($member['independenceStatus'] ?? '') === 'independent') {
                $independent++;
            }
        }

        return ($independent / $total);

    }//end independenceRatio()

    /**
     * Run compliance checks over computed statistics.
     *
     * @param array<string,mixed> $stats Computed statistics.
     *
     * @return array{passed:bool,flags:array<int,string>}
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     */
    public function complianceFlagCheck(array $stats): array
    {
        $flags = [];
        if ((int) ($stats['meetingCount'] ?? 0) < self::MIN_MEETINGS_PER_YEAR) {
            $flags[] = 'insufficient-meeting-frequency';
        }

        if ((float) ($stats['independenceRatio'] ?? 0.0) < self::MIN_INDEPENDENCE_RATIO) {
            $flags[] = 'low-independence-ratio';
        }

        return ['passed' => ($flags === []), 'flags' => $flags];

    }//end complianceFlagCheck()

    /**
     * Generate an annual governance report for a board and year.
     *
     * @param string $boardId The Board UUID.
     * @param int    $year    The reporting year.
     *
     * @return array<string,mixed> The report payload.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     */
    public function generateAnnualReport(string $boardId, int $year): array
    {
        $members  = $this->fetchAll(schema: 'board-member', filters: ['relations.board' => $boardId]);
        $meetings = $this->fetchAll(schema: 'board-meeting', filters: ['relations.board' => $boardId]);

        $yearMeetings = array_values(
            array_filter(
                $meetings,
                static function (array $meeting) use ($year): bool {
                    return str_starts_with((string) ($meeting['meetingDate'] ?? ''), (string) $year);
                }
            )
        );

        $resolutions = $this->fetchAll(schema: 'resolution');
        $adopted     = 0;
        foreach ($resolutions as $resolution) {
            if (($resolution['status'] ?? '') === 'adopted'
                && str_starts_with((string) ($resolution['adoptionDate'] ?? ''), (string) $year) === true
            ) {
                $adopted++;
            }
        }

        $conflicts     = $this->fetchAll(schema: 'conflict-of-interest');
        $materialCount = 0;
        foreach ($conflicts as $conflict) {
            if (($conflict['severity'] ?? '') === 'material'
                && str_starts_with((string) ($conflict['declarationTimestamp'] ?? ''), (string) $year) === true
            ) {
                $materialCount++;
            }
        }

        $stats = [
            'board'              => $boardId,
            'year'               => $year,
            'meetingCount'       => count($yearMeetings),
            'memberCount'        => count($members),
            'independenceRatio'  => $this->independenceRatio(members: $members),
            'resolutionsAdopted' => $adopted,
            'materialConflicts'  => $materialCount,
        ];

        $stats['compliance'] = $this->complianceFlagCheck(stats: $stats);

        return $stats;

    }//end generateAnnualReport()
}//end class
