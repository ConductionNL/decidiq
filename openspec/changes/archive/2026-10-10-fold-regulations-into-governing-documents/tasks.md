# fold-regulations-into-governing-documents tasks

## 1. Merge the two schemas

- [x] 1.1 Add the absorbed properties to `GoverningDocument`, with `cvdrIdentifier` renamed.
- [x] 1.2 Union the `type` and `status` enums.
- [x] 1.3 Declare `migratedFromObject` on both generic schemas, so the idempotency key persists.
- [x] 1.4 Retire `Regeling` and `RegelingVersie`, non-destructively.
- [x] 1.5 Repoint `RegelingExportPackage.regulation` at `governing-document`.

## 2. Carry the rows across

- [x] 2.1 Add `MigrateRegulationsToGoverningDocuments`, documents before versions.
- [x] 2.2 Resolve every reference to a uuid before writing it.
- [x] 2.3 Skip a version whose parent could not be copied, rather than binding it to nothing.
- [x] 2.4 Register the repair step.

## 3. Remove the surfaces

- [x] 3.1 Delete the verordeningenregister manifest fragment: one menu entry, three pages. (deleted in #1159; no src/manifest.d/verordeningenregister.json)
- [x] 3.2 Check whether the version detail page has a generic counterpart, and add one if not. (ported: src/manifest.d/governing-documents-register.json, the version detail page noted "Ported from the retired RegelingVersieDetail")

## 4. Move the vocabulary to the example sets

- [x] 4.1 Convert the municipality set's regulations into governing documents. (lib/Settings/profiles/municipality.json seeds a by-law with externalRegisterIdentifier CVDR641871 and a policy rule as governing-document)

## 5. Prove it

- [x] 5.1 Unit tests: mapping, the rename, idempotency, ordering, an orphan version. (tests/Unit/Migration/MigrateRegulationsToGoverningDocumentsTest.php)
- [ ] 5.2 E2E: the regulations still render, under the generic surface. (not run: needs the live instance) (live pass, decision 139)
