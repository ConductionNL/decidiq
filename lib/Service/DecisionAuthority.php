<?php

/**
 * Decidiq decision authority.
 *
 * Whether the person who drafted a decision may take it, read off the
 * delegatie- en mandaatregister through DelegatedAuthorityCheck: the drafter
 * is the decision's owner and the act is its decisionType. The decisions leaf
 * shows the answer as its mandate notice on the host's page.
 *
 * The decision is read through ObjectService with RBAC and multitenancy at
 * their defaults; a decision the caller may not read answers null, the same
 * as a missing one. The answer names an allocation's subject and restrictions
 * only when it covers the drafter.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers whether a decision's drafter holds the authority to take it.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
 */
class DecisionAuthority {

	/**
	 * Schema slug of the allocation rows the check answers with.
	 */
	private const ALLOCATION_SCHEMA = 'bevoegdheidstoedeling';

	/**
	 * Reads the decision.
	 *
	 * @var DecisionContextResolver
	 */
	private readonly DecisionContextResolver $contextResolver;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface  $objectService Reads the decision under RBAC.
	 * @param DelegatedAuthorityCheck $check         Judges the authority.
	 * @param RegisterObjectStore     $store         Reads the covering allocation.
	 * @param LoggerInterface         $logger        Logs an unreadable allocation.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly DelegatedAuthorityCheck $check,
		private readonly RegisterObjectStore $store,
		private readonly LoggerInterface $logger,
	) {
		$this->contextResolver = new DecisionContextResolver(logger: $logger);
	}//end __construct()

	/**
	 * The authority answer for one decision.
	 *
	 * @param string $decisionId UUID of the decision.
	 *
	 * @return array{authorised: bool, reason: string, actor: string, act: string, subject: string|null, restrictions: string|null}|null
	 *         Null when the caller may not read the decision.
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
	 */
	public function forDecision(string $decisionId): ?array {
		try {
			$decision = $this->contextResolver->loadDecision(objectService: $this->objectService, decisionId: $decisionId);
		} catch (Throwable) {
			return null;
		}

		if ($decision === null) {
			return null;
		}

		$self = [];
		if (is_array($decision['@self'] ?? null) === true) {
			$self = $decision['@self'];
		}

		$actor  = (string)($self['owner'] ?? '');
		$act    = (string)($decision['decisionType'] ?? '');
		$answer = $this->check->check(actor: $actor, act: $act);

		$subject      = null;
		$restrictions = null;
		if ($answer['authorised'] === true && ($answer['allocation'] ?? '') !== '') {
			$allocation   = $this->allocation(uuid: (string)$answer['allocation']);
			$subject      = $this->text(value: $allocation['subject'] ?? null);
			$restrictions = $this->text(value: $allocation['beperkingen'] ?? null);
		}

		return [
			'authorised'   => $answer['authorised'],
			'reason'       => $answer['reason'],
			'actor'        => $actor,
			'act'          => $act,
			'subject'      => $subject,
			'restrictions' => $restrictions,
		];
	}//end forDecision()

	/**
	 * The covering allocation, or an empty row when it cannot be read.
	 *
	 * @param string $uuid The allocation id.
	 *
	 * @return array<string, mixed>
	 */
	private function allocation(string $uuid): array {
		try {
			return ($this->store->find(schema: self::ALLOCATION_SCHEMA, uuid: $uuid) ?? []);
		} catch (Throwable $failure) {
			$this->logger->warning('[DecisionAuthority] allocation unreadable: ' . $failure->getMessage());
			return [];
		}
	}//end allocation()

	/**
	 * Trimmed text, or null for none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
