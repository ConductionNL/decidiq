<?php

/**
 * Decidesk DecisionRequestedEvent
 *
 * Public cross-app event a consumer fleet app dispatches to ask decidesk to
 * raise a governance Decision for one of its objects. The in-process,
 * autoloaded replacement for the broken IntegrationService::getLeaf HTTP
 * delegation path: dispatched via Nextcloud's IEventDispatcher and handled
 * synchronously by DecisionRequestedListener, so the dispatching producer can
 * read the resolved decisionId back off the same instance.
 *
 * @category Event
 * @package  OCA\Decidesk\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app request event: a consumer app asks decidesk to raise a Decision.
 *
 * All request fields are immutable (constructor-injected getters). Nextcloud
 * typed dispatch is synchronous, so the single result slot (decisionId +
 * handled) is written by decidesk's listener and read by the producer right
 * after dispatch — the standard NC request/response-over-the-bus pattern.
 *
 * @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md
 */
class DecisionRequestedEvent extends Event
{

    /**
     * The id of the Decision decidesk created or matched (result slot).
     *
     * @var string|null
     */
    private ?string $decisionId = null;

    /**
     * Whether decidesk's listener handled this request (result slot).
     *
     * @var boolean
     */
    private bool $handled = false;

    /**
     * Construct the request event.
     *
     * @param string               $sourceApp         The consumer app raising the decision
     * @param string               $subjectRegister   OpenRegister register of the originating object
     * @param string               $subjectSchema     OpenRegister schema of the originating object
     * @param string               $subjectId         OpenRegister id of the originating object
     * @param string               $subjectLabel      Human display label for the subject
     * @param string               $decisionType      Decision type (e.g. contract, report-adoption)
     * @param string               $actorId           Nextcloud UID of the requesting user
     * @param array<string, mixed> $payload           Additional decision body fields (title/text/...)
     * @param string               $externalReference Consumer's own reference (idempotency/linking)
     * @param string               $correlationId     Correlation id echoed on the conclusion event
     */
    public function __construct(
        private readonly string $sourceApp,
        private readonly string $subjectRegister,
        private readonly string $subjectSchema,
        private readonly string $subjectId,
        private readonly string $subjectLabel='',
        private readonly string $decisionType='contract',
        private readonly string $actorId='',
        private readonly array $payload=[],
        private readonly string $externalReference='',
        private readonly string $correlationId='',
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the consumer app that raised the decision.
     *
     * @return string
     */
    public function getSourceApp(): string
    {
        return $this->sourceApp;
    }//end getSourceApp()

    /**
     * Get the OpenRegister register of the originating object.
     *
     * @return string
     */
    public function getSubjectRegister(): string
    {
        return $this->subjectRegister;
    }//end getSubjectRegister()

    /**
     * Get the OpenRegister schema of the originating object.
     *
     * @return string
     */
    public function getSubjectSchema(): string
    {
        return $this->subjectSchema;
    }//end getSubjectSchema()

    /**
     * Get the OpenRegister id of the originating object.
     *
     * @return string
     */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }//end getSubjectId()

    /**
     * Get the human display label for the subject.
     *
     * @return string
     */
    public function getSubjectLabel(): string
    {
        return $this->subjectLabel;
    }//end getSubjectLabel()

    /**
     * Get the requested decision type.
     *
     * @return string
     */
    public function getDecisionType(): string
    {
        return $this->decisionType;
    }//end getDecisionType()

    /**
     * Get the Nextcloud UID of the requesting user.
     *
     * @return string
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }//end getActorId()

    /**
     * Get the additional decision body payload.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }//end getPayload()

    /**
     * Get the consumer's own external reference.
     *
     * @return string
     */
    public function getExternalReference(): string
    {
        return $this->externalReference;
    }//end getExternalReference()

    /**
     * Get the correlation id echoed on the conclusion event.
     *
     * @return string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }//end getCorrelationId()

    /**
     * Get the id of the Decision decidesk created or matched (result slot).
     *
     * @return string|null Null until decidesk's listener has handled the event.
     */
    public function getDecisionId(): ?string
    {
        return $this->decisionId;
    }//end getDecisionId()

    /**
     * Set the resolved Decision id (written by decidesk's listener).
     *
     * @param string $decisionId The created/matched Decision id
     *
     * @return void
     */
    public function setDecisionId(string $decisionId): void
    {
        $this->decisionId = $decisionId;
    }//end setDecisionId()

    /**
     * Whether decidesk's listener handled this request.
     *
     * @return bool
     */
    public function isHandled(): bool
    {
        return $this->handled;
    }//end isHandled()

    /**
     * Mark whether decidesk's listener handled this request.
     *
     * @param bool $handled True when decidesk created/matched a Decision
     *
     * @return void
     */
    public function setHandled(bool $handled): void
    {
        $this->handled = $handled;
    }//end setHandled()
}//end class
