# Proposal: deep-link-listener-in-decidiqs-namespace

## Summary

The Zuiddrecht rehearsal logged "Could not load event listener service OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener: Could not resolve appId!" 1,985 times. decidiq registered the AppHost's generic deep-link listener under OpenRegister's class name. Nextcloud builds a listener through the container of the app that owns its namespace, so OpenRegister's container built it, without the `appId` argument decidiq's factory supplies. The listener never ran, and decidiq's deep links were never registered.

## What changes

- `AppHostRegistrar` registers the factory and the listener under `OCA\Decidiq\Listener\DeepLinkRegistrationListener`, as OpenRegister's `Bootstrap::registerDeepLinkListener()` and pipelinq do.
