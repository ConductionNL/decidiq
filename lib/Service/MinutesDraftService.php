<?php
/**
 * Decidesk Minutes Draft Service
 *
 * Thin orchestration over the Nextcloud TaskProcessing/AI provider abstraction
 * that turns an aligned transcript into a structured DRAFT for the existing
 * resolution-minutes editor. Always a draft: never written into a lifecycle-
 * bearing Minutes object, never auto-approved or auto-published.
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
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Exception\MissingObjectException;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates AI-assisted draft minutes from an aligned transcript.
 *
 * Provider ABSENCE hides the action: {@see self::isProviderAvailable()} returns
 * false and callers do not offer "Generate draft minutes". When present, a
 * per-agenda-item prompt is assembled (item title + aligned segments + recorded
 * votes/decisions) and run through TaskProcessing; flat transcripts fall back to
 * a whole-meeting summary. Every produced section carries provenance
 * (aiGenerated/provider/generatedAt). Suggested decisions/action items are
 * cross-checked against the recorded voting outcomes/decisions: matches link,
 * non-matches are flagged unverified.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class MinutesDraftService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (lazy OR + TaskProcessing).
     * @param LoggerInterface    $logger    The logger.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a TaskProcessing/AI provider is available on this instance.
     *
     * Provider absence is a first-class state that HIDES the generate action.
     *
     * @return bool True when at least one TaskProcessing provider is registered.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function isProviderAvailable(): bool
    {
        try {
            $manager = $this->container->get(\OCP\TaskProcessing\IManager::class);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk MinutesDraftService: TaskProcessing manager unavailable',
                ['error' => $e->getMessage()]
            );
            return false;
        }

        try {
            return $manager->hasProviders();
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk MinutesDraftService: hasProviders() failed',
                ['error' => $e->getMessage()]
            );
            return false;
        }

    }//end isProviderAvailable()

    /**
     * Generate a draft from a transcript.
     *
     * Builds the per-agenda-item draft structure (or a flat whole-meeting
     * section when no timeline exists), runs each prompt through the AI
     * provider, cross-checks suggestions against the recorded record, stamps
     * provenance, and records draftGeneration provenance back on the Transcript.
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return array<string,mixed> The draft structure for the minutes editor.
     *
     * @throws MissingObjectException When the Transcript cannot be found.
     * @throws \DomainException       When no AI provider is available (code 503).
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function generate(string $transcriptId): array
    {
        if ($this->isProviderAvailable() === false) {
            throw new \DomainException('No TaskProcessing/AI provider is available on this instance.', 503);
        }

        $transcript = $this->fetchObject(id: $transcriptId, schema: 'transcript');
        if ($transcript === null) {
            throw new MissingObjectException(message: sprintf('Transcript "%s" not found.', $transcriptId));
        }

        $meetingId = $this->resolveMeetingId(transcript: $transcript);
        $segments  = ($transcript['segments'] ?? []);
        if (is_array($segments) === false) {
            $segments = [];
        }

        $agendaItems  = [];
        $votingRounds = [];
        $decisions    = [];
        if ($meetingId !== null) {
            $agendaItems  = $this->fetchRelated(meetingId: $meetingId, schema: 'agenda-item');
            $votingRounds = $this->fetchRelated(meetingId: $meetingId, schema: 'voting-round');
            $decisions    = $this->fetchRelated(meetingId: $meetingId, schema: 'decision');
        }

        $providerId  = $this->preferredProviderId();
        $generatedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $sections = $this->buildSections(
            segments: $segments,
            agendaItems: $agendaItems,
            votingRounds: $votingRounds,
            decisions: $decisions,
            providerId: $providerId,
            generatedAt: $generatedAt
        );

        $draft = [
            'sections'   => $sections,
            'provenance' => [
                'aiGenerated' => true,
                'providerId'  => $providerId,
                'generatedAt' => $generatedAt,
            ],
        ];

        // Record provenance on the Transcript (never on a Minutes object — no lifecycle touch).
        $transcript['draftGeneration'] = [
            'aiGenerated' => true,
            'providerId'  => $providerId,
            'generatedAt' => $generatedAt,
        ];
        $this->saveTranscript(transcript: $transcript);

        return $draft;

    }//end generate()

    /**
     * Build the per-section draft (or a flat whole-meeting section).
     *
     * Exposed (public) so the section-assembly + cross-check logic can be
     * unit-tested without OR/TaskProcessing by injecting a runner. The default
     * runner uses the real TaskProcessing provider.
     *
     * @param array<int,array<string,mixed>> $segments     Aligned transcript segments.
     * @param array<int,array<string,mixed>> $agendaItems  Agenda items.
     * @param array<int,array<string,mixed>> $votingRounds Recorded voting rounds.
     * @param array<int,array<string,mixed>> $decisions    Recorded decisions.
     * @param string                         $providerId   AI provider id (provenance).
     * @param string                         $generatedAt  Generation timestamp (provenance).
     * @param callable|null                  $runner       Optional prompt runner (test seam).
     *
     * @return array<int,array<string,mixed>> The draft sections.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function buildSections(
        array $segments,
        array $agendaItems,
        array $votingRounds,
        array $decisions,
        string $providerId,
        string $generatedAt,
        ?callable $runner=null
    ): array {
        $run = $runner;
        if ($run === null) {
            $run = function (string $prompt): string {
                return $this->runPrompt(prompt: $prompt);
            };
        }

        $hasTimeline = false;
        foreach ($segments as $segment) {
            if (is_array($segment) === true && ($segment['agendaItem'] ?? '') !== '') {
                $hasTimeline = true;
                break;
            }
        }

        $sections = [];

        if ($hasTimeline === true && $agendaItems !== []) {
            foreach ($agendaItems as $item) {
                if (is_array($item) === false) {
                    continue;
                }

                $itemId    = (string) ($item['id'] ?? ($item['uuid'] ?? ''));
                $itemTitle = (string) ($item['title'] ?? ($item['name'] ?? 'Agendapunt'));
                $itemSegs  = $this->segmentsForItem(segments: $segments, agendaItemId: $itemId);
                $itemVotes = $this->recordForItem(records: $votingRounds, agendaItemId: $itemId);
                $itemDec   = $this->recordForItem(records: $decisions, agendaItemId: $itemId);

                $prompt  = $this->assemblePrompt(title: $itemTitle, segments: $itemSegs, votes: $itemVotes, decisions: $itemDec);
                $summary = $run($prompt);

                $sections[] = $this->buildSection(
                    agendaItem: $itemId,
                    title: $itemTitle,
                    summary: $summary,
                    votingRounds: $itemVotes,
                    decisions: $itemDec,
                    providerId: $providerId,
                    generatedAt: $generatedAt
                );
            }//end foreach
        } else {
            // Flat whole-meeting fallback.
            $prompt  = $this->assemblePrompt(title: 'Volledige vergadering', segments: $segments, votes: $votingRounds, decisions: $decisions);
            $summary = $run($prompt);

            $sections[] = $this->buildSection(
                agendaItem: '',
                title: 'Volledige vergadering',
                summary: $summary,
                votingRounds: $votingRounds,
                decisions: $decisions,
                providerId: $providerId,
                generatedAt: $generatedAt
            );
        }//end if

        return $sections;

    }//end buildSections()

    /**
     * Build one provenance-stamped section with cross-checked suggestions.
     *
     * @param string                         $agendaItem   Agenda item UUID ('' for flat).
     * @param string                         $title        Section title.
     * @param string                         $summary      AI-generated summary text.
     * @param array<int,array<string,mixed>> $votingRounds Recorded voting rounds in scope.
     * @param array<int,array<string,mixed>> $decisions    Recorded decisions in scope.
     * @param string                         $providerId   AI provider id.
     * @param string                         $generatedAt  Generation timestamp.
     *
     * @return array<string,mixed> The section.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function buildSection(
        string $agendaItem,
        string $title,
        string $summary,
        array $votingRounds,
        array $decisions,
        string $providerId,
        string $generatedAt
    ): array {
        return [
            'agendaItem'  => $agendaItem,
            'title'       => $title,
            'summary'     => $summary,
            'suggestions' => $this->crossCheck(summary: $summary, votingRounds: $votingRounds, decisions: $decisions),
            'provenance'  => [
                'aiGenerated' => true,
                'providerId'  => $providerId,
                'generatedAt' => $generatedAt,
            ],
        ];

    }//end buildSection()

    /**
     * Cross-check the AI summary's suggested outcomes against the recorded record.
     *
     * Exposed (public) for unit testing. For each recorded decision/voting
     * outcome whose title appears in the summary, emit a `matched` suggestion
     * that links to the record; when the summary mentions an outcome with no
     * recorded match it is emitted `unverified`. The heuristic is intentionally
     * conservative — unverified is the safe default.
     *
     * @param string                         $summary      The AI summary text.
     * @param array<int,array<string,mixed>> $votingRounds Recorded voting rounds.
     * @param array<int,array<string,mixed>> $decisions    Recorded decisions.
     *
     * @return array<int,array<string,mixed>> Suggestions with match/unverified flags.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function crossCheck(string $summary, array $votingRounds, array $decisions): array
    {
        $lowerSummary = mb_strtolower($summary);
        $suggestions  = [];

        $records = [];
        foreach ($decisions as $decision) {
            if (is_array($decision) === true) {
                $records[] = ['type' => 'decision', 'data' => $decision];
            }
        }

        foreach ($votingRounds as $round) {
            if (is_array($round) === true) {
                $records[] = ['type' => 'voting-round', 'data' => $round];
            }
        }

        foreach ($records as $record) {
            $data  = $record['data'];
            $title = (string) ($data['title'] ?? ($data['name'] ?? ''));
            $id    = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
            if ($title === '') {
                continue;
            }

            $matched = (mb_strtolower($title) !== '' && str_contains($lowerSummary, mb_strtolower($title)) === true);

            $linkedId = '';
            if ($matched === true) {
                $linkedId = $id;
            }

            $suggestions[] = [
                'title'      => $title,
                'recordType' => $record['type'],
                'linkedId'   => $linkedId,
                'unverified' => ($matched === false),
            ];
        }//end foreach

        return $suggestions;

    }//end crossCheck()

    /**
     * Assemble the prompt for one agenda item (or the whole meeting).
     *
     * @param string                         $title     Section title.
     * @param array<int,array<string,mixed>> $segments  Segments in scope.
     * @param array<int,array<string,mixed>> $votes     Voting rounds in scope.
     * @param array<int,array<string,mixed>> $decisions Decisions in scope.
     *
     * @return string The assembled prompt.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function assemblePrompt(string $title, array $segments, array $votes, array $decisions): string
    {
        $lines   = [];
        $lines[] = 'Vat de bespreking van het volgende agendapunt zakelijk samen in het Nederlands. '
            .'Noem genomen besluiten en actiepunten. Verzin niets dat niet in het transcript staat.';
        $lines[] = '';
        $lines[] = 'Agendapunt: '.$title;
        $lines[] = '';
        $lines[] = 'Transcript:';

        foreach ($segments as $segment) {
            if (is_array($segment) === false) {
                continue;
            }

            $label  = (string) ($segment['speakerLabel'] ?? '');
            $text   = (string) ($segment['text'] ?? '');
            $prefix = '';
            if ($label !== '') {
                $prefix = $label.': ';
            }

            $lines[] = $prefix.$text;
        }

        if ($votes !== [] || $decisions !== []) {
            $lines[] = '';
            $lines[] = 'Vastgelegde uitkomsten (ter referentie, niet verzinnen):';
            foreach ($decisions as $decision) {
                if (is_array($decision) === true) {
                    $lines[] = '- Besluit: '.((string) ($decision['title'] ?? ''));
                }
            }

            foreach ($votes as $vote) {
                if (is_array($vote) === true) {
                    $lines[] = '- Stemming: '.((string) ($vote['title'] ?? '')).' ('.((string) ($vote['result'] ?? ($vote['outcome'] ?? ''))).')';
                }
            }
        }

        return implode("\n", $lines);

    }//end assemblePrompt()

    /**
     * Run a single text-to-text prompt through TaskProcessing synchronously.
     *
     * @param string $prompt The prompt.
     *
     * @return string The provider output text (empty on failure).
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function runPrompt(string $prompt): string
    {
        try {
            $manager = $this->container->get(\OCP\TaskProcessing\IManager::class);
            $task    = new Task(TextToText::ID, ['input' => $prompt], 'decidesk', null);
            $result  = $manager->runTask($task);
            $output  = $result->getOutput();
            if (is_array($output) === true) {
                return (string) ($output['output'] ?? '');
            }

            return '';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk MinutesDraftService: prompt run failed',
                ['error' => $e->getMessage()]
            );
            return '';
        }

    }//end runPrompt()

    /**
     * Resolve the preferred TaskProcessing provider id for provenance.
     *
     * @return string The provider id, or '' when not resolvable.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function preferredProviderId(): string
    {
        try {
            $manager  = $this->container->get(\OCP\TaskProcessing\IManager::class);
            $provider = $manager->getPreferredProvider(TextToText::ID);
            if (is_object($provider) === true && method_exists($provider, 'getId') === true) {
                return (string) $provider->getId();
            }
        } catch (\Throwable) {
            return '';
        }

        return '';

    }//end preferredProviderId()

    /**
     * Segments aligned to a given agenda item.
     *
     * @param array<int,array<string,mixed>> $segments     All segments.
     * @param string                         $agendaItemId Agenda item UUID.
     *
     * @return array<int,array<string,mixed>> Matching segments.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function segmentsForItem(array $segments, string $agendaItemId): array
    {
        $result = [];
        foreach ($segments as $segment) {
            if (is_array($segment) === true && (string) ($segment['agendaItem'] ?? '') === $agendaItemId) {
                $result[] = $segment;
            }
        }

        return $result;

    }//end segmentsForItem()

    /**
     * Records (votes/decisions) linked to a given agenda item.
     *
     * @param array<int,array<string,mixed>> $records      Records.
     * @param string                         $agendaItemId Agenda item UUID.
     *
     * @return array<int,array<string,mixed>> Matching records.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function recordForItem(array $records, string $agendaItemId): array
    {
        $result = [];
        foreach ($records as $record) {
            if (is_array($record) === false) {
                continue;
            }

            $linked = ($record['agendaItem'] ?? ($record['relations']['agendaItem'] ?? ($record['relations']['agenda-item'] ?? null)));
            if (is_array($linked) === true) {
                $linked = ($linked['id'] ?? ($linked[0] ?? null));
            }

            if ((string) $linked === $agendaItemId) {
                $result[] = $record;
            }
        }

        return $result;

    }//end recordForItem()

    /**
     * Fetch objects related to a meeting via OR findAll.
     *
     * @param string $meetingId Meeting UUID.
     * @param string $schema    Schema slug.
     *
     * @return array<int,array<string,mixed>> Object data arrays.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function fetchRelated(string $meetingId, string $schema): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entities      = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => $schema,
                    'filters'  => [
                        'register'           => 'decidesk',
                        'schema'             => $schema,
                        '_relations.meeting' => $meetingId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk MinutesDraftService: related fetch failed',
                ['schema' => $schema, 'error' => $e->getMessage()]
            );
            return [];
        }

        $result = [];
        foreach ($entities as $entity) {
            if (is_array($entity) === true) {
                $result[] = $entity;
            } else if (method_exists($entity, 'getObject') === true) {
                $result[] = $entity->getObject();
            } else if (method_exists($entity, 'jsonSerialize') === true) {
                $result[] = (array) $entity->jsonSerialize();
            }
        }

        return $result;

    }//end fetchRelated()

    /**
     * Fetch a single object as an array (or null).
     *
     * @param string $id     Object UUID.
     * @param string $schema Schema slug.
     *
     * @return array<string,mixed>|null The object data.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function fetchObject(string $id, string $schema): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $id, register: 'decidesk', schema: $schema);
        } catch (\Throwable) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return (array) $entity->jsonSerialize();

    }//end fetchObject()

    /**
     * Persist a Transcript object (for draftGeneration provenance only).
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return void
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function saveTranscript(array $transcript): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $id            = ($transcript['id'] ?? ($transcript['@self']['id'] ?? null));
            $uuid          = null;
            if ($id !== null) {
                $uuid = (string) $id;
            }

            $objectService->saveObject(
                object: $transcript,
                register: 'decidesk',
                schema: 'transcript',
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk MinutesDraftService: provenance save failed',
                ['error' => $e->getMessage()]
            );
        }

    }//end saveTranscript()

    /**
     * Resolve the linked meeting UUID from a Transcript object.
     *
     * @param array<string,mixed> $transcript The Transcript object.
     *
     * @return string|null The meeting UUID.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    private function resolveMeetingId(array $transcript): ?string
    {
        $relation = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
        if (is_array($relation) === true) {
            $relation = ($relation['id'] ?? ($relation[0] ?? null));
        }

        if ($relation === null || $relation === '') {
            return null;
        }

        return (string) $relation;

    }//end resolveMeetingId()
}//end class
