<?php

/**
 * Decidiq DecisionConcludedEvent
 *
 * Public cross-app event Decidiq dispatches when a delegated (provenance-
 * carrying) Decision reaches a terminal outcome. Consumer fleet apps listen
 * for it to perform their own downstream side effects (shillinq GL post,
 * procest ZGW advance) — Decidiq owns the decision only, never the consumer's
 * side effect. Carries the subject/provenance reference plus the outcome
 * envelope built by DecisionIntegrationService::getOutcomeEnvelope().
 *
 * @category Event
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app conclusion event: Decidiq reports a concluded delegated Decision.
 *
 * Fully immutable — Decidiq constructs it from the outcome envelope and the
 * subject reference; consumers only read. The `status` value is the one
 * DERIVED by getOutcomeEnvelope() (no new state machine, ADR-031).
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 */
class DecisionConcludedEvent extends Event {
	/**
	 * Construct the conclusion event.
	 *
	 * @param string $decisionId The concluded Decision id
	 * @param string $decisionType The Decision type
	 * @param string $status Derived outcome status (approved|rejected|withdrawn|pending)
	 * @param string $outcome Raw decision outcome (e.g. adopted|rejected)
	 * @param bool $signed Whether a signature stage resolved
	 * @param string|null $signingReference Signing reference, when signed
	 * @param array<int, mixed> $signers Resolved signers list
	 * @param string|null $decidedAt When the decision concluded
	 * @param string $sourceApp Consumer app that raised the decision
	 * @param string|null $subjectRegister OpenRegister register of the originating object
	 * @param string|null $subjectSchema OpenRegister schema of the originating object
	 * @param string|null $subjectId OpenRegister id of the originating object
	 * @param string $externalReference Consumer's own reference
	 * @param string $correlationId Correlation id from the request event
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) This parameter list is a
	 * PUBLISHED CROSS-APP CONTRACT, not an internal signature. Consumer apps
	 * mirror it verbatim and construct the event POSITIONALLY — see procest
	 * tests/Stubs/Decidiq/Event/DecisionConcludedEvent.php (a byte-for-byte
	 * mirror of this constructor) and
	 * procest tests/Unit/Listener/DecisionConcludedListenerTest.php, which
	 * builds the event with all fourteen arguments in this exact order.
	 * Grouping the parameters into value objects would break those consumers at
	 * runtime and cannot be done from this repository alone; it needs a
	 * coordinated, versioned change to the decidesk-decision-events contract. See
	 * openspec/specs/decidesk-decision-events/spec.md.
	 */
	public function __construct(
		private readonly string $decisionId,
		private readonly string $decisionType,
		private readonly string $status,
		private readonly string $outcome,
		private readonly bool $signed,
		private readonly ?string $signingReference,
		private readonly array $signers,
		private readonly ?string $decidedAt,
		private readonly string $sourceApp,
		private readonly ?string $subjectRegister,
		private readonly ?string $subjectSchema,
		private readonly ?string $subjectId,
		private readonly string $externalReference = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Build a conclusion event from a getOutcomeEnvelope() array.
	 *
	 * The envelope already echoes the subject reference + externalReference;
	 * sourceApp and correlationId are supplied by the caller (they live on the
	 * Decision / originating request, not always in the envelope).
	 *
	 * @param array<string, mixed> $envelope Outcome envelope from getOutcomeEnvelope()
	 * @param string $outcome Raw decision outcome
	 * @param string $sourceApp Consumer app that raised the decision
	 * @param string $correlationId Correlation id from the request event
	 *
	 * @return self
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public static function fromEnvelope(
		array $envelope,
		string $outcome,
		string $sourceApp,
		string $correlationId = '',
	): self {
		return new self(
			decisionId: (string)($envelope['decisionId'] ?? ''),
			decisionType: (string)($envelope['decisionType'] ?? ''),
			status: (string)($envelope['status'] ?? 'pending'),
			outcome: $outcome,
			signed: (bool)($envelope['signed'] ?? false),
			signingReference: ($envelope['signingReference'] ?? null),
			signers: (array)($envelope['signers'] ?? []),
			decidedAt: ($envelope['decidedAt'] ?? null),
			sourceApp: $sourceApp,
			subjectRegister: ($envelope['subjectRegister'] ?? null),
			subjectSchema: ($envelope['subjectSchema'] ?? null),
			subjectId: ($envelope['subjectId'] ?? null),
			externalReference: (string)($envelope['externalReference'] ?? ''),
			correlationId: $correlationId,
		);
	}//end fromEnvelope()

	/**
	 * Get the concluded Decision id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getDecisionId(): string {
		return $this->decisionId;
	}//end getDecisionId()

	/**
	 * Get the Decision type.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getDecisionType(): string {
		return $this->decisionType;
	}//end getDecisionType()

	/**
	 * Get the derived outcome status (approved|rejected|withdrawn|pending).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * Get the raw decision outcome (e.g. adopted|rejected).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getOutcome(): string {
		return $this->outcome;
	}//end getOutcome()

	/**
	 * Whether a signature stage resolved.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function isSigned(): bool {
		return $this->signed;
	}//end isSigned()

	/**
	 * Get the signing reference, when signed.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getSigningReference(): ?string {
		return $this->signingReference;
	}//end getSigningReference()

	/**
	 * Get the resolved signers list.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getSigners(): array {
		return $this->signers;
	}//end getSigners()

	/**
	 * Get when the decision concluded.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getDecidedAt(): ?string {
		return $this->decidedAt;
	}//end getDecidedAt()

	/**
	 * Get the consumer app that raised the decision.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the OpenRegister register of the originating object.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getSubjectRegister(): ?string {
		return $this->subjectRegister;
	}//end getSubjectRegister()

	/**
	 * Get the OpenRegister schema of the originating object.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getSubjectSchema(): ?string {
		return $this->subjectSchema;
	}//end getSubjectSchema()

	/**
	 * Get the OpenRegister id of the originating object.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getSubjectId(): ?string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * Get the consumer's own external reference.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * Get the correlation id from the request event.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()
}//end class
