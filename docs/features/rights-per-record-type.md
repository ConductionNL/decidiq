# Rights per record type

Every kind of record in decidiq, a meeting, a motion, the minutes, has rules
for who may read it, create it, change it and delete it. OpenRegister enforces
those rules. This page shows how an administrator reads them and how to give
your own groups a role, so that, for example, everyone in your Griffie group
may change minutes.

## Read the rules

1. Open **Administration settings** and choose **decidiq**.
2. Find **Rights per record type**.

The table has a row per kind of record and says, for read, create, change and
delete, who may do it:

- **Everyone** means anyone, signed in or not. "(under a condition)" after it
  means only records that meet a condition, usually that they are published.
- **Signed-in users** means anyone with an account on this Nextcloud.
- A group name means members of that group.

A row marked "(the app's general rules)" has no rules of its own and follows
the general rules decidiq sets for all its records.

## Give your groups a role

The rules name three decidiq roles, each with its own group:

- **Record administrators** (`decidiq-administrators`): may change and delete
  records.
- **Secretariat** (`decidiq-secretariat`).
- **Publication flow** (`decidiq-publication-flow`).

To give a group of your own the same rights as a role:

1. Pick the group in the field for that role. You can pick more than one.
2. Press **Save groups**.

decidiq then loads its rules again with your groups added, and the table shows
them. The role's own group keeps its rights, so you cannot lock yourself out.
To take a group's rights away, remove it from the field and save.

"Everyone" and "Signed-in users" are not roles and cannot be changed here.

## Good to know

- Saving loads the rules again at once. If that fails the page says so; your
  choice is kept and applies at the next load.
- The general rules count too: a group you add to Record administrators may
  change every kind of record that has no rules of its own.
