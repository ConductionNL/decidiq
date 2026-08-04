<?php

/**
 * Unit tests for VotingService template voting-rule defaults (process-config).
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
 * @spec openspec/specs/process-configuration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ParticipantUuidLookup;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\Decidesk\Service\VoteCastingService;
use OCA\Decidesk\Service\VotingOpenedNotifier;
use OCA\Decidesk\Service\VotingRoundCloser;
use OCA\Decidesk\Service\VotingRoundOpener;
use OCA\Decidesk\Service\VotingRoundPreflight;
use OCA\Decidesk\Service\VotingRoundProjection;
use OCA\Decidesk\Service\VotingRoundResults;
use OCA\Decidesk\Service\VotingRoundRules;
use OCA\Decidesk\Service\VotingService;
use OCA\Decidesk\Service\VotingSubjectOutcomeApplier;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Verifies the round-open path applies a body template's voting-rule defaults
 * when the caller leaves a rule null, and that an explicit caller value wins.
 *
 * @spec openspec/specs/process-configuration/spec.md
 */
class VotingServiceTemplateRuleTest extends TestCase
{

    /**
     * Captured saved voting-round objects.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $saved = [];

    /**
     * Build a VotingService whose ProcessTemplateService returns $templateRule.
     *
     * @param array<string,string>|null $templateRule The template voting rule, or null
     *
     * @return VotingService
     */
    private function buildService(?array $templateRule): VotingService
    {
        $this->saved = [];

        $meeting = $this->createMock(ObjectEntity::class);
        // quorumRequired=0 -> checkQuorum() returns true (no participants needed).
        $meeting->method('jsonSerialize')->willReturn(['id' => 'meeting-1', 'quorumRequired' => 0]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('find')->willReturn($meeting);
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) {
                $this->saved[] = $object;
                return $object;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService): object {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                throw new \RuntimeException('not wired in test: '.$id);
            }
        );

        $participantResolver = $this->createMock(ParticipantResolver::class);
        $participantResolver->method('resolveMeetingParticipants')->willReturn([]);

        $templateService = $this->createMock(ProcessTemplateService::class);
        $templateService->method('resolveVotingRuleForBody')->willReturn($templateRule);

        $motionService = $this->createMock(MotionService::class);

        // VotingService is a thin facade: every operation is delegated to a
        // single-purpose collaborator, so the graph is built explicitly here
        // where production relies on Nextcloud's constructor auto-wiring.
        $logger  = new NullLogger();
        $results = new VotingRoundResults(
            container: $container,
            motionService: $motionService,
            participantResolver: $participantResolver
        );

        return new VotingService(
            opener: new VotingRoundOpener(
                container: $container,
                motionService: $motionService,
                participantResolver: $participantResolver,
                preflight: new VotingRoundPreflight(
                    container: $container,
                    logger: $logger,
                    motionService: $motionService,
                    participantResolver: $participantResolver,
                    templateService: $templateService
                ),
                notifier: new VotingOpenedNotifier(
                    container: $container,
                    logger: $logger,
                    participantResolver: $participantResolver
                )
            ),
            caster: new VoteCastingService(
                container: $container,
                logger: $logger,
                motionService: $motionService,
                participantResolver: $participantResolver
            ),
            closer: new VotingRoundCloser(
                container: $container,
                logger: $logger,
                oriService: $this->createMock(OriPublicationService::class),
                results: $results,
                outcome: new VotingSubjectOutcomeApplier(
                    container: $container,
                    logger: $logger,
                    motionService: $motionService
                )
            ),
            results: $results,
            projection: new VotingRoundProjection(container: $container),
            participants: new ParticipantUuidLookup(container: $container),
        );

    }//end buildService()

    /**
     * When the caller omits rule values, the body template defaults apply.
     *
     * @return void
     */
    public function testTemplateDefaultsApplyWhenCallerOmitsRules(): void
    {
        $service = $this->buildService(
            [
                'voteThreshold'      => 'qualified-majority-two-thirds',
                'abstentionHandling' => 'count',
                'tieBreakRule'       => 'chair-decides',
            ]
        );

        $service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(
                voteThreshold: null,
                abstentionHandling: null,
                tieBreakRule: null,
                governanceBodyId: 'body-1'
            )
        );

        self::assertNotEmpty($this->saved);
        $round = $this->saved[0];
        self::assertSame('qualified-majority-two-thirds', $round['voteThreshold']);
        self::assertSame('count', $round['abstentionHandling']);
        self::assertSame('chair-decides', $round['tieBreakRule']);

    }//end testTemplateDefaultsApplyWhenCallerOmitsRules()

    /**
     * An explicit caller value wins over the template default.
     *
     * @return void
     */
    public function testExplicitCallerValueWinsOverTemplate(): void
    {
        $service = $this->buildService(['voteThreshold' => 'qualified-majority-two-thirds']);

        $service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(voteThreshold: 'unanimous', governanceBodyId: 'body-1')
        );

        self::assertSame('unanimous', $this->saved[0]['voteThreshold']);

    }//end testExplicitCallerValueWinsOverTemplate()

    /**
     * No template -> the built-in method defaults remain in place (fail-soft).
     *
     * @return void
     */
    public function testNoTemplateFallsBackToBuiltInDefaults(): void
    {
        $service = $this->buildService(null);

        $service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(governanceBodyId: null)
        );

        self::assertSame('simple-majority', $this->saved[0]['voteThreshold']);
        self::assertSame('exclude', $this->saved[0]['abstentionHandling']);
        self::assertSame('rejected', $this->saved[0]['tieBreakRule']);

    }//end testNoTemplateFallsBackToBuiltInDefaults()
}//end class
