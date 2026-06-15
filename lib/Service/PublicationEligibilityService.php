<?php
/**
 * Decidesk Publication Eligibility Service
 *
 * Single home for the public-publication structural deny-list. Recordings and
 * raw transcripts of a governance meeting are confidential to the body's
 * members and are NEVER eligible for public publication; the approved Minutes
 * are the only public record.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

/**
 * Owns the structural deny-list of schemas/files that can never be published.
 *
 * This change introduces the deny-list as a single, testable home seeded with
 * `Transcript` and recording files (task 2.7). The publish-decisions-via-
 * opencatalogi change extends this same list with its board-governance family
 * (its tasks 2.2/2.3) rather than defining a second copy — the deny-list lives
 * in one place. Construction is intentionally dependency-free so it is cheap to
 * call from any publication payload path.
 *
 * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
 */
class PublicationEligibilityService
{

    /**
     * Schema slugs that are structurally ineligible for public publication.
     *
     * @var string[]
     */
    public const DENIED_SCHEMAS = [
        'transcript',
    ];

    /**
     * File-name patterns (lowercase substrings) treated as confidential
     * recording/transcript artefacts that must never be published.
     *
     * @var string[]
     */
    public const DENIED_FILE_MARKERS = [
        'recording',
        'transcript',
    ];

    /**
     * Audio extensions whose files are confidential recordings.
     *
     * @var string[]
     */
    public const DENIED_FILE_EXTENSIONS = [
        'mp3',
        'wav',
        'm4a',
        'ogg',
        'oga',
        'opus',
        'flac',
        'mka',
        'webm',
    ];

    /**
     * Whether a schema slug is on the structural publication deny-list.
     *
     * @param string $schemaSlug The schema slug (kebab-case, e.g. 'transcript').
     *
     * @return bool True when the schema can never be published.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    public function isSchemaDenied(string $schemaSlug): bool
    {
        return in_array(strtolower(trim($schemaSlug)), self::DENIED_SCHEMAS, true);

    }//end isSchemaDenied()


    /**
     * Whether a file is a confidential recording/transcript artefact.
     *
     * @param string $fileName File name (with extension) or path.
     *
     * @return bool True when the file can never be published.
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    public function isFileDenied(string $fileName): bool
    {
        $lower = strtolower(basename(trim($fileName)));
        if ($lower === '') {
            return false;
        }

        $ext = (string) pathinfo($lower, PATHINFO_EXTENSION);
        if (in_array($ext, self::DENIED_FILE_EXTENSIONS, true) === true) {
            return true;
        }

        foreach (self::DENIED_FILE_MARKERS as $marker) {
            if (str_contains($lower, $marker) === true) {
                return true;
            }
        }

        return false;

    }//end isFileDenied()


    /**
     * Assert that a publication target is eligible, throwing on a denied target.
     *
     * Payload-construction callers invoke this before building any public
     * payload so a denied schema or recording file is refused regardless of
     * status or actor.
     *
     * @param string      $schemaSlug The target schema slug.
     * @param string|null $fileName   Optional target file name/path.
     *
     * @return void
     *
     * @throws \DomainException When the target is on the deny-list (code 422).
     *
     * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
     */
    public function assertPublishable(string $schemaSlug, ?string $fileName=null): void
    {
        if ($this->isSchemaDenied(schemaSlug: $schemaSlug) === true) {
            throw new \DomainException(
                sprintf('Objects of type "%s" are not publishable (structural deny-list).', $schemaSlug),
                422
            );
        }

        if ($fileName !== null && $this->isFileDenied(fileName: $fileName) === true) {
            throw new \DomainException(
                sprintf('File "%s" is a confidential recording/transcript and is not publishable.', $fileName),
                422
            );
        }

    }//end assertPublishable()
}//end class
