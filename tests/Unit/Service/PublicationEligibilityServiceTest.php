<?php

/**
 * Unit tests for PublicationEligibilityService.
 *
 * Covers the structural publication deny-list: Transcript objects and
 * recording/transcript files can never be published (task 2.7).
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

use OCA\Decidesk\Service\PublicationEligibilityService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublicationEligibilityService.
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
 */
class PublicationEligibilityServiceTest extends TestCase
{

    /**
     * Test that the Transcript schema is structurally denied.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testTranscriptSchemaDenied(): void
    {
        $service = new PublicationEligibilityService();
        self::assertTrue($service->isSchemaDenied('transcript'));
        self::assertFalse($service->isSchemaDenied('decision'));

    }//end testTranscriptSchemaDenied()


    /**
     * Test that recording/transcript files are denied.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testRecordingFilesDenied(): void
    {
        $service = new PublicationEligibilityService();
        self::assertTrue($service->isFileDenied('Decidesk/x/recording.mp3'));
        self::assertTrue($service->isFileDenied('transcript-abc.txt'));
        self::assertTrue($service->isFileDenied('call.wav'));
        self::assertFalse($service->isFileDenied('minutes.pdf'));

    }//end testRecordingFilesDenied()


    /**
     * Test that assertPublishable throws on a denied schema.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testAssertPublishableRefusesTranscript(): void
    {
        $service = new PublicationEligibilityService();
        $this->expectException(\DomainException::class);
        $this->expectExceptionCode(422);
        $service->assertPublishable(schemaSlug: 'transcript');

    }//end testAssertPublishableRefusesTranscript()


    /**
     * Test that assertPublishable throws on a recording file.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testAssertPublishableRefusesRecordingFile(): void
    {
        $service = new PublicationEligibilityService();
        $this->expectException(\DomainException::class);
        $service->assertPublishable(schemaSlug: 'minutes', fileName: 'recording.mp3');

    }//end testAssertPublishableRefusesRecordingFile()


    /**
     * Test that a non-denied schema + file is allowed (no exception).
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/tasks.md#task-5.1
     *
     * @return void
     */
    public function testAssertPublishableAllowsMinutes(): void
    {
        $service = new PublicationEligibilityService();
        $service->assertPublishable(schemaSlug: 'minutes', fileName: 'minutes.pdf');
        self::assertTrue(true);

    }//end testAssertPublishableAllowsMinutes()
}//end class
