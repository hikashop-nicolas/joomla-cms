/**
 * Customize - Position plugin (admin side).
 *
 * Loaded in the com_menus Customize host page. Modules are marked server-side (ModulesRenderer) and
 * dragged by their toolbar title (the engine provides the generic drag mechanism). Dropping a module
 * onto another module (or an empty-position drop zone) reorders/moves it in place. Dropping it onto
 * the sticky "move to another position" bar greys the module and shows a position picker, so any
 * template position (including ones not rendered on the page) is reachable.
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
(function (window) {
  'use strict';

  var JC = window.JoomlaCustomize;

  if (!JC) {
    return;
  }

  function t(key, fallback) {
    return JC.text(key, fallback);
  }

  function reloadFrame() {
    var f = window.document.getElementById('customize-frame');

    if (f) {
      try {
        f.contentWindow.location.reload();
      } catch (e) {
        f.src = f.src;
      }
    }
  }

  function moduleSelector(position) {
    return '[data-customize-type="module"][data-customize-position="' + (window.CSS ? window.CSS.escape(position) : position) + '"]';
  }

  // --- In-place reorder (drop onto a module / empty-position zone) -------------

  function saveOrder(doc, position) {
    var ids = Array.prototype.map.call(doc.querySelectorAll(moduleSelector(position)), function (m) {
      return m.getAttribute('data-customize-id');
    });

    JC.callAction('position', 'reorder', { position: position, order: ids }).then(function (res) {
      if (res && res.success) {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVED', 'Order saved.'));
      } else {
        var reason = (res && res.message) || t('PLG_CUSTOMIZE_POSITION_UNKNOWN_ERROR', 'unknown error');
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_FAILED', 'Save failed: %s').replace('%s', reason));
      }
    }).catch(function () {
      JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_ERROR', 'Save error.'));
    });
  }

  // --- Sticky "move to another position" zone ---------------------------------

  var draggedModule = null;
  var sticky = null;
  var pending = null;

  function isModuleEl(el) {
    return el && el.getAttribute('data-customize-type') === 'module';
  }

  function removeSticky() {
    if (sticky && sticky.parentNode) {
      sticky.parentNode.removeChild(sticky);
    }
    sticky = null;
  }

  function cancelMove() {
    if (pending) {
      pending.classList.remove('customize-pending');
    }
    pending = null;
    removeSticky();
  }

  function openSelector(doc) {
    sticky.textContent = t('JGLOBAL_LOADING', 'Loading…');

    JC.callAction('position', 'positions', {}).then(function (res) {
      if (!res || !res.success) {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_LOAD_FAILED', 'Could not load positions.'));
        cancelMove();
        return;
      }

      sticky.textContent = '';
      sticky.classList.add('customize-sticky-form');

      var label = doc.createElement('span');
      label.textContent = t('PLG_CUSTOMIZE_POSITION_LABEL', 'Position');

      var sel = doc.createElement('select');
      var current = pending.getAttribute('data-customize-position');
      (res.positions || []).forEach(function (p) {
        var o = doc.createElement('option');
        o.value = p;
        o.textContent = p;
        if (p === current) {
          o.selected = true;
        }
        sel.appendChild(o);
      });

      var save = doc.createElement('button');
      save.type = 'button';
      save.className = 'customize-action customize-action-save';
      save.textContent = t('COM_MENUS_CUSTOMIZE_SAVE', 'Save');

      var cancel = doc.createElement('button');
      cancel.type = 'button';
      cancel.className = 'customize-action customize-action-cancel';
      cancel.textContent = t('COM_MENUS_CUSTOMIZE_CANCEL', 'Cancel');

      sticky.appendChild(label);
      sticky.appendChild(sel);
      sticky.appendChild(save);
      sticky.appendChild(cancel);

      cancel.addEventListener('click', cancelMove);

      save.addEventListener('click', function () {
        save.disabled = true;
        save.textContent = t('COM_MENUS_CUSTOMIZE_SAVING', 'Saving…');

        JC.callAction('position', 'move', { id: pending.getAttribute('data-customize-id'), position: sel.value }).then(function (r) {
          if (r && r.success) {
            // A fresh render shows the module in its new position (revealing it if it was hidden).
            pending = null;
            sticky = null;
            reloadFrame();
          } else {
            save.disabled = false;
            save.textContent = t('COM_MENUS_CUSTOMIZE_SAVE', 'Save');
            var reason = (r && r.message) || t('PLG_CUSTOMIZE_POSITION_UNKNOWN_ERROR', 'unknown error');
            JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_FAILED', 'Save failed: %s').replace('%s', reason));
          }
        }).catch(function () {
          save.disabled = false;
          save.textContent = t('COM_MENUS_CUSTOMIZE_SAVE', 'Save');
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_ERROR', 'Save error.'));
        });
      });
    });
  }

  function createSticky(doc) {
    if (sticky) {
      return;
    }

    sticky = doc.createElement('div');
    sticky.className = 'customize-sticky-zone';
    sticky.textContent = t('PLG_CUSTOMIZE_POSITION_DROP_HINT', 'Drop here to move to another position');

    sticky.addEventListener('dragover', function (e) {
      if (draggedModule) {
        e.preventDefault();
        sticky.classList.add('customize-sticky-over');
      }
    });

    sticky.addEventListener('dragleave', function () {
      sticky.classList.remove('customize-sticky-over');
    });

    sticky.addEventListener('drop', function (e) {
      if (!draggedModule) {
        return;
      }

      e.preventDefault();
      sticky.classList.remove('customize-sticky-over');
      pending = draggedModule;
      pending.classList.add('customize-pending');
      openSelector(doc);
    });

    doc.body.appendChild(sticky);
  }

  JC.on('customize:drag-start', function (e) {
    draggedModule = (e.detail && e.detail.el) || null;

    if (isModuleEl(draggedModule) && e.detail && e.detail.doc) {
      createSticky(e.detail.doc);
    }
  });

  JC.on('customize:drag-end', function () {
    draggedModule = null;

    // Keep the bar only if the module was dropped on it (a position is being chosen).
    if (!pending) {
      removeSticky();
    }
  });

  // Declare modules sortable; the engine handles the drag mechanics and calls onReorder on drop
  // onto another module or an empty-position drop zone.
  JC.registerAreaType('module', {
    draggable: true,
    onReorder: function (info) {
      var position = info.target.getAttribute('data-customize-position')
        || info.target.getAttribute('data-customize-droppos');

      if (!position) {
        return;
      }

      info.dragged.setAttribute('data-customize-position', position);
      saveOrder(info.doc, position);
    }
  });
}(window));
