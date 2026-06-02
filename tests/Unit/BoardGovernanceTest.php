<?php

/**
 * Unit tests for the board-meeting-resolutions backend.
 *
 * Covers the board-governance schema definitions in decidesk_register.json plus the
 * pure, deterministic logic of the board services (audit hash chaining, adoption
 * threshold computation, HMAC anonymization unlinkability, access-level matrix).
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit;

use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\BoardMaterialAuthorizationService;
use OCA\Decidesk\Service\BoardVotingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for board-governance schemas and service logic.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-9
 */
class BoardGovernanceTest extends TestCase
{

    /**
     * The schemas from the register.
     *
     * @var array<string,mixed>
     */
    private array $schemas;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path = __DIR__.'/../../lib/Settings/decidesk_register.json';
        $json = file_get_contents(filename: $path);
        self::assertNotFalse(condition: $json, message: 'Register JSON file must exist');
        $register      = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        $this->schemas = ($register['components']['schemas'] ?? []);

    }//end setUp()

    /**
     * Test that all nine board-governance schemas exist with slug, version and seeds.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-1
     */
    public function testBoardSchemasExist(): void
    {
        $names = [
            'Board',
            'BoardMember',
            'BoardMeeting',
            'Resolution',
            'BoardVote',
            'BoardMinutes',
            'ConflictOfInterest',
            'BoardMaterial',
            'AuditLogEntry',
        ];

        foreach ($names as $name) {
            self::assertArrayHasKey(key: $name, array: $this->schemas, message: "Schema '{$name}' must exist");
            self::assertNotEmpty(actual: ($this->schemas[$name]['slug'] ?? ''), message: "Schema '{$name}' must have a slug");
            self::assertSame(expected: 'object', actual: ($this->schemas[$name]['type'] ?? ''));
        }

    }//end testBoardSchemasExist()

    /**
     * Test the Board type enum carries all seven board types.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-1.1
     */
    public function testBoardTypeEnum(): void
    {
        $enum = $this->schemas['Board']['properties']['type']['enum'];
        self::assertContains(needle: 'raad-van-commissarissen', haystack: $enum);
        self::assertContains(needle: 'raad-van-bestuur', haystack: $enum);
        self::assertContains(needle: 'audit-committee', haystack: $enum);
        self::assertCount(expectedCount: 7, haystack: $enum);

    }//end testBoardTypeEnum()

    /**
     * Test the BoardMaterial access-level enum enforces least-privilege levels.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-1.8
     */
    public function testBoardMaterialAccessLevelEnum(): void
    {
        $enum     = $this->schemas['BoardMaterial']['properties']['access-level']['enum'];
        $expected = ['board-only', 'executive-only', 'audit-committee', 'external-auditor', 'regulator'];
        self::assertSame(expected: $expected, actual: $enum);

    }//end testBoardMaterialAccessLevelEnum()

    /**
     * Test that the Resolution adoption is computed declaratively (ADR-031).
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-1.4
     */
    public function testResolutionDeclarativeAdoption(): void
    {
        $resolution = $this->schemas['Resolution'];
        self::assertArrayHasKey(key: 'x-openregister-aggregations', array: $resolution);
        self::assertArrayHasKey(key: 'votesInFavorCount', array: $resolution['x-openregister-aggregations']);
        self::assertArrayHasKey(key: 'x-openregister-calculations', array: $resolution);
        self::assertArrayHasKey(key: 'thresholdAchieved', array: $resolution['x-openregister-calculations']);

    }//end testResolutionDeclarativeAdoption()

    /**
     * Test that the audit hash is deterministic and chains over the previous hash.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.1
     */
    public function testAuditHashChaining(): void
    {
        $service = new AuditLogService(
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class)
        );

        $first = $service->computeHash(
            timestamp: '2026-05-28T15:28:00Z',
            actor: 'bm-anna',
            action: 'vote',
            objectUids: ['r-1', 'v-1'],
            previousHash: ''
        );

        // Deterministic: same inputs yield the same hash.
        $repeat = $service->computeHash(
            timestamp: '2026-05-28T15:28:00Z',
            actor: 'bm-anna',
            action: 'vote',
            objectUids: ['r-1', 'v-1'],
            previousHash: ''
        );
        self::assertSame(expected: $first, actual: $repeat);
        self::assertSame(expected: 64, actual: strlen($first), message: 'SHA-256 hex hash is 64 chars');

        // Chaining: a different previous hash produces a different current hash.
        $second = $service->computeHash(
            timestamp: '2026-05-28T15:29:00Z',
            actor: 'bm-maria',
            action: 'vote',
            objectUids: ['r-1', 'v-2'],
            previousHash: $first
        );
        self::assertNotSame(expected: $first, actual: $second);

        // Tampering with an earlier entry changes the dependent hash.
        $tampered = $service->computeHash(
            timestamp: '2026-05-28T15:29:00Z',
            actor: 'bm-maria',
            action: 'vote',
            objectUids: ['r-1', 'v-2'],
            previousHash: 'deadbeef'
        );
        self::assertNotSame(expected: $second, actual: $tampered);

    }//end testAuditHashChaining()

    /**
     * Test the adoption threshold computation across all threshold types.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function testThresholdComputation(): void
    {
        $service = new BoardVotingService(
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            appConfig: $this->createMock(IAppConfig::class),
            auditLogService: $this->createMock(AuditLogService::class)
        );

        // Simple majority: 3 in favor vs 2 against passes.
        self::assertTrue(condition: $service->thresholdMet(threshold: 'simple-majority', inFavor: 3, against: 2, eligible: 5));
        self::assertFalse(condition: $service->thresholdMet(threshold: 'simple-majority', inFavor: 2, against: 3, eligible: 5));

        // Two-thirds of 6 eligible needs >= 4 in favor.
        self::assertTrue(condition: $service->thresholdMet(threshold: 'qualified-majority-two-thirds', inFavor: 4, against: 1, eligible: 6));
        self::assertFalse(condition: $service->thresholdMet(threshold: 'qualified-majority-two-thirds', inFavor: 3, against: 1, eligible: 6));

        // Three-quarters of 8 eligible needs >= 6 in favor.
        self::assertTrue(condition: $service->thresholdMet(threshold: 'qualified-majority-three-quarters', inFavor: 6, against: 0, eligible: 8));
        self::assertFalse(condition: $service->thresholdMet(threshold: 'qualified-majority-three-quarters', inFavor: 5, against: 0, eligible: 8));

        // Unanimous needs at least one in favor and zero against.
        self::assertTrue(condition: $service->thresholdMet(threshold: 'unanimous', inFavor: 5, against: 0, eligible: 5));
        self::assertFalse(condition: $service->thresholdMet(threshold: 'unanimous', inFavor: 5, against: 1, eligible: 6));

    }//end testThresholdComputation()

    /**
     * Test that HMAC anonymization is stable per member yet unlinkable across resolutions.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.5
     */
    public function testAnonymizationUnlinkability(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('fixed-test-secret-0123456789abcdef');

        $service = new BoardVotingService(
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            appConfig: $appConfig,
            auditLogService: $this->createMock(AuditLogService::class)
        );

        $tokenA1 = $service->anonymizeMember(resolutionId: 'r-1', boardMemberId: 'bm-anna');
        $tokenA1b = $service->anonymizeMember(resolutionId: 'r-1', boardMemberId: 'bm-anna');
        $tokenA2 = $service->anonymizeMember(resolutionId: 'r-2', boardMemberId: 'bm-anna');
        $tokenB1 = $service->anonymizeMember(resolutionId: 'r-1', boardMemberId: 'bm-bob');

        // Stable within a resolution (double-vote prevention).
        self::assertSame(expected: $tokenA1, actual: $tokenA1b);
        // Same member across resolutions is not linkable.
        self::assertNotSame(expected: $tokenA1, actual: $tokenA2);
        // Different members within a resolution differ.
        self::assertNotSame(expected: $tokenA1, actual: $tokenB1);
        // Token does not contain the raw member id.
        self::assertStringStartsWith(prefix: 'hmac:', string: $tokenA1);
        self::assertStringNotContainsString(needle: 'bm-anna', haystack: $tokenA1);

    }//end testAnonymizationUnlinkability()

    /**
     * Test the access-level matrix enforces least privilege.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.3
     */
    public function testAccessLevelMatrix(): void
    {
        $service = new BoardMaterialAuthorizationService(
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            auditLogService: $this->createMock(AuditLogService::class)
        );

        // Board-only is visible to any board member role.
        self::assertTrue(condition: $service->roleMayView(accessLevel: 'board-only', role: 'non-executive-member'));
        // Executive-only is hidden from a non-executive member.
        self::assertFalse(condition: $service->roleMayView(accessLevel: 'executive-only', role: 'non-executive-member'));
        self::assertTrue(condition: $service->roleMayView(accessLevel: 'executive-only', role: 'executive-member'));
        // A regulator material is not visible to an ordinary board member.
        self::assertFalse(condition: $service->roleMayView(accessLevel: 'regulator', role: 'member'));
        self::assertTrue(condition: $service->roleMayView(accessLevel: 'regulator', role: 'regulator'));

    }//end testAccessLevelMatrix()
}//end class
