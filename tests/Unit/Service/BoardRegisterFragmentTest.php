<?php

/**
 * Unit tests asserting the board-meeting-resolutions register fragment is
 * well-formed and merges additively onto the monolith (ADR-037).
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-1.13
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the board fragment adds its schemas without clobbering existing ones.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-1.13
 */
final class BoardRegisterFragmentTest extends TestCase
{

    /**
     * Path to the register fragment.
     *
     * @var string
     */
    private const FRAGMENT = __DIR__.'/../../../lib/Settings/register.d/40-board-meeting-resolutions.json';

    /**
     * Invoke the private static SettingsService::deepMergeConfig().
     *
     * @param array<mixed> $base    Base config.
     * @param array<mixed> $overlay Fragment.
     *
     * @return array<mixed>
     */
    private function merge(array $base, array $overlay): array
    {
        $m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $m->setAccessible(true);
        return $m->invoke(null, $base, $overlay);

    }//end merge()

    /**
     * The fragment is valid JSON and declares the nine board schemas.
     *
     * @return void
     */
    public function testFragmentDeclaresBoardSchemas(): void
    {
        $this->assertFileExists(self::FRAGMENT);
        $data = json_decode((string) file_get_contents(self::FRAGMENT), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        $schemas = array_keys($data['components']['schemas']);
        foreach (['Board', 'BoardMember', 'BoardMeeting', 'Resolution', 'BoardVote', 'BoardMinutes', 'ConflictOfInterest', 'BoardMaterial', 'BoardAuditLogEntry'] as $expected) {
            $this->assertContains($expected, $schemas);
        }

    }//end testFragmentDeclaresBoardSchemas()

    /**
     * Merging the fragment preserves pre-existing schemas (additive union).
     *
     * @return void
     */
    public function testFragmentMergesAdditively(): void
    {
        $base     = ['components' => ['schemas' => ['Meeting' => ['type' => 'object']]]];
        $fragment = json_decode((string) file_get_contents(self::FRAGMENT), true);

        $merged = $this->merge($base, $fragment);
        $this->assertArrayHasKey('Meeting', $merged['components']['schemas']);
        $this->assertArrayHasKey('Resolution', $merged['components']['schemas']);

    }//end testFragmentMergesAdditively()

    /**
     * Every schema carries at least one seed except the audit log (write-only).
     *
     * @return void
     */
    public function testSeedDataPresent(): void
    {
        $data    = json_decode((string) file_get_contents(self::FRAGMENT), true);
        $schemas = $data['components']['schemas'];
        $this->assertGreaterThanOrEqual(3, count($schemas['Board']['x-openregister-seeds']));
        $this->assertGreaterThanOrEqual(10, count($schemas['BoardMember']['x-openregister-seeds']));
        $this->assertGreaterThanOrEqual(10, count($schemas['Resolution']['x-openregister-seeds']));

    }//end testSeedDataPresent()
}//end class
