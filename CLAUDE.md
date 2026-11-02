# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

IANSEO module (archery competition management software) that publishes a PNG of the places left in each session of a competition: per session, per bow type (quotas of the SelfRegistration module) and per distance (targets entered by the organiser). Code, identifiers and comments are in English. The admin page is translated French/English (`SessionPlaces/i18n.js`, `sp_t()` in `lib.php`); the image has its own texts and language setting (`sp_img_t()` in `core.php`). Sister modules with the same conventions: `../ianseo-self-registration`, `../ianseo-entry-import`.

## Commands

```sh
node --test tests/*.test.js          # translations (same keys fr/en, image texts too), latest build versioning
php tests/core.test.php              # pure logic of core.php (no IANSEO, no database)
for f in SessionPlaces/*.php; do php -l "$f"; done
```

No local PHP? Use `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/core.test.php`.

PHP, image and UI are verified against a real IANSEO in `dev/` (port 8091, compose project `sessionplaces-dev`, so it can run next to SelfRegistration's on 8089):

```sh
cd dev && IANSEO_DIR=/path/to/copy docker compose up -d --build && IANSEO_DIR=/path/to/copy ./seed.sh   # http://localhost:8091/
```

- `seed.sh` creates competition SP26 (FR rules, type 3, sub-rule 9 TAE: `CL%` 70 m, `CO%` 50 m; there is no BB) with 3 sessions; session 1 (2 × 2) is full.
- `SELFREG_DIR=../../ianseo-self-registration/SelfRegistration` mounts SelfRegistration. Its table is created by opening its page once (`Common/TourOn.php?ToId=…` first), then `seed.sh` adds pending/waiting-list requests and quotas.
- Reference IANSEO sources are in `../ianseo`, read-only.

## Architecture

**The module only reads.** It writes nothing but its own rows of `ModulesParameters`. SelfRegistration's table and settings are read when present (`sp_selfreg_table()`, `sp_selfreg_quotas()`), never written.

In `SessionPlaces/`:
- `core.php`: pure logic, tested by `tests/core.test.php`:
  - `sp_valid_query()` / `sp_valid_code()` / `sp_format()`: the public parameters (see Security);
  - `sp_like()`: MySQL LIKE in PHP, to match `div+class` with `TournamentDistances.TdClasses` (first match, table order), also for SelfRegistration rows (module table: no SQL join on string columns, collations may differ);
  - `sp_distance_label()`: label from the names first, without IANSEO's `-1`/`-2` suffixes ("70m-1" → "70 m"), else from the metres `TdDist1…`, else the name. IANSEO never reads `TdDist`: its Competition › Distances page saves only the names, so the metres are 0 on rows added there (`FECL%` in 26_18m_2) and stale on rows renamed there;
  - `sp_clean_settings()`: settings cleaned against existing sessions and distance labels, for reading and saving;
  - `sp_places()`: capacity `SesTar4Session × SesAth4Target`; per distance `targets × SesAth4Target`; free never negative;
  - `sp_fingerprint()`, `sp_etag()`, `sp_etag_matches()`; image texts, dates (no intl), `sp_wrap()`.
- `lib.php`: database access, parameters, `sp_data()`, cache, admin context, self-update (same mechanism as the sister modules, prefix `sp_`).
  - Taken places (`sp_usage()`): entries `EnAthlete=1` with `QuSession>0` (any status, like IANSEO's CheckSession.php) + SelfRegistration requests with `SrStatus=0`. Waiting list (3) takes no place, validated (1) counts through its entry.
  - `sp_data()` returns the places and their fingerprint. The fingerprint covers everything drawn (places, head of the competition, logo size, language), the module version and `render.php`'s mtime. **Anything new drawn in the image must be part of the fingerprint**, or the cache serves stale images.
- `render.php`: GD drawing, at 2× then reduced. Two formats (`$data['format']`, part of the fingerprint): `full` (`sp_layout()`, 1080 px: header with logo, cards, footer) and `compact` (`sp_layout_compact()`, 640 px: sessions only). Both share `sp_tools()` (colours, text and box helpers) and run twice (measure, then draw); `sp_card()` is measured with no-op drawing closures. IANSEO's fonts (`Common/tcpdf/fonts/Roboto-*.ttf`, else FreeSans), GD built-in font as last resort. The footer time is the drawing time (= last change).
- `image.php`: the **only public file** (see Security).
- `preview.php`: same image for the admin page (session + rights), even when unpublished; `?v=<hash>` only busts the browser cache, `&f=compact` for the compact format. The format is chosen on the admin page (address, HTML code, preview) and is not saved: both are always served.
- `index.php` + `admin.js` + `admin.css` + `api.php`: admin page (IANSEO template, `window.SP_CONTEXT`).
  - Rights: `AclParticipants`/`pAdvancedEntries` ReadWrite, like SelfRegistration. Updates require `AclRoot`.
  - `api.php` only accepts JSON bodies (a cross-site form cannot send them).
  - `admin.js` re-renders through `innerHTML`: every value goes through `esc()`/`th()`. Empty `targets` must reach JS as `{}`, not `[]` (`sp_context()`).
- `menu.php`: included by `Common/Menu.php` from both `get_which_menu` and `get_which_run_menu`; in the latter `$acl` and `$ret['PART']` may be missing.

## Security of image.php

- Method GET/HEAD (else 405), then `sp_valid_query()` on the **raw** `QUERY_STRING`: exactly `c=[A-Za-z0-9_.-]{1,8}`, optionally followed by the fixed `&f=compact` (else 400). Checked before IANSEO's `config.php`, whose `Globals.inc.php`/`config.php` react to GET parameters (`SetLanguage`, `ianseo-debug-session`, `Ianseo-debug-mode`).
- The code is only used in `sp_tour_id()` (StrSafe_DB); everything after uses the integer `ToId`. Never put the code in a path, header or the image.
- 404 with an empty body for unknown, unpublished, or no GD; a code refused by `sp_valid_code()` still runs the same lookup with ''.
- No PHP session: `SpNoSession` handler + `session.use_cookies=0` before `config.php`, then `Set-Cookie`/`Pragma`/`Expires`/`Last-Modified` removed (IANSEO sends no-cache headers). No `JsonOut()` / CORS.
- Texts from IANSEO go through `sp_clean_text()` (UTF-8, no control or bidi characters, length).

## Cache

- Folder `sys_get_temp_dir()/ianseo-sessionplaces-<sha1(module dir)>`, mode 0700; refused if it is a link, not writable or not owned by the web server user (then the image is drawn every time).
- File `<ToId>-<format>-<hash>.png`, written to a temp file then renamed, under `flock` on `<ToId>.lock`; other files of the competition in the same format removed after a new drawing.
- `ETag: W/"sp-<hash>"`, `Cache-Control: public, max-age=60, must-revalidate`; `If-None-Match` → 304 without drawing nor reading the file.

## Persistence

- Parameters stored like `setModuleParameter()` (serialized), the value always a JSON string; `sp_param_get/set($TourId, …)` use direct SQL, TourId 0 = installation-wide. `sp_param_get()` also reads SelfRegistration's (`$module`).
- Per competition: `settings` (public, lang, hidden, divisions, targets). Installation-wide: `channel`, `updateCheck`.
- `sp_purge_orphans()` (admin page load) removes rows of deleted competitions.

## Releases and self-update

Same as `../ianseo-self-registration`:
- a `vX.Y.Z` tag runs `release.yml` (stable release, marked Latest on GitHub, read through `/releases/latest`);
- `latest.yml` publishes the `latest` pre-release on every push to `main` (version `X.Y.(Z+1)-latest.<yyyymmddhhmm>.<sha>`, `scripts/latest-version.js`); update channels `stable` / `latest` (`nightly`, the former name, is read as `latest`);
- `version.json` stays `0.0.0-dev` in the repo;
- the archive must contain only `SessionPlaces/…` with `SessionPlaces/version.json`;
- the GitHub repo (`yet-another-quentin/ianseo-session-places`) must be public.

## Git

- Commit messages: [gitmoji](https://gitmoji.dev/) prefix (Unicode emoji), English, a single line, no `Co-Authored-By`.
- Commits are GPG-signed. If signing fails because pinentry cannot prompt, ask the user to run the commit.
