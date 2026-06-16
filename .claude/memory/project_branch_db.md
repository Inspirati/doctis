---
name: Branch and database relationship
description: git-design-1 and dev share the same live vaio database — no per-branch schema migration needed at merge time
type: project
---
All active branches (`git-design-1`, `dev`, etc.) operate against the same
single MariaDB database on vaio. There is no per-branch database.

**Why:** Single-instance development environment; the database is rebuilt via
the reset procedure when a clean slate is needed, which applies the full
`schema.php` migration chain. Any schema changes committed on a feature branch
are already live on the shared database by the time the branch is merged.

**How to apply:** Do not flag schema migrations in `schema.php` as a pre-merge
or post-merge action item. The migration is already applied. The only case
where schema action is needed is when setting up Doctis on a NEW host from
scratch, following `doc/doctis-git-server-setup.txt`.
