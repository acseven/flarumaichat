# Repo notes

Fork of `wszdb/flarumaichat` (Flarum 1.x AI chat extension). `main` carries
the local fixes; `chdk-patches` is pushed as a mirror of `main`, because the
site that runs this fork names that branch in its composer constraint.
`upstream-patches` holds work meant to go back upstream.

## Public repo — keep it clean

This repository is public. Never put in code, comments, locale strings,
docs or commit messages:

- API keys, tokens or passwords
- host names, SSH aliases, server paths, database names or credentials
- forum URLs, user names, user IDs or e-mail addresses
- local machine paths (`/Users/...`, `/home/...`) or references to sibling
  checkouts on the same machine

Write settings-driven code instead of site-specific values. Anything
site-specific belongs in the forum's admin settings, not in this repo.

## Settings this fork adds

All are rows of `wszdb-flarumaichat.*`, edited on the extension page.

| Setting | What it does |
| --- | --- |
| `blocked-tags` | Tag ids the assistant never answers in. Blocking a parent blocks its children. |
| `enabled-tags` | Tag ids it may start an answer in on its own. Empty means every tag. |
| `blocked-groups` | Group ids whose members' posts it never answers on its own. |
| `manual-override-groups` | Group ids whose posts a hand-made request answers anyway, past a group or tag block. Private discussions still stay closed. |
| `reply_in_private` | Whether it answers inside private discussions at all. |
| `context_files` | Local data files it may quote from. |
| `context_chars` | Characters of file and linked-thread context one prompt may carry. |
| `linked_threads` | Quote threads of this forum that a post links to. |
| `glm_thinking`, `web_search`, `web_search_domains` | z.ai GLM options. |

Two guards decide whether the assistant may answer: `Silence::reason()` (the
discussion and the post's author) and, for the post control only,
`BlockedGroups::manualOverride()`. `max_tokens` caps the answer, not the
context.

Self-checks, no framework and no database:

```sh
php -d zend.assertions=1 scripts/test-silence.php
php -d zend.assertions=1 scripts/test-context-files.php
php -d zend.assertions=1 scripts/test-linked-discussions.php
php scripts/test-usage.php
```

## Build

`js/dist/*.js` is committed and shipped as-is. After changing `js/src`:

```sh
npm --prefix js install
npm --prefix js run build
```
