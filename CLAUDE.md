# Repo notes

Fork of `wszdb/flarumaichat` (Flarum 1.x AI chat extension). Branch
`chdk-patches` carries the local fixes; `main` tracks upstream.

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

## Build

`js/dist/*.js` is committed and shipped as-is. After changing `js/src`:

```sh
npm --prefix js install
npm --prefix js run build
```
