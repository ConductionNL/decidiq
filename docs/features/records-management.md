# Archival dossiers and the archive

A meeting's records belong together in the archive. decidiq bundles them into
one archival dossier: the approved minutes, the decisions, the voting rounds
and the documents. OpenRegister keeps the retention rules, the e-depot
transfer and the destruction. decidiq hands the dossier over and shows what
happened.

## Form and close a dossier

The chair or secretary of a meeting forms its dossier. decidiq gathers the
meeting's records by reference. While the dossier is forming, gather again to
pick up records added later.

Close the dossier when it is complete. decidiq refuses to close when the
minutes are missing or not approved, or when the meeting is not closed. Give
a reason to close it anyway; the reason is kept on the dossier. A closed
dossier is frozen: nobody adds or removes records after that.

When the register keeps TMLO metadata, closing also marks the dossier
semi-static (semi_statisch) in OpenRegister.

## Where a dossier goes

Each dossier follows its Selectielijst category, set once in the register.
The **Archive route** panel on the dossier page shows the category and the
route:

- **Kept** categories go to the archive. decidiq puts the dossier's records on
  an OpenRegister transfer list. An archivist approves the list in
  OpenRegister, and OpenRegister packages and delivers it to the e-depot.
- **Destroy** categories go to destruction. decidiq asks OpenRegister for a
  destruction list. OpenRegister checks each record's retention period and
  legal holds, and names every record it leaves off, with the reason.

Without an e-depot connection the panel says automated transfer is
unavailable. It links to the OpenRegister settings, and the dossier stays
closed.

Only an archivist (the `archivaris` group) or an administrator hands a dossier
over. These are the same people OpenRegister lets approve the lists.

## Transferred and destroyed

Use **Check OpenRegister's outcome** on the dossier page. When OpenRegister has
delivered the transfer, the dossier becomes transferred. When OpenRegister has
carried out the destruction list, the dossier becomes destroyed.

After a destruction, **File the destruction certificate** fetches
OpenRegister's verklaring van vernietiging. decidiq files a copy in the
meeting's Archive folder: a PDF when filinq is installed, markdown otherwise.
The copy lists every field OpenRegister wrote and the records it skipped. The
dossier keeps a reference to the copy, and the dossier itself is never
destroyed.

## Security classification

Minutes, decisions, meetings, documents and dossiers carry a security
classification: openbaar, intern, vertrouwelijk or geheim. These four follow
OpenRegister's confidentiality levels in the same order.

- A record classified above openbaar is never published.
- A dossier less confidential than one of its records shows a classification
  warning. The warning names that record. Raise the dossier's classification
  to clear it.

## The archive dashboard

**Registers > Archive** counts the dossiers that are forming, closed, waiting
for transfer, on a destruction list, transferred and destroyed. Each count
opens the matching list. Archivists get a weekly reminder for every dossier
that waits for transfer.

Two counts are not on the dashboard yet: overdue transfers and closed
meetings without a dossier. They need date filters that OpenRegister's counts
do not take yet. A meeting's page shows how many dossiers it has; zero on a
closed meeting is an archiving gap.
