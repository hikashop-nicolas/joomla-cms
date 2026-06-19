/**
 * Cassiopeia's own Customize-mode behaviors.
 *
 * Loaded by the Customize host (com_templates) when Cassiopeia is the active template, by resolved
 * media path. It uses only the public JoomlaCustomize (JC) API. Moving, adding, removing and hiding
 * layout blocks is handled generically by plg_customize_layout via the data-customize contract the
 * core LayoutHelper emits, so the only thing this template ships is its own resize behaviour: a drag
 * handle on each sidebar boundary that changes the main/sidebar width ratio (grid track sizes are
 * template-specific), persisted to the template's customize.css via the com_templates endpoint.
 */
import JC from 'customize.api';

(function () {
  'use strict';

  // Cassiopeia's main grid area is four 19.875rem tracks (= 79.5rem). We redistribute that fixed
  // width between side-left / component / side-right, so the centered layout keeps its overall size.
  const MAIN_REM = 79.5;
  const MIN_SIDE_REM = 8;
  const MIN_COMP_REM = 24;

  const t = (key, fallback) => JC.text(key, fallback);

  // --- Ratio drag -------------------------------------------------------------
  // Build the lg grid override for the three main tracks (in rem, summing to MAIN_REM).
  function columnsRule(sideL, comp, sideR) {
    return '[full-start] minmax(0, 1fr) [main-start] '
      + sideL.toFixed(3) + 'rem ' + comp.toFixed(3) + 'rem ' + sideR.toFixed(3) + 'rem'
      + ' [main-end] minmax(0, 1fr) [full-end]';
  }

  // Three-column area map matching columnsRule (side-l, comp, side-r between the margins).
  const AREAS = '". banner banner banner ." ". top-a top-a top-a ." ". top-b top-b top-b ."'
    + ' ". side-l comp side-r ." ". bot-a bot-a bot-a ." ". bot-b bot-b bot-b ."';

  // Current rem widths of the three main tracks, read from the live grid (so a drag starts from the
  // current ratio whether or not customize.css is already applied).
  function currentWidths(doc, grid) {
    const px = (sel, fallbackRem) => {
      const el = grid.querySelector(sel);
      const rem = px2rem(doc, el ? el.getBoundingClientRect().width : 0);
      return rem > 0 ? rem : fallbackRem;
    };
    let sideL = px('.container-sidebar-left', MAIN_REM / 4);
    let sideR = px('.container-sidebar-right', MAIN_REM / 4);
    let comp = MAIN_REM - sideL - sideR;

    if (comp < MIN_COMP_REM) {
      comp = MIN_COMP_REM;
    }

    return { sideL, comp, sideR };
  }

  function px2rem(doc, px) {
    const root = parseFloat(doc.defaultView.getComputedStyle(doc.documentElement).fontSize) || 16;
    return px / root;
  }

  function applyLive(grid, w) {
    grid.style.gridTemplateColumns = columnsRule(w.sideL, w.comp, w.sideR);
    grid.style.gridTemplateAreas = AREAS;
  }

  function persist(w) {
    const css = '@media (min-width: 992px) {\n'
      + '  .site-grid {\n'
      + '    grid-template-columns: ' + columnsRule(w.sideL, w.comp, w.sideR) + ';\n'
      + '    grid-template-areas: ' + AREAS + ';\n'
      + '  }\n'
      + '}\n';

    return JC.templateAction('writeCustomizeCss', { css });
  }

  // Wire a boundary handle: drag it OR use the arrow keys to trade width between the component and that
  // sidebar. Exposed as a WAI-ARIA window splitter so it is keyboard-operable, not pointer-only.
  function wireHandle(doc, handle) {
    const grid = doc.querySelector('.site-grid');

    if (!grid) {
      return;
    }

    const edge = handle.getAttribute('data-customize-edge'); // 'left' | 'right'
    const win = doc.defaultView;
    const sideKey = edge === 'left' ? 'sideL' : 'sideR';
    const otherKey = edge === 'left' ? 'sideR' : 'sideL';

    handle.setAttribute('role', 'separator');
    handle.setAttribute('aria-orientation', 'vertical');
    handle.setAttribute('tabindex', '0');
    handle.setAttribute('aria-valuemin', '0');
    handle.setAttribute('aria-valuemax', '100');
    handle.setAttribute('aria-label', handle.getAttribute('title') || t('TPL_CASSIOPEIA_CUSTOMIZE_RATIO_HINT', 'Drag to change the main / sidebar width'));

    function updateAria(side) {
      const pct = Math.round((side / MAIN_REM) * 100);
      handle.setAttribute('aria-valuenow', String(pct));
      handle.setAttribute('aria-valuetext', pct + '%');
    }

    // Apply this edge's sidebar width (clamped), giving the rest to the component; render + return it.
    function applyWidths(start, side) {
      const w = { sideL: start.sideL, sideR: start.sideR };
      w[sideKey] = Math.max(MIN_SIDE_REM, Math.min(side, MAIN_REM - MIN_COMP_REM - start[otherKey]));
      w.comp = MAIN_REM - w.sideL - w.sideR;
      applyLive(grid, w);
      updateAria(w[sideKey]);

      return w;
    }

    updateAria(currentWidths(doc, grid)[sideKey]);

    handle.addEventListener('pointerdown', function (down) {
      down.preventDefault();
      down.stopPropagation();

      const start = currentWidths(doc, grid);
      const startX = down.clientX;
      let live = { sideL: start.sideL, comp: start.comp, sideR: start.sideR };

      function onMove(move) {
        // Dragging the right handle right (or the left handle left) widens the component.
        let delta = px2rem(doc, move.clientX - startX);

        if (edge === 'left') {
          delta = -delta;
        }

        live = applyWidths(start, start[sideKey] - delta);
      }

      function onUp() {
        win.removeEventListener('pointermove', onMove);
        win.removeEventListener('pointerup', onUp);

        persist(live).then(function (res) {
          if (res && res.success) {
            JC.ui.toast(doc, t('TPL_CASSIOPEIA_CUSTOMIZE_RATIO_SAVED', 'Layout width saved.'));
            JC.reloadFrame();
          } else {
            JC.ui.toast(doc, t('TPL_CASSIOPEIA_CUSTOMIZE_SAVE_FAILED', 'Could not save the layout width.'));
            applyLive(grid, start);
          }
        });
      }

      win.addEventListener('pointermove', onMove);
      win.addEventListener('pointerup', onUp);
    });

    // Keyboard: the arrow pointing toward the component widens it; Home/End to the limits. The save is
    // debounced and does NOT reload (a reload would drop keyboard focus from the handle).
    const towardComponent = edge === 'right' ? 'ArrowRight' : 'ArrowLeft';
    const awayFromComponent = edge === 'right' ? 'ArrowLeft' : 'ArrowRight';
    let saveTimer = null;

    handle.addEventListener('keydown', function (e) {
      if ([towardComponent, awayFromComponent, 'Home', 'End'].indexOf(e.key) === -1) {
        return;
      }

      e.preventDefault();

      const start = currentWidths(doc, grid);
      const step = 2; // rem per press
      let side = start[sideKey];

      if (e.key === towardComponent) {
        side -= step;
      } else if (e.key === awayFromComponent) {
        side += step;
      } else if (e.key === 'Home') {
        side = MIN_SIDE_REM;
      } else if (e.key === 'End') {
        side = MAIN_REM - MIN_COMP_REM - start[otherKey];
      }

      const live = applyWidths(start, side);
      win.clearTimeout(saveTimer);
      saveTimer = win.setTimeout(function () {
        persist(live).then(function (res) {
          JC.ui.toast(doc, (res && res.success)
            ? t('TPL_CASSIOPEIA_CUSTOMIZE_RATIO_SAVED', 'Layout width saved.')
            : t('TPL_CASSIOPEIA_CUSTOMIZE_SAVE_FAILED', 'Could not save the layout width.'));
        });
      }, 400);
    });
  }

  // --- Swap sidebars ----------------------------------------------------------
  // Move the modules between the left and right sidebars (content-level, via the position plugin), so
  // a sidebar can switch sides without touching the grid (and so never conflicting with the ratio).
  // Shown only when the page actually has a sidebar.
  function buildSwapSidebars() {
    const panel = JC.panel && JC.panel();

    if (!panel) {
      return;
    }

    const doc = window.document;
    const btn = doc.createElement('button');
    btn.type = 'button';
    btn.className = 'customize-swap-sidebars btn btn-outline-secondary btn-sm';
    btn.textContent = t('TPL_CASSIOPEIA_CUSTOMIZE_SWAP_SIDEBARS', 'Swap sidebars');
    btn.style.display = 'none';
    panel.appendChild(btn);

    btn.addEventListener('click', function () {
      JC.callAction('position', 'swap', { a: 'sidebar-left', b: 'sidebar-right' }).then(function (r) {
        if (r && r.success) {
          JC.reloadFrame();
        } else {
          JC.ui.toast(doc, t('TPL_CASSIOPEIA_CUSTOMIZE_SWAP_FAILED', 'Could not swap the sidebars.'));
        }
      }).catch(function () {
        JC.ui.toast(doc, t('TPL_CASSIOPEIA_CUSTOMIZE_SWAP_FAILED', 'Could not swap the sidebars.'));
      });
    });

    return btn;
  }

  const swapBtn = buildSwapSidebars();

  JC.on('customize:frame-ready', function (e) {
    const doc = e.detail && e.detail.doc;

    if (!doc) {
      return;
    }

    Array.prototype.forEach.call(doc.querySelectorAll('.customize-region-boundary'), function (handle) {
      wireHandle(doc, handle);
    });

    if (swapBtn) {
      const hasSidebar = doc.querySelector('.container-sidebar-left, .container-sidebar-right');
      swapBtn.style.display = hasSidebar ? '' : 'none';
    }
  });
})();
