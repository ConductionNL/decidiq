<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Non-blocking prompt-on-transition publish dialog
 (publish-decisions-via-opencatalogi). Shown when a decision reaches `enacted`
 for a governance body configured with the `prompt-on-transition` policy.
 Dismissal NEVER publishes — publication only happens via the explicit Publish
 button (which calls the same authoritative publish endpoint).

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Publish this decision?')"
		data-testid="publication-prompt-modal"
		@closing="$emit('dismiss')">
		<template #default>
			<p>{{ t('decidesk', 'This decision has been enacted and the governance body is configured to prompt for publication. Publishing makes a derived public record available through OpenCatalogi. You can also publish later from the Publication tab.') }}</p>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="publication-prompt-publish"
				@click="$emit('publish')">
				{{ t('decidesk', 'Publish now') }}
			</NcButton>
			<NcButton
				data-testid="publication-prompt-dismiss"
				@click="$emit('dismiss')">
				{{ t('decidesk', 'Not now') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'PublicationPromptModal',
	components: { NcButton, NcDialog },
}
</script>
