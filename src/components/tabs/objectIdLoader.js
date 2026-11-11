/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The objectId prop and loader every detail tab shares.
 *
 * A detail tab shows one object, named by its `objectId` prop. It reads that
 * object in its own `load()` method, once when the tab is created and again
 * whenever the page hands it another object. The tab declares `load()`; this
 * mixin declares the prop and the watcher that calls it.
 */

export const objectIdLoader = {
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},
}
