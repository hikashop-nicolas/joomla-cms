# Customize mode: PR-readiness checklist

Working notes for upstreaming the Customize feature (branch `feature/customize-mode`).
This file tracks what is left before opening a joomla-cms PR; remove it before submitting.

Audit date: 2026-06-10.

## Already in good shape (verified)
- ESLint passes clean on the customize engine + plugin JS (`build/eslint.config.mjs`).
- No debug leftovers (no console.log / var_dump / TODO / FIXME in customize source).
- Permissions: every ajax action checks the CSRF token and a per-item ACL
  (`core.edit`/`core.delete`/`core.create` on `com_modules.module.<id>` etc.; the file-writing
  view/language handlers gate on `core.admin`).
- View override writer is hardened: component/view/layout/block/template are sanitized
  (no path traversal), and it never overwrites an existing override.
- Keyboard + ARIA + live-region announcements implemented (DOM-verified).

## Hard blockers
- [~] **Tests.** 21 unit tests passing: `CustomizeModeTest` (runtime: detect/isActive, recordString
      + isRecordable, recordSprintf); the event-handler tests for the module, position and view
      plugins (markup injection, drop zone, block wrapping); and the view override path sanitizer
      (extracted as `View::sanitizeOverrideRequest`, tested for correct paths, path-traversal
      stripping, and rejection of missing segments). Still need tests for the rest of the plugins'
      *ajax* actions (harder: static Session::checkToken + db + filesystem) and system/Cypress tests.
- [x] **Update SQL.** Done. Idempotent `INSERT ... WHERE NOT EXISTS` for the 5 plugins in
      `administrator/components/com_admin/sql/updates/{mysql,postgresql}/6.2.0-2026-06-10.sql`
      (targeting 6.2.0; validated on the test DB: applies clean, stays at 5 rows).
- [~] **Asset pipeline -> upstream conventions.** Progress: the manifests now use convention names
      (`customize.*`, `plg_customize_*.admin`), `build/build-customize.mjs` copies them into `media/`,
      and the standard build copies `media_source` wholesale too, so the manifests reach `media/`.
      BLOCKED on the registration switch: tried `$wa->getRegistry()->addExtensionRegistryFile('customize')`
      + `useStyle`/`useScript` (the same runtime pattern com_content uses for com_contenthistory). In
      this view it registers the assets (assetExists() is true, 12 registry files) but they never
      render into the head, with either `com_menus.customize.*` or `customize.*` naming and the cache
      cleared; the .min files are valid. So path-based `registerAndUseScript` is retained for now.
      Needs WAM-internals investigation, or the canonical move of the engine into com_menus's
      auto-loaded `media/com_menus/joomla.asset.json`. The local-only `build/build-customize.mjs`
      should also be dropped for the PR (the standard build compiles `build/media_source`).
- [~] **PHP code standards.** Installing composer dev deps to run `php-cs-fixer` + `phpunit`.

## Likely required by reviewers
- [~] Dedicated security review of the file-writing handlers. The view override path resolver is now
      extracted and unit-tested (no traversal possible); the language override writer and a broader
      pass still want review.
- [ ] Cross-origin / postMessage fallback, or document the same-origin-only limitation in the PR.
- [ ] ES modules: maintainers may want `.es6.js` modules instead of the current `.es5.js` IIFEs.
- [~] Documentation: PR description + manual test steps drafted in `PR_DESCRIPTION.md`. User docs
      (manual.joomla.org) still to write.

## Polish
- [ ] Real screen-reader pass (VoiceOver / NVDA), only DOM-verified so far.
- [ ] Document/guard "move a module to a position the template doesn't render" (saves but vanishes
      from the preview; drag has the same behaviour).
- [ ] Self-review of the core-change footprint (HtmlView event, ModulesRenderer events,
      CustomizeMode, Language/Text recordString, SiteApplication, com_menus) to minimise/justify.
