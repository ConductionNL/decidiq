<?php

/**
 * Decidiq Proxy Holder Cap
 *
 * The per-holder proxy limit for grants made on a VotingRound: one participant
 * may hold at most `decidiq`/`max_proxies_per_holder` proxies on a round (the
 * same cap ProxyVoteService::register() applies, NL governance default 2).
 *
 * Extracted from ProxyDelegationService so the delegation flow does not also
 * carry the app-config lookup behind the cap.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\Decidiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enforces the maximum number of proxies one participant may hold on a round.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class ProxyHolderCap {

	/**
	 * Constructor for ProxyHolderCap.
	 *
	 * @param ContainerInterface $container The DI container (app config is resolved lazily)
	 * @param LoggerInterface    $logger    Logger for config lookup failures
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse a grant that would give the receiver more proxies than allowed.
	 *
	 * @param list<array{fromParticipantId: string, toParticipantId: string}> $grants          The round's grants, minus the grantor's earlier one
	 * @param string                                                          $toParticipantId The receiving participant UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the receiver already holds the maximum
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function assertRoomFor(array $grants, string $toParticipantId): void {
		$held = count(
			array_filter(
				$grants,
				static fn (array $grant): bool => $grant['toParticipantId'] === $toParticipantId
			)
		);

		$maxProxies = $this->maxProxiesPerHolder();
		if ($held >= $maxProxies) {
			throw new InvalidArgumentException(
				sprintf(
					'Deze deelnemer heeft al het maximale aantal volmachten (%d van %d) voor deze stemronde',
					$held,
					$maxProxies
				)
			);
		}

	}//end assertRoomFor()

	/**
	 * Resolve the per-holder proxy cap, shared with ProxyVoteService::register().
	 *
	 * Values below 1 and lookup failures fall back to the default, so a
	 * misconfigured cap never switches the limit off.
	 *
	 * @return int The maximum number of proxies one participant may hold
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function maxProxiesPerHolder(): int {
		try {
			$value = $this->container->get(IAppConfig::class)->getValueInt(
				Application::APP_ID,
				ProxyVoteService::MAX_PROXIES_CONFIG_KEY,
				ProxyVoteService::MAX_PROXIES_DEFAULT
			);
			if ($value >= 1) {
				return $value;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: max_proxies_per_holder config lookup failed — using default',
				['error' => $e->getMessage()]
			);
		}

		return ProxyVoteService::MAX_PROXIES_DEFAULT;

	}//end maxProxiesPerHolder()
}//end class
