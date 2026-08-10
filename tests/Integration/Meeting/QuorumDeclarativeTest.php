<?php

/**
 * Integration test for quorum declarative schema (chain spec 1 of 3).
 *
 * Verifies that the x-openregister-aggregations (totalParticipantCount,
 * presentParticipantCount) and x-openregister-calculations (quorumPercentage,
 * quorumMet) declared on the Meeting schema materialise correctly when the
 * OpenRegister aggregation engine is available.
 *
 * If the engine is not available in this environment (no live OR install with
 * cross-schema aggregation support), all three test cases skip cleanly via
 * markTestSkipped rather than fail.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Integration\Meeting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Integration\Meeting;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for quorum declarative schema on Meeting.
 *
 * Engine dependency note: these tests require OpenRegister's aggregation engine
 * to support cross-schema filtering via @self.{relation} syntax. If the engine
 * is unavailable or does not yet support cross-schema aggregations, all cases
 * skip with markTestSkipped. See design.md § Engine dependency for the full
 * decision tree.
 *
 * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
 */
class QuorumDeclarativeTest extends TestCase
{

    /**
     * ObjectService instance, or null if OR is not available.
     *
     * @var object|null
     */
    private ?object $objectService = null;

    /**
     * Set up test fixtures; skip suite if ObjectService is not available.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
            $this->markTestSkipped(
                message: 'OpenRegister ObjectService not available — cross-schema aggregation engine not present in this environment.'
            );
        }

        try {
            $this->objectService = \OC::$server->get(\OCA\OpenRegister\Service\ObjectService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                message: 'OpenRegister ObjectService could not be resolved from the DI container: '.$e->getMessage()
            );
        }//end try

    }//end setUp()

    /**
     * Test quorum met when required count is reached.
     *
     * Scenario: Meeting with quorumRequired = 3, 5 Participants in the governance
     * body, 3 of which have attendanceStatus = present.
     * Expected: quorumMet === true, presentParticipantCount === 3,
     * quorumPercentage === 60.
     *
     * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
     *
     * @return void
     */
    public function testQuorumMetWithRequiredAndPresent(): void
    {
        $this->assertNotNull(actual: $this->objectService, message: 'ObjectService must be resolved.');

        $governanceBodyId = $this->createTestGovernanceBody();
        $meetingId        = $this->createTestMeeting(
            governanceBodyId: $governanceBodyId,
            quorumRequired: 3
        );

        $this->createTestParticipants(
            governanceBodyId: $governanceBodyId,
            total: 5,
            presentCount: 3
        );

        $meeting = $this->fetchMeeting(meetingId: $meetingId);

        if (isset($meeting['quorumMet']) === false) {
            $this->markTestSkipped(
                message: 'Cross-schema aggregation engine did not materialise quorumMet — engine support gap.'
            );
        }

        self::assertTrue(
            condition: (bool) $meeting['quorumMet'],
            message: 'quorumMet must be true when 3 present ≥ quorumRequired 3.'
        );
        self::assertSame(
            expected: 3,
            actual: (int) $meeting['presentParticipantCount'],
            message: 'presentParticipantCount must equal 3.'
        );
        self::assertEqualsWithDelta(
            expected: 60.0,
            actual: (float) $meeting['quorumPercentage'],
            delta: 0.01,
            message: 'quorumPercentage must be 60 (3/5 × 100).'
        );

        $this->cleanupTestData(
            meetingId: $meetingId,
            governanceBodyId: $governanceBodyId
        );

    }//end testQuorumMetWithRequiredAndPresent()

    /**
     * Test quorum not met when present count falls below required.
     *
     * Scenario: Meeting with quorumRequired = 3, 5 Participants, 2 present.
     * Expected: quorumMet === false, quorumPercentage === 40.
     *
     * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
     *
     * @return void
     */
    public function testQuorumNotMetBelowRequired(): void
    {
        $this->assertNotNull(actual: $this->objectService, message: 'ObjectService must be resolved.');

        $governanceBodyId = $this->createTestGovernanceBody();
        $meetingId        = $this->createTestMeeting(
            governanceBodyId: $governanceBodyId,
            quorumRequired: 3
        );

        $this->createTestParticipants(
            governanceBodyId: $governanceBodyId,
            total: 5,
            presentCount: 2
        );

        $meeting = $this->fetchMeeting(meetingId: $meetingId);

        if (isset($meeting['quorumMet']) === false) {
            $this->markTestSkipped(
                message: 'Cross-schema aggregation engine did not materialise quorumMet — engine support gap.'
            );
        }

        self::assertFalse(
            condition: (bool) $meeting['quorumMet'],
            message: 'quorumMet must be false when 2 present < quorumRequired 3.'
        );
        self::assertEqualsWithDelta(
            expected: 40.0,
            actual: (float) $meeting['quorumPercentage'],
            delta: 0.01,
            message: 'quorumPercentage must be 40 (2/5 × 100).'
        );

        $this->cleanupTestData(
            meetingId: $meetingId,
            governanceBodyId: $governanceBodyId
        );

    }//end testQuorumNotMetBelowRequired()

    /**
     * Test quorum met when quorumRequired is null (quorum not required).
     *
     * Scenario: Meeting with quorumRequired = null, 5 Participants, 0 present.
     * Expected: quorumMet === true (null branch of the quorumMet expression).
     *
     * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
     *
     * @return void
     */
    public function testQuorumMetWhenNotRequired(): void
    {
        $this->assertNotNull(actual: $this->objectService, message: 'ObjectService must be resolved.');

        $governanceBodyId = $this->createTestGovernanceBody();
        $meetingId        = $this->createTestMeeting(
            governanceBodyId: $governanceBodyId,
            quorumRequired: null
        );

        $this->createTestParticipants(
            governanceBodyId: $governanceBodyId,
            total: 5,
            presentCount: 0
        );

        $meeting = $this->fetchMeeting(meetingId: $meetingId);

        if (isset($meeting['quorumMet']) === false) {
            $this->markTestSkipped(
                message: 'Cross-schema aggregation engine did not materialise quorumMet — engine support gap.'
            );
        }

        self::assertTrue(
            condition: (bool) $meeting['quorumMet'],
            message: 'quorumMet must be true when quorumRequired is null (quorum not required).'
        );

        $this->cleanupTestData(
            meetingId: $meetingId,
            governanceBodyId: $governanceBodyId
        );

    }//end testQuorumMetWhenNotRequired()

    /**
     * Create a test GovernanceBody and return its ID.
     *
     * @return string The created governance body ID.
     */
    private function createTestGovernanceBody(): string
    {
        $body = $this->objectService->runAsSystem(
            fn () => $this->objectService->saveObject(
                object: [
                    'name'     => 'Test Governance Body '.uniqid(prefix: '', more_entropy: true),
                    'bodyType' => 'legislative',
                    'domain'   => 'test',
                ],
                register: 'decidesk',
                schema: 'governance-body',
            )
        );

        if (is_object($body) && method_exists($body, 'jsonSerialize')) {
            $body = $body->jsonSerialize();
        }

        return (string) ($body['id'] ?? $body['uuid'] ?? '');

    }//end createTestGovernanceBody()

    /**
     * Create a test Meeting linked to a governance body.
     *
     * @param string   $governanceBodyId The governance body UUID.
     * @param int|null $quorumRequired   Minimum present count, or null for unrestricted.
     *
     * @return string The created meeting ID.
     */
    private function createTestMeeting(string $governanceBodyId, ?int $quorumRequired): string
    {
        $data = [
            'title'          => 'Test Meeting '.uniqid(prefix: '', more_entropy: true),
            'meetingType'    => 'regular',
            'scheduledDate'  => '2026-01-01T10:00:00Z',
            'meetingMode'    => 'in-person',
            'lifecycle'      => 'opened',
            'governanceBody' => $governanceBodyId,
        ];

        if ($quorumRequired !== null) {
            $data['quorumRequired'] = $quorumRequired;
        }

        $meeting = $this->objectService->runAsSystem(
            fn () => $this->objectService->saveObject(
                object: $data,
                register: 'decidesk',
                schema: 'meeting',
            )
        );

        if (is_object($meeting) && method_exists($meeting, 'jsonSerialize')) {
            $meeting = $meeting->jsonSerialize();
        }

        return (string) ($meeting['id'] ?? $meeting['uuid'] ?? '');

    }//end createTestMeeting()

    /**
     * Create a set of Participants for a governance body, some present.
     *
     * @param string $governanceBodyId The governance body UUID.
     * @param int    $total            Total participants to create.
     * @param int    $presentCount     How many should have attendanceStatus = present.
     *
     * @return void
     */
    private function createTestParticipants(
        string $governanceBodyId,
        int $total,
        int $presentCount
    ): void {
        for ($i = 0; $i < $total; $i++) {
            $status = 'absent';
            if ($i < $presentCount) {
                $status = 'present';
            }

            $this->objectService->runAsSystem(
                fn () => $this->objectService->saveObject(
                    object: [
                        'displayName'      => 'Test Participant '.uniqid(prefix: '', more_entropy: true),
                        'role'             => 'member',
                        'governanceBody'   => $governanceBodyId,
                        'attendanceStatus' => $status,
                    ],
                    register: 'decidesk',
                    schema: 'participant',
                )
            );
        }

    }//end createTestParticipants()

    /**
     * Fetch a Meeting object and return it as an array.
     *
     * @param string $meetingId The meeting UUID.
     *
     * @return array<string,mixed> The meeting data.
     */
    private function fetchMeeting(string $meetingId): array
    {
        $this->objectService->setRegister('decidesk');
        $this->objectService->setSchema('meeting');
        $entity = $this->objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');

        if ($entity === null) {
            return [];
        }

        if (method_exists(object_or_class: $entity, method: 'jsonSerialize') === true) {
            return $entity->jsonSerialize();
        }

        if (is_array(value: $entity) === true) {
            return $entity;
        }

        return [];

    }//end fetchMeeting()

    /**
     * Remove test data created during a test case.
     *
     * @param string $meetingId        The meeting UUID to delete.
     * @param string $governanceBodyId The governance body UUID to delete.
     *
     * @return void
     */
    private function cleanupTestData(string $meetingId, string $governanceBodyId): void
    {
        try {
            $this->objectService->setRegister('decidesk');
            $this->objectService->setSchema('participant');
            $participantEntities = $this->objectService->findAll([
                'filters' => ['governanceBody' => $governanceBodyId, '_limit' => 100],
            ]);
            foreach ($participantEntities as $pEntity) {
                $p = method_exists($pEntity, 'jsonSerialize') ? $pEntity->jsonSerialize() : [];
                $pid = (string) ($p['id'] ?? $p['uuid'] ?? '');
                if ($pid !== '') {
                    // The UUID parameter is named `uuid`, not `id` — this used
                    // to pass `id:` and raise "Unknown named parameter", which
                    // the best-effort catch below swallowed, so cleanup had
                    // never once removed a row (#399).
                    $this->objectService->runAsSystem(
                        fn () => $this->objectService->deleteObject(
                            uuid: $pid,
                            register: 'decidesk',
                            schema: 'participant',
                        )
                    );
                }
            }

            $this->objectService->runAsSystem(
                fn () => $this->objectService->deleteObject(
                    uuid: $meetingId,
                    register: 'decidesk',
                    schema: 'meeting',
                )
            );
            $this->objectService->runAsSystem(
                fn () => $this->objectService->deleteObject(
                    uuid: $governanceBodyId,
                    register: 'decidesk',
                    schema: 'governance-body',
                )
            );
        } catch (\Throwable) {
            // Best-effort cleanup.
        }//end try

    }//end cleanupTestData()
}//end class
