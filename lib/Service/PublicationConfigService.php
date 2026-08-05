<?php
/**
 * Decidesk Publication Config Service
 *
 * Reads and writes the per-governance-body publication configuration
 * (target OpenCatalogi catalog, per-type publication policy, attendance
 * rendering policy) stored as a single JSON blob in IAppConfig.
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
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Stores publication configuration per governance body as app config — no
 * bespoke tables (ADR-022). The config blob shape is:
 *   { "<bodyId>": { "catalog": "<id>", "policy": { "decision": "manual-only"|"prompt-on-transition", ... }, "attendance": "counts"|"role-holders" } }
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationConfigService
{
    /**
     * IAppConfig key the publication configuration blob is stored under.
     *
     * @var string
     */
    public const CONFIG_KEY = 'publication_config';

    /**
     * Valid per-type publication policy values.
     *
     * @var string[]
     */
    public const POLICIES = ['manual-only', 'prompt-on-transition'];

    /**
     * Valid attendance-rendering policy values for minutes payloads.
     *
     * @var string[]
     */
    public const ATTENDANCE_POLICIES = ['counts', 'role-holders'];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig App configuration store.
     *
     * @spec openspec/specs/public-publication/spec.md
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Read the full publication configuration blob.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return array<string,mixed> Map of bodyId => body config.
     */
    public function getAll(): array
    {
        $raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;

    }//end getAll()

    /**
     * Resolve the configuration for one governance body, with safe defaults.
     *
     * @param string $bodyId UUID of the GovernanceBody.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return array{catalog:string,policy:array<string,string>,attendance:string}
     */
    public function getForBody(string $bodyId): array
    {
        $all  = $this->getAll();
        $body = ($all[$bodyId] ?? []);

        $policy = [];
        if (isset($body['policy']) === true && is_array($body['policy']) === true) {
            $policy = $body['policy'];
        }

        $attendance = ($body['attendance'] ?? 'counts');
        if (in_array($attendance, self::ATTENDANCE_POLICIES, true) === false) {
            $attendance = 'counts';
        }

        return [
            'catalog'    => (string) ($body['catalog'] ?? ''),
            'policy'     => $policy,
            'attendance' => $attendance,
        ];

    }//end getForBody()

    /**
     * Resolve the publication policy for a (body, source type) pair.
     *
     * @param string $bodyId     UUID of the GovernanceBody.
     * @param string $sourceType One of decision|agenda|minutes.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return string 'manual-only' (default) or 'prompt-on-transition'.
     */
    public function getPolicy(string $bodyId, string $sourceType): string
    {
        $body  = $this->getForBody(bodyId: $bodyId);
        $value = ($body['policy'][$sourceType] ?? 'manual-only');
        if (in_array($value, self::POLICIES, true) === false) {
            return 'manual-only';
        }

        return $value;

    }//end getPolicy()

    /**
     * Persist the full publication configuration blob.
     *
     * Validates policy + attendance enums; unknown values are dropped to keep
     * the stored blob well-formed.
     *
     * @param array<string,mixed> $config Map of bodyId => body config.
     *
     * @spec openspec/specs/public-publication/spec.md
     *
     * @return array<string,mixed> The normalised, persisted config.
     */
    public function save(array $config): array
    {
        $clean = [];
        foreach ($config as $bodyId => $body) {
            if (is_array($body) === false) {
                continue;
            }

            $policy    = [];
            $rawPolicy = ($body['policy'] ?? []);
            if (is_array($rawPolicy) === true) {
                foreach (['decision', 'agenda', 'minutes'] as $type) {
                    $value = ($rawPolicy[$type] ?? null);
                    if (in_array($value, self::POLICIES, true) === true) {
                        $policy[$type] = $value;
                    }
                }
            }

            $attendance = ($body['attendance'] ?? 'counts');
            if (in_array($attendance, self::ATTENDANCE_POLICIES, true) === false) {
                $attendance = 'counts';
            }

            $clean[(string) $bodyId] = [
                'catalog'    => (string) ($body['catalog'] ?? ''),
                'policy'     => $policy,
                'attendance' => $attendance,
            ];
        }//end foreach

        $encoded = json_encode($clean, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($encoded === false) {
            $encoded = '{}';
        }

        $this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $encoded);

        return $clean;

    }//end save()
}//end class
