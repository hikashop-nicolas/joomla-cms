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
- [ ] **Tests.** None exist. Need unit tests (`tests/Unit`) for `CustomizeMode` + each plugin's
      ajax actions, and ideally system/Cypress tests (`tests/System`) for the flow.
- [ ] **Update SQL.** Only fresh-install `installation/sql/{mysql,postgresql}/base.sql` got the 5
      plugin rows. Add `administrator/components/com_admin/sql/updates/{mysql,postgresql}/<ver>-<date>.sql`
      so existing sites enable the plugins on upgrade.
- [ ] **Asset pipeline -> upstream conventions.** Move from path-based `registerAndUseScript`
      to manifest-based registration (the `joomla.asset.json` files already exist), use a canonical
      media path, ensure the build copies the manifests into `media/`, and drop the local-only
      `build/build-customize.mjs` (the standard build already compiles `build/media_source`).
- [ ] **PHP code standards.** Run `php-cs-fixer` (composer dev deps not installed locally) and fix.

## Likely required by reviewers
- [ ] Dedicated security review of the file-writing handlers (template + language overrides).
- [ ] Cross-origin / postMessage fallback, or document the same-origin-only limitation in the PR.
- [ ] ES modules: maintainers may want `.es6.js` modules instead of the current `.es5.js` IIFEs.
- [ ] Documentation: PR description + manual test steps + user docs.

## Polish
- [ ] Real screen-reader pass (VoiceOver / NVDA), only DOM-verified so far.
- [ ] Document/guard "move a module to a position the template doesn't render" (saves but vanishes
      from the preview; drag has the same behaviour).
- [ ] Self-review of the core-change footprint (HtmlView event, ModulesRenderer events,
      CustomizeMode, Language/Text recordString, SiteApplication, com_menus) to minimise/justify.
