<?php

/**
 * Integration tests for declarative quorum fields on Meeting.
 *
 * Validates that `x-openregister-aggregations` (totalParticipantCount,
 * presentParticipantCount) and `x-openregister-calculations` (quorumPercentage,
 * quorumMet) declared in decidesk_register.json materialise correctly at read time
 * when the OpenRegister engine runs the computation.
 *
 * ## Soft-fail contract
 *
 * These tests require a fully provisioned Nextcloud + OpenRegister environment
 * with cross-schema aggregation support (`@self.{relation}` filter). The build
 * container cannot fulfil that requirement (OCC requires root; config.php is not
 * readable). Every test therefore calls `$this->requireLiveEngine()` as its first
 * statement, which invokes `markTestSkipped` when the engine is unavailable.
 *
 * The tests still run (and assert) when `DECIDESK_INTEGRATION=1` is set in the
 * environment, which a properly provisioned CI container should do after importing
 * the register via `occ openregister:configurations:import-app decidesk`.
 *
 * ## Engine-capability spike outcome (task 1)
 *
 * The spike (adding a temporary `spikeParticipantCount` aggregation) was assessed
 * during build. The build container cannot execute `occ` commands or make HTTP
 * queries to a live instance. The spike is therefore marked as "assumed passing"
 * based on the spec author's knowledge of OpenRegister's aggregation engine
 * capability, and the implementation proceeds. If the engine does NOT support
 * cross-schema `@self.{relation}` filters, these tests will fail in a provisioned
 * environment — the failure surface will then be used to file the OR feature
 * request documented in design.md § "Engine dependency".
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Integration\Meeting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Integration\Meeting;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Integration tests verifying declarative quorum materialisation on Meeting objects.
 *
 * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
 */
class QuorumDeclarativeTest extends TestCase
{

    /**
     * DI container — only available when running inside a full NC environment.
     *
     * @var ContainerInterface|null
     */
    private ?ContainerInterface $container = null;


    /**
     * Set up: resolve the DI container if available.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists('\OC') === true && method_exists('\OC', 'getServerContainer') === true) {
            $this->container = \OC::getServerContainer();
        }

    }//end setUp()


    /**
     * Skip the test unless a live OpenRegister engine is available.
     *
     * A live engine is available when:
     * - `DECIDESK_INTEGRATION=1` is set, AND
     * - The DI container can resolve ObjectService.
     *
     * @return void
     */
    private function requireLiveEngine(): void
    {
        if (getenv('DECIDESK_INTEGRATION') !== '1') {
            $this->markTestSkipped(
                'Skipped: set DECIDESK_INTEGRATION=1 and run inside a provisioned NC+OpenRegister environment.'
            );
        }

        if ($this->container === null) {
            $this->markTestSkipped(
                'Skipped: Nextcloud DI container not available — run inside a full NC server environment.'
            );
        }

        if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
            $this->markTestSkipped(
                'Skipped: OCA\\OpenRegister\\Service\\ObjectService not registered — OpenRegister app not active.'
            );
        }

    }//end requireLiveEngine()


    /**
     * Retrieve a live ObjectService instance from the DI container.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

    }//end objectService()


    /**
     * Test quorumMet=true when present count meets quorumRequired.
     *
     * Scenario: Meeting with quorumRequired=3, 5 Participants in the body,
     * 3 of which have attendanceStatus="present".
     * Expected: quorumMet=true, presentParticipantCount=3, quorumPercentage=60.
     *
     * @return void
     *
     * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
     */
    public function testQuorumMetWithRequiredAndPresent(): void
    {
        $this->requireLiveEngine();

        $objectService = $this->objectService();

        // Create a GovernanceBody.
        $body   = $objectService->saveObject(
            register: 'decidesk',
            schema: 'GovernanceBody',
            object: [
                'name'     => 'Test Body Quorum Met',
                'bodyType' => 'legislative',
                'domain'   => 'test',
            ]
        );
        $bodyId = ($body['uuid'] ?? $body['id']);

        // Create 5 Participants — 3 present, 2 absent.
        foreach (['present', 'present', 'present', 'absent', 'absent'] as $i => $status) {
            $objectService->saveObject(
                register: 'decidesk',
                schema: 'Participant',
                object: [
                    'displayName'      => "Participant $i",
                    'role'             => 'member',
                    'attendanceStatus' => $status,
                    'relations'        => [
                        ['register' => 'decidesk', 'schema' => 'GovernanceBody', 'id' => $bodyId],
                    ],
                ]
            );
        }

        // Create a Meeting linked to the body with quorumRequired=3.
        $meeting = $objectService->saveObject(
            register: 'decidesk',
            schema: 'Meeting',
            object: [
                'title'          => 'Integration Test Meeting — Quorum Met',
                'meetingType'    => 'regular',
                'scheduledDate'  => '2026-01-01T10:00:00Z',
                'meetingMode'    => 'in-person',
                'lifecycle'      => 'scheduled',
                'quorumRequired' => 3,
                'relations'      => [
                    ['register' => 'decidesk', 'schema' => 'GovernanceBody', 'id' => $bodyId],
                ],
            ]
        );

        $meetingId = ($meeting['uuid'] ?? $meeting['id']);

        // Re-read the Meeting to trigger materialised calculation.
        $result = $objectService->getObject(register: 'decidesk', schema: 'meeting', uuid: $meetingId);

        self::assertSame(expected: 3, actual: $result['presentParticipantCount'] ?? null, message: 'presentParticipantCount must be 3');
        self::assertSame(expected: 5, actual: $result['totalParticipantCount'] ?? null, message: 'totalParticipantCount must be 5');
        self::assertEqualsWithDelta(expected: 60.0, actual: $result['quorumPercentage'] ?? null, delta: 0.01, message: 'quorumPercentage must be 60');
        self::assertTrue(condition: $result['quorumMet'] ?? false, message: 'quorumMet must be true when presentCount >= quorumRequired');

    }//end testQuorumMetWithRequiredAndPresent()


    /**
     * Test quorumMet=false when present count is below quorumRequired.
     *
     * Scenario: Meeting with quorumRequired=3, 5 Participants in the body,
     * 2 of which have attendanceStatus="present".
     * Expected: quorumMet=false, presentParticipantCount=2, quorumPercentage=40.
     *
     * @return void
     *
     * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
     */
    public function testQuorumNotMetBelowRequired(): void
    {
        $this->requireLiveEngine();

        $objectService = $this->objectService();

        // Create a GovernanceBody.
        $body   = $objectService->saveObject(
            register: 'decidesk',
            schema: 'GovernanceBody',
            object: [
                'name'     => 'Test Body Quorum Not Met',
                'bodyType' => 'legislative',
                'domain'   => 'test',
            ]
        );
        $bodyId = ($body['uuid'] ?? $body['id']);

        // Create 5 Participants — 2 present, 3 absent.
        foreach (['present', 'present', 'absent', 'absent', 'absent'] as $i => $status) {
            $objectService->saveObject(
                register: 'decidesk',
                schema: 'Participant',
                object: [
                    'displayName'      => "Participant $i",
                    'role'             => 'member',
                    'attendanceStatus' => $status,
                    'relations'        => [
                        ['register' => 'decidesk', 'schema' => 'GovernanceBody', 'id' => $bodyId],
                    ],
                ]
            );
        }

        // Create a Meeting with quorumRequired=3.
        $meeting = $objectService->saveObject(
            register: 'decidesk',
            schema: 'Meeting',
            object: [
                'title'          => 'Integration Test Meeting — Quorum Not Met',
                'meetingType'    => 'regular',
                'scheduledDate'  => '2026-01-01T10:00:00Z',
                'meetingMode'    => 'in-person',
                'lifecycle'      => 'scheduled',
                'quorumRequired' => 3,
                'relations'      => [
                    ['register' => 'decidesk', 'schema' => 'GovernanceBody', 'id' => $bodyId],
                ],
            ]
        );

        $meetingId = ($meeting['uuid'] ?? $meeting['id']);

        $result = $objectService->getObject(register: 'decidesk', schema: 'meeting', uuid: $meetingId);

        self::assertSame(expected: 2, actual: $result['presentParticipantCount'] ?? null, message: 'presentParticipantCount must be 2');
        self::assertEqualsWithDelta(expected: 40.0, actual: $result['quorumPercentage'] ?? null, delta: 0.01, message: 'quorumPercentage must be 40');
        self::assertFalse(condition: $result['quorumMet'] ?? true, message: 'quorumMet must be false when presentCount < quorumRequired');

    }//end testQuorumNotMetBelowRequired()


    /**
     * Test quorumMet=true when quorumRequired is null (quorum not enforced).
     *
     * Scenario: Meeting with quorumRequired=null, 5 Participants in the body,
     * none present. Expected: quorumMet=true (null branch of the calculation).
     *
     * @return void
     *
     * @spec openspec/changes/quorum-schema-declaration/tasks.md#task-5
     */
    public function testQuorumMetWhenNotRequired(): void
    {
        $this->requireLiveEngine();

        $objectService = $this->objectService();

        // Create a GovernanceBody.
        $body   = $objectService->saveObject(
            register: 'decidesk',
            schema: 'GovernanceBody',
            object: [
                'name'     => 'Test Body No Quorum Requirement',
                'bodyType' => 'operational',
                'domain'   => 'test',
            ]
        );
        $bodyId = ($body['uuid'] ?? $body['id']);

        // Create 5 Participants — none present.
        for ($i = 0; $i < 5; $i++) {
            $objectService->saveObject(
                register: 'decidesk',
                schema: 'Participant',
                object: [
                    'displayName'      => "Participant $i",
                    'role'             => 'member',
                    'attendanceStatus' => 'absent',
                    'relations'        => [
                        ['register' => 'decidesk', 'schema' => 'GovernanceBody', 'id' => $bodyId],
                    ],
                ]
            );
        }

        // Create a Meeting with no quorumRequired (null).
        $meeting = $objectService->saveObject(
            register: 'decidesk',
            schema: 'Meeting',
            object: [
                'title'         => 'Integration Test Meeting — No Quorum Requirement',
                'meetingType'   => 'regular',
                'scheduledDate' => '2026-01-01T10:00:00Z',
                'meetingMode'   => 'in-person',
                'lifecycle'     => 'scheduled',
                'relations'     => [
                    ['register' => 'decidesk', 'schema' => 'GovernanceBody', 'id' => $bodyId],
                ],
            ]
        );

        $meetingId = ($meeting['uuid'] ?? $meeting['id']);

        $result = $objectService->getObject(register: 'decidesk', schema: 'meeting', uuid: $meetingId);

        self::assertSame(expected: 0, actual: $result['presentParticipantCount'] ?? null, message: 'presentParticipantCount must be 0');
        self::assertTrue(condition: $result['quorumMet'] ?? false, message: 'quorumMet must be true when quorumRequired is null');

    }//end testQuorumMetWhenNotRequired()


}//end class
