// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The pdf.js worker, bundled as a web worker of its own so the annotator
 * (document-annotations) renders PDFs off the main thread. Started from
 * src/views/AnnotationView.vue with `new Worker(new URL(...))`, which webpack
 * turns into a separate chunk loaded only when the annotator opens.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */

import 'pdfjs-dist/build/pdf.worker.min.mjs'
