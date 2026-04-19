<?php

/**
 * Test stub for OCA\OpenRegister\Event\DeepLinkRegistrationEvent.
 *
 * Defines the class in the correct namespace so that unit tests can exercise
 * DeepLinkRegistrationListener without requiring the OpenRegister app to be
 * installed as a Composer dependency.
 *
 * This file is loaded by tests/bootstrap-unit.php and is NOT scanned by PHPCS.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Stub implementation of DeepLinkRegistrationEvent for unit testing.
 *
 * Captures register() calls so tests can assert on the registered slugs
 * and URL templates without needing the real OpenRegister event class.
 */
class DeepLinkRegistrationEvent extends Event
{

    /**
     * Captured registration calls.
     *
     * @var array<array{appId:string,registerSlug:string,schemaSlug:string,urlTemplate:string}>
     */
    private array $registrations = [];


    /**
     * Record a deep-link registration.
     *
     * @param string $appId        The Nextcloud app identifier.
     * @param string $registerSlug The OpenRegister register slug.
     * @param string $schemaSlug   The schema slug to register.
     * @param string $urlTemplate  The URL template with {uuid} placeholder.
     *
     * @return void
     */
    public function register(
        string $appId,
        string $registerSlug,
        string $schemaSlug,
        string $urlTemplate,
    ): void {
        $this->registrations[] = [
            'appId'        => $appId,
            'registerSlug' => $registerSlug,
            'schemaSlug'   => $schemaSlug,
            'urlTemplate'  => $urlTemplate,
        ];

    }//end register()


    /**
     * Return all captured registrations.
     *
     * @return array<array{appId:string,registerSlug:string,schemaSlug:string,urlTemplate:string}>
     */
    public function getRegistrations(): array
    {
        return $this->registrations;

    }//end getRegistrations()


}//end class
