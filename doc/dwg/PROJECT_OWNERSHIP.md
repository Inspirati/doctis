# Project owner

Status: **built and tested on the native VM, 2026-10-23**, on `dev`. Not on the
live version, which needs a database rebuild to gain the column.

Owner decision 2026-10-23: a project has a **responsible person** — a
project manager or equivalent — as a first-class field, and **sub-projects
inherit it as the default**.

---

## 1. Why a field rather than the access list

Before this, the only thing identifying who ran a project was *whoever holds
MANAGER in `{project_user_list}`*. That is an access list, not an
accountability: it can hold nobody, one person or many. On live it holds
**seven** people, so it named nobody in particular.

The decision was reconsidered now because the project model changed. One
project (HCRQMS) made the field pointless; the agreed model is HCRQMS plus one
standalone top-level project per engineering programme, at which point "who is
responsible for this programme?" is a question nothing answered.

Every other level already had a person field: `{category}.user_id`,
`{dwg}.owner_id`, `{topic_expectation}.owner_id`, `{topic_profile}.user_id`,
`{meeting}.chair_id`. Project was the gap.

## 2. The field

`{project}.owner_id`, `int(10) unsigned NOT NULL DEFAULT 0`, with
`idx_project_owner`. Added to the existing `CREATE TABLE` in
[admin/schema.php](../../admin/schema.php) in place, so **a rebuild is
required** — it does not arrive by upgrade.

`0` means *inherit from the parent project*, which is also the default, so an
existing tree keeps behaving as one unit once its top-level project names an
owner.

## 3. Inheritance

[`project_get_owner( $project_id, $inherited = true )`](../../core/project_api.php)
climbs the project hierarchy until a project names an owner, mirroring
`dwg_publish_site()` and repository resolution. `project_get_owner_source()`
returns which project supplied it, so a form can distinguish *set here* from
*inherited from X*. The walk stops on a cycle.

Two rules worth knowing:

- **`$inherited = false` reads only the project's own field.** The edit form
  needs this: the picker must show "inherit from parent" rather than
  pre-selecting the inherited person, or saving the form would silently pin the
  inherited value.
- **A disabled or deleted owner reports as no owner.** The field exists to name
  somebody who can be held responsible, and a disabled account cannot be. The
  stored value is left untouched, so re-enabling the account restores it.

## 4. Where it is wired

| Layer | Detail |
|-------|--------|
| API | `project_create()` and `project_update()` take `$p_owner_id` and `$p_classification`, both defaulted so existing callers are unaffected |
| Commands | `ProjectAddCommand`, `ProjectUpdateCommand` (payload key `owner`, an account reference) |
| REST / SOAP | the project row carries `owner` (the project's own) **and** `effective_owner` (after inheritance), plus `classification`; the WSDL `ProjectData` type has all three |
| UI | Owner picker and Classification on the project create and edit forms; the edit form names the project an inherited owner comes from |
| Project list | a sortable **Owner** column, showing an inherited owner in italics |

**On update, absent means unchanged.** `project_update()` takes `null` for
owner and classification to mean "leave alone", and `ProjectUpdateCommand`
passes `null` when the payload omits them, so a client that knows nothing about
these fields cannot blank them. An explicit owner of `0` does clear it, which
restores inheritance.

## 5. What consumes it

Two consumers are connected:

- **Successor suggestion.** `user_transfer_suggest_successor()` falls back to
  the owner of the project where a departing user holds the most documents,
  when the `reports_to` chain yields nothing. For documents this is arguably
  the better answer rather than merely a last resort: document responsibility
  follows the programme, not the departing person's line manager. See
  [USER_LIFECYCLE.md](USER_LIFECYCLE.md) §5.
- **New document owner.** A document's owner defaults to its creator (D6), but
  a creator who does not meet `$g_own_dwg_threshold` would leave the field
  holding somebody ineligible, so it falls back to the project owner. See
  [OWNERSHIP.md](OWNERSHIP.md) §10, D6.

### Where that policy lives, and why it moved

The owner default is applied **only** in `DwgAddCommand`. `DwgData::create()`
persists what it is given and invents nothing.

It was briefly implemented in both, which made the import opt-out below a
no-op: the command left the field at 0 and `create()` filled it with the
creator regardless. Importing the real HCRQMS repository is what exposed it —
the report said 100 documents were left unowned while all 100 were in fact
owned by the administrator who ran the import.

A bulk import opts out with the command's `default_owner` option. The person
running an import of pre-existing documents is not thereby taking
responsibility for all of them, and silently attributing them is the false
accountability this field exists to prevent. Leaving them unowned makes the
gap visible, and the importer's grouped warnings name each unmatched
frontmatter owner with a count.

Discussed and **not** connected, each a small piece of work when wanted:

- a project-level default for `{category}.user_id`, which has no parent today;
- QMS accountability for a project's documentation, beside
  `{topic_profile}.user_id` which records only who *authorised* a profile;
- a document-control authority line on the published site;
- a notification target for project-level events, instead of everyone at
  MANAGER;
- a default meeting chair for `$g_meeting_project_id`.

## 6. `{project}.classification`

Kept and wired, rather than dropped (owner decision 2026-10-23): the
classification scheme for projects and documents is unresolved, and the field
is expected to matter once it is settled.

It is carried as **free text**, exactly as the document field is — a plain
input, no enum and no validation — because inventing a scheme now would have to
be undone. `{dwg}.classification` works the same way and defaults to
`UNCLASSIFIED` on the document create form.

Nothing reads it yet. That is deliberate.

### Two more dead columns, not touched

`{project}.reference_url1` and `{project}.reference_url2` are also unwired —
no read or write anywhere in `project_api.php`, the manage pages or the
commands. They were not part of this decision and have been left alone.
Wiring them would be the same small piece of work as classification if they
turn out to be wanted.

Before this work, `due_date` was the only wired Doctis addition to
`{project}`.

## 7. The hierarchy cache, fixed

`project_hierarchy_cache()` builds once per request and returns early
afterwards, and nothing invalidated it. Code that added a hierarchy row and
then resolved an owner in the same request would have seen the old tree.

This was first left alone and documented as a sharp edge, on the grounds that
it is shared MantisBT code. That was the wrong call: the owner clarified on
2026-10-23 that shared code should be modified where justified, and a missing
invalidation that a new feature depends on qualifies. So
`project_hierarchy_clear_cache()` now exists and
`project_hierarchy_add()`, `_update()`, `_remove()` and `_remove_all()` all
call it.

Fixed alongside it: `$g_cache_show_disabled_all` was declared and used
nowhere, while `$g_cache_project_inheritance_all` was used three times and
never declared — a typo in the original line, which worked only because PHP
treats an undeclared global as null.

## 8. Test record

`tests/Mantis/ProjectOwnerTest.php` — **10 tests, 33 assertions, pass**:
create round-trip, no owner anywhere, two-level inheritance, own-owner
override, clearing an owner to restore inheritance, a disabled owner reporting
as none, an update that omits the fields leaving them alone, the cycle guard,
the document-owner fallback, and the successor-suggestion fallback.

Full `mantis` suite: **380 tests, 1 pre-existing skip**.

Also verified directly against the rebuilt VM, which holds live's user table:
a parent owned by `venkat` with classification `INTERNAL`, a child and
grandchild inheriting it, the child overriding to `yogesh` and the grandchild
following the child, an owner set to a disabled account reporting as none, and
the classification surviving an update that did not mention it.
