#!/usr/bin/env python3
"""
Doctis stress-test SQL generator.

Generates a self-contained SQL script that inserts a large synthetic dataset
into an existing Doctis database (post-install, post-sample-data-load).

Usage:
    # Generate and load in one step:
    python3 admin/tools/doctis-generate-stress-data.py | \
        ssh hcr@vaio "mysql doctis"

    # Or save first then load:
    python3 admin/tools/doctis-generate-stress-data.py > /tmp/stress.sql
    cat /tmp/stress.sql | ssh hcr@vaio "mysql doctis"

Tune the CONSTANTS block below, then re-run.  A fresh reset is required
before each load (the INSERTs are not idempotent):

    ssh hcr@vaio "echo 'yes' | sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"
    ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-drop-and-create-new-database.sh"
    ssh hcr@vaio "echo 'yes' | bash /var/www/html/doctis/admin/tools/doctis-load-sample-data.sh"
"""

import hashlib
import random
import time

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# CONSTANTS — tune these before each run
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

N_PROJECTS       = 5    # top-level projects
N_SUB            = 2    # sub-projects per top-level project
N_SUBSUB         = 1    # sub-sub-projects per sub-project (set 0 to omit)
N_DOCS_PER_PRJ   = 5    # documents at EACH project level (top + sub + subsub)
N_ISSUES_PER_DOC = 3    # bug/issue rows per document
N_USERS          = 20   # stress users to add (above the existing sample accounts)
N_LICENSES       = 10   # stress licenses to add (above the existing sample licenses)
N_NEWS           = 200   # news stories spread across top-level projects

# ── Starting IDs — must not collide with existing seed/sample data ────────────
#
# After a fresh install + doctis-load-sample-data.sh the following IDs are used:
#   project  : id 1           (example)
#   user     : ids 1..15      (1=administrator, 2..8=role accounts, 9..15=LOTR)
#   license  : ids 1..21      (21 sample ITAR/IP licenses)
#   category : id 1           (global General, project_id=0)
#   dwg_text : id 1           (Empty placeholder)
#   documents: id 1           (Empty placeholder)
#   dwg      : id 1           (archived placeholder)
#   bug / bug_text: no rows   (start at 1)
#   news             : no rows (start at 1)

FIRST_PROJECT_ID  = 2
FIRST_USER_ID     = 16   # 1 admin + 7 role + 7 LOTR = 15 used
FIRST_LICENSE_ID  = 22   # 21 sample licenses
FIRST_DWG_TEXT_ID = 2    # id=1 is the Empty placeholder
FIRST_DOCUMENT_ID = 2    # id=1 is the Empty placeholder
FIRST_DWG_ID      = 2    # id=1 is the archived placeholder
FIRST_BUG_TEXT_ID = 1
FIRST_BUG_ID      = 1

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# MantisBT / Doctis enum values
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ACCESS_REPORTER    = 25
VIEW_PUBLIC        = 10
BUG_STATUS_NEW     = 10
DWG_STATUS_PENDING = 110
PRIORITY_NORMAL    = 30
SEVERITY_MINOR     = 50

BATCH_SIZE = 500   # rows per INSERT statement
BASE_TS    = int(time.time())
RNG_SEED   = 42


# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# Helpers
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

def _esc(s):
    return str(s).replace('\\', '\\\\').replace("'", "\\'")

def _v(val):
    if val is None:
        return 'NULL'
    if isinstance(val, int):
        return str(val)
    return f"'{_esc(val)}'"

def _cookie(seed):
    """64-char unique cookie string derived from seed."""
    return hashlib.sha256(f'stress-{seed}-{BASE_TS}'.encode()).hexdigest()

def _blank_pw():
    """MD5('') — blank password matching sample data convention."""
    return 'd41d8cd98f00b204e9800998ecf8427e'


class Batcher:
    """
    Accumulates INSERT rows and emits them as batched statements.

    Auto-flush is disabled — call flush() explicitly to control emit order.
    All rows are held in memory until flush() is called, which is fine for
    datasets of a few thousand rows (the intended stress-test sizes).
    """

    def __init__(self, table, columns):
        self.table   = table
        self.columns = columns
        self._rows   = []

    def add(self, *values):
        if len(values) != len(self.columns):
            raise ValueError(
                f'{self.table}: expected {len(self.columns)} columns, '
                f'got {len(values)}'
            )
        self._rows.append(f"({','.join(_v(v) for v in values)})")

    def flush(self):
        if not self._rows:
            return
        cols = ','.join(f'`{c}`' for c in self.columns)
        # Emit rows in chunks so each INSERT statement stays under BATCH_SIZE
        for start in range(0, len(self._rows), BATCH_SIZE):
            chunk = self._rows[start:start + BATCH_SIZE]
            print(f"INSERT INTO `{self.table}` ({cols}) VALUES")
            print(',\n'.join(chunk) + ';')
        print()
        self._rows = []


# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# Main
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

def main():
    random.seed(RNG_SEED)

    total_projects = N_PROJECTS * (1 + N_SUB + N_SUB * N_SUBSUB)
    # +N_DOCS_PER_PRJ for the pre-existing "example" project (id=1)
    total_docs     = (total_projects + 1) * N_DOCS_PER_PRJ
    total_issues   = total_docs * N_ISSUES_PER_DOC

    # ── Header ────────────────────────────────────────────────────────────────
    print('-- Doctis stress-test dataset')
    print(f'-- Generated : {time.strftime("%Y-%m-%d %H:%M:%S")}')
    print(f'-- Projects  : {N_PROJECTS} top × {N_SUB} sub × {N_SUBSUB} subsub = {total_projects} stress + 1 example = {total_projects + 1} total')
    print(f'-- Documents : {N_DOCS_PER_PRJ} per project = {total_docs} total (incl. example)')
    print(f'-- Issues    : {N_ISSUES_PER_DOC} per doc = {total_issues} total')
    print(f'-- Users     : {N_USERS}   Licenses: {N_LICENSES}   News: {N_NEWS}')
    print()
    print('USE doctis;')
    print('SET NAMES utf8mb3;')
    print('SET foreign_key_checks = 0;')
    print('START TRANSACTION;')
    print()

    # ── Batchers ──────────────────────────────────────────────────────────────
    user_b = Batcher('user', [
        'username', 'realname', 'email', 'password',
        'enabled', 'protected', 'access_level',
        'login_count', 'lost_password_request_count', 'failed_login_count',
        'cookie_string', 'last_visit', 'date_created',
        'position_title', 'company', 'phone', 'department',
        'meeting_invite', 'email_secondary',
    ])

    lic_b = Batcher('license', [
        'id', 'project_id', 'enabled', 'name', 'match_str', 'type',
        'status', 'view_state', 'access_min', 'description',
    ])

    lic_user_b = Batcher('license_user_list', [
        'user_id', 'license_id', 'status', 'date_added',
    ])

    proj_b = Batcher('project', [
        'id', 'name', 'status', 'enabled', 'view_state', 'access_min',
        'file_path', 'description', 'category_id', 'inherit_global',
        'reference_url1', 'reference_url2', 'classification', 'due_date',
    ])

    hier_b = Batcher('project_hierarchy', [
        'child_id', 'parent_id', 'inherit_parent',
    ])

    proj_user_b = Batcher('project_user_list', [
        'project_id', 'user_id', 'access_level',
    ])

    dwg_text_b = Batcher('dwg_text', [
        'id', 'description', 'steps_to_reproduce', 'additional_information',
    ])

    documents_b = Batcher('documents', [
        'id', 'title', 'author', 'publisher', 'reference', 'number',
        'edition', 'revision', 'link_url', 'classification',
        'revision_date', 'release_date',
    ])

    dwg_b = Batcher('dwg', [
        'id', 'project_id', 'creator_id', 'handler_id', 'duplicate_id',
        'category_id', 'document_id', 'enabled', 'status', 'priority',
        'view_state', 'version', 'discipline', 'classification', 'summary',
        'link_url', 'date_submitted', 'last_updated', 'due_date',
        'dwg_text_id', 'sticky',
    ])

    bug_text_b = Batcher('bug_text', [
        'id', 'description', 'steps_to_reproduce', 'additional_information',
    ])

    bug_b = Batcher('bug', [
        'id', 'project_id', 'reporter_id', 'handler_id', 'duplicate_id',
        'priority', 'severity', 'reproducibility', 'status', 'resolution',
        'projection', 'eta', 'bug_text_id', 'os', 'os_build', 'platform',
        'version', 'fixed_in_version', 'build', 'profile_id', 'view_state',
        'summary', 'sponsorship_total', 'sticky', 'target_version',
        'category_id', 'date_submitted', 'due_date', 'last_updated',
        'document_id', 'document_sha',
    ])

    news_b = Batcher('news', [
        'project_id', 'poster_id', 'view_state', 'announcement',
        'headline', 'body', 'last_modified', 'date_posted',
    ])

    # ── Users ─────────────────────────────────────────────────────────────────
    user_ids = list(range(FIRST_USER_ID, FIRST_USER_ID + N_USERS))
    print('-- Users')
    for i, uid in enumerate(user_ids, 1):
        user_b.add(
            f'stress_user_{i}',
            f'Stress User {i}',
            f'stress.user.{i}@example.com',
            _blank_pw(),
            1, 0, ACCESS_REPORTER,
            0, 0, 0,
            _cookie(f'user-{i}'),
            BASE_TS, BASE_TS,
            f'Test Engineer {i}', 'Stress Corp', '', 'QA', 0, '',
        )
    user_b.flush()

    # ── Licenses ──────────────────────────────────────────────────────────────
    license_ids = list(range(FIRST_LICENSE_ID, FIRST_LICENSE_ID + N_LICENSES))
    print('-- Licenses')
    for i, lid in enumerate(license_ids, 1):
        lic_b.add(
            lid, 0, 1,
            f'Stress License {i}',
            f'STRESSLIC{i:04d}',
            'Test License',
            10, VIEW_PUBLIC, 10,
            f'Synthetic stress-test license {i}',
        )
    lic_b.flush()

    # Assign up to 3 random licenses to each stress user
    print('-- License-user assignments')
    for uid in user_ids:
        sample = random.sample(license_ids, min(3, len(license_ids)))
        for lid in sample:
            lic_user_b.add(uid, lid, 10, BASE_TS)
    lic_user_b.flush()

    # ── Projects, hierarchy, user assignments, documents, issues ──────────────
    proj_id     = FIRST_PROJECT_ID
    dwg_text_id = FIRST_DWG_TEXT_ID
    doc_id      = FIRST_DOCUMENT_ID
    dwg_id      = FIRST_DWG_ID
    bug_text_id = FIRST_BUG_TEXT_ID
    bug_id      = FIRST_BUG_ID
    doc_seq = 0   # global doc sequence for unique references

    def pick_user(seq):
        return user_ids[seq % len(user_ids)]

    def emit_project(pid, name, parent_pid):
        proj_b.add(
            pid, name, 10, 1, VIEW_PUBLIC, 10,
            '', f'Stress test project: {name}',
            1,   # category_id=1 (global General)
            1,   # inherit_global
            '', '', 'UNCLASSIFIED', BASE_TS,
        )
        if parent_pid is not None:
            hier_b.add(pid, parent_pid, 0)
        for uid in user_ids:
            proj_user_b.add(pid, uid, ACCESS_REPORTER)

    def emit_docs(pid, label):
        nonlocal dwg_text_id, doc_id, dwg_id
        nonlocal bug_text_id, bug_id, doc_seq

        for d in range(1, N_DOCS_PER_PRJ + 1):
            doc_seq += 1
            dt_id  = dwg_text_id
            d_id   = doc_id
            dw_id  = dwg_id
            title  = f'{label} Doc {d}'
            ref    = f'STR-{doc_seq:06d}'

            dwg_text_b.add(dt_id, f'Description of {title}.', '', '')
            documents_b.add(
                d_id, title, 'Stress Author', 'Stress Corp',
                ref, f'{doc_seq:06d}', 'Ed 1', 'Rev A',
                '', 'UNCLASSIFIED', BASE_TS, BASE_TS,
            )
            dwg_b.add(
                dw_id, pid, pick_user(doc_seq), 0, 0,
                1,   # category_id=1 (global General)
                d_id, 1, DWG_STATUS_PENDING, PRIORITY_NORMAL,
                VIEW_PUBLIC, '', 'Stress', 'UNCLASSIFIED', title,
                '', BASE_TS, BASE_TS, BASE_TS, dt_id, 0,
            )

            dwg_text_id += 1
            doc_id      += 1
            dwg_id      += 1

            for iss in range(1, N_ISSUES_PER_DOC + 1):
                bt_id   = bug_text_id
                b_id    = bug_id
                summary = f'Issue {iss} on {title}'

                bug_text_b.add(bt_id, f'Stress issue: {summary}', '', '')
                bug_b.add(
                    b_id, pid, pick_user(bug_id), 0, 0,
                    PRIORITY_NORMAL, SEVERITY_MINOR, 10,
                    BUG_STATUS_NEW, 10, 10, 10,
                    bt_id, '', '', '', '', '', '', 0,
                    VIEW_PUBLIC, summary,
                    0, 0, '',
                    1,   # category_id=1 (global General)
                    BASE_TS, BASE_TS, BASE_TS,
                    dw_id, '',   # document_id = dwg.id, document_sha = ''
                )

                bug_text_id += 1
                bug_id      += 1

    # ── Populate the existing "example" project (id=1) so the default UI view
    #    shows data immediately without requiring the user to switch projects.
    print('-- example project (id=1): user assignments + documents + issues')
    for uid in user_ids:
        proj_user_b.add(1, uid, ACCESS_REPORTER)
    proj_user_b.flush()
    emit_docs(1, 'example')

    # Collect top-level project IDs for news generation after the main loop
    top_project_ids = [1]

    for p in range(1, N_PROJECTS + 1):
        top_pid  = proj_id
        top_name = f'Project {p}'
        top_project_ids.append(top_pid)

        emit_project(proj_id, top_name, None)
        proj_id += 1
        emit_docs(top_pid, top_name)

        for s in range(1, N_SUB + 1):
            sub_pid  = proj_id
            sub_name = f'Sub Project {p}.{s}'

            emit_project(proj_id, sub_name, top_pid)
            proj_id += 1
            emit_docs(sub_pid, sub_name)

            for ss in range(1, N_SUBSUB + 1):
                ss_pid  = proj_id
                ss_name = f'Sub Sub Project {p}.{s}.{ss}'

                emit_project(proj_id, ss_name, sub_pid)
                proj_id += 1
                emit_docs(ss_pid, ss_name)

    # ── News — spread N_NEWS stories across top-level projects (cycling) ──────
    for n in range(N_NEWS):
        pid = top_project_ids[n % len(top_project_ids)]
        news_b.add(
            pid, pick_user(n), VIEW_PUBLIC, 0,
            f'News story {n + 1}',
            f'Stress test news item {n + 1} for project {pid}.',
            BASE_TS, BASE_TS,
        )

    # Emit tables in dependency order (projects before docs, docs before bugs)
    print('-- Projects')
    proj_b.flush()
    print('-- Project hierarchy')
    hier_b.flush()
    print('-- Project-user assignments')
    proj_user_b.flush()
    print('-- Document metadata (documents + dwg_text + dwg)')
    dwg_text_b.flush()
    documents_b.flush()
    dwg_b.flush()
    print('-- Issues (bug_text + bug)')
    bug_text_b.flush()
    bug_b.flush()
    print('-- News')
    news_b.flush()

    # ── Footer ────────────────────────────────────────────────────────────────
    print('COMMIT;')
    print('SET foreign_key_checks = 1;')
    print()
    print('-- Dataset summary:')
    print(f'--   Users        : {N_USERS}')
    print(f'--   Licenses     : {N_LICENSES}')
    print(f'--   Projects     : {total_projects} stress + 1 example')
    print(f'--   Documents    : {total_docs}  (incl. {N_DOCS_PER_PRJ} in example project)')
    print(f'--   Issues       : {total_issues}')
    print(f'--   News stories : {N_NEWS}')


if __name__ == '__main__':
    main()
