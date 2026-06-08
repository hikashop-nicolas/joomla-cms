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

  // --- Sticky drop zones while dragging a module ------------------------------
  // Two bars appear while dragging: a blue "move to another position" bar at the bottom and a red
  // "remove this module" bar at the top. Dropping the module on either greys it in place and turns
  // that bar into a confirm step (a position picker, or a delete confirmation).

  var draggedModule = null;
  var moveBar = null;
  var deleteBar = null;
  var pending = null;

  function isModuleEl(el) {
    return el && el.getAttribute('data-customize-type') === 'module';
  }

  function removeBars() {
    [moveBar, deleteBar].forEach(function (b) {
      if (b && b.parentNode) {
        b.parentNode.removeChild(b);
      }
    });
    moveBar = null;
    deleteBar = null;
  }

  function cancelAction() {
    if (pending) {
      pending.classList.remove('customize-pending');
    }
    pending = null;
    removeBars();
  }

  // Capture the dragged module and drop the bar that was not used, keeping the chosen one.
  function beginConfirm(keep) {
    pending = draggedModule;
    pending.classList.add('customize-pending');

    var drop = (keep === 'move') ? deleteBar : moveBar;
    if (drop && drop.parentNode) {
      drop.parentNode.removeChild(drop);
    }
    if (keep === 'move') {
      deleteBar = null;
    } else {
      moveBar = null;
    }
  }

  function openSelector(doc) {
    var bar = moveBar;
    bar.textContent = t('JGLOBAL_LOADING', 'Loading…');

    JC.callAction('position', 'positions', {}).then(function (res) {
      if (!res || !res.success) {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_LOAD_FAILED', 'Could not load positions.'));
        cancelAction();
        return;
      }

      bar.textContent = '';
      bar.classList.add('customize-sticky-form');

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

      bar.appendChild(label);
      bar.appendChild(sel);
      bar.appendChild(save);
      bar.appendChild(cancel);

      cancel.addEventListener('click', cancelAction);

      save.addEventListener('click', function () {
        save.disabled = true;
        save.textContent = t('COM_MENUS_CUSTOMIZE_SAVING', 'Saving…');

        JC.callAction('position', 'move', { id: pending.getAttribute('data-customize-id'), position: sel.value }).then(function (r) {
          if (r && r.success) {
            pending = null;
            moveBar = null;
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

  function openDeleteConfirm(doc) {
    var bar = deleteBar;
    bar.textContent = '';
    bar.classList.add('customize-sticky-form');

    var name = pending.getAttribute('data-customize-name') || '';
    var label = doc.createElement('span');
    label.textContent = t('PLG_CUSTOMIZE_POSITION_DELETE_CONFIRM', 'Remove module %s?').replace('%s', name);

    var del = doc.createElement('button');
    del.type = 'button';
    del.className = 'customize-action customize-action-danger';
    del.textContent = t('PLG_CUSTOMIZE_POSITION_DELETE_BTN', 'Remove');

    var cancel = doc.createElement('button');
    cancel.type = 'button';
    cancel.className = 'customize-action customize-action-cancel';
    cancel.textContent = t('COM_MENUS_CUSTOMIZE_CANCEL', 'Cancel');

    bar.appendChild(label);
    bar.appendChild(del);
    bar.appendChild(cancel);

    cancel.addEventListener('click', cancelAction);

    del.addEventListener('click', function () {
      del.disabled = true;
      del.textContent = t('COM_MENUS_CUSTOMIZE_SAVING', 'Saving…');

      JC.callAction('position', 'delete', { id: pending.getAttribute('data-customize-id') }).then(function (r) {
        if (r && r.success) {
          pending = null;
          deleteBar = null;
          reloadFrame();
        } else {
          del.disabled = false;
          del.textContent = t('PLG_CUSTOMIZE_POSITION_DELETE_BTN', 'Remove');
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_DELETE_FAILED', 'Could not remove the module.'));
        }
      }).catch(function () {
        del.disabled = false;
        del.textContent = t('PLG_CUSTOMIZE_POSITION_DELETE_BTN', 'Remove');
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_DELETE_FAILED', 'Could not remove the module.'));
      });
    });
  }

  function makeZone(doc, extraClass, hintKey, hintFallback, onDrop) {
    var zone = doc.createElement('div');
    zone.className = 'customize-sticky-zone' + (extraClass ? ' ' + extraClass : '');
    zone.textContent = t(hintKey, hintFallback);

    zone.addEventListener('dragover', function (e) {
      if (draggedModule) {
        e.preventDefault();
        zone.classList.add('customize-sticky-over');
      }
    });

    zone.addEventListener('dragleave', function () {
      zone.classList.remove('customize-sticky-over');
    });

    zone.addEventListener('drop', function (e) {
      if (!draggedModule) {
        return;
      }

      e.preventDefault();
      zone.classList.remove('customize-sticky-over');
      onDrop(doc);
    });

    doc.body.appendChild(zone);
    return zone;
  }

  function createBars(doc) {
    if (moveBar || deleteBar) {
      return;
    }

    deleteBar = makeZone(doc, 'customize-sticky-zone-top customize-sticky-danger', 'PLG_CUSTOMIZE_POSITION_DELETE_HINT', 'Drop here to remove this module', function (d) {
      beginConfirm('delete');
      openDeleteConfirm(d);
    });

    moveBar = makeZone(doc, '', 'PLG_CUSTOMIZE_POSITION_DROP_HINT', 'Drop here to move to another position', function (d) {
      beginConfirm('move');
      openSelector(d);
    });
  }

  JC.on('customize:drag-start', function (e) {
    draggedModule = (e.detail && e.detail.el) || null;

    if (draggedModule) {
      draggedModule.classList.remove('customize-new');
    }

    if (isModuleEl(draggedModule) && e.detail && e.detail.doc) {
      createBars(e.detail.doc);
    }
  });

  JC.on('customize:drag-end', function () {
    draggedModule = null;

    // Keep the bars only if the module was dropped on one (a confirm step is showing).
    if (!pending) {
      removeBars();
    }
  });

  // --- Add module (panel control, in the admin parent) ------------------------
  // Pick a type + title; the module is created in a default position and shown highlighted and
  // draggable, so the position is chosen by dragging it (like any other module).

  var newModuleId = null;

  function openAddForm(doc, panel, btn) {
    btn.classList.add('customize-hidden');

    var form = doc.createElement('div');
    form.className = 'customize-add-form';

    // Draggable bar at the top: grab it and drop it on the page to place (and create) the module.
    var bar = doc.createElement('div');
    bar.className = 'customize-add-bar';
    bar.setAttribute('draggable', 'true');

    var typeLabel = doc.createElement('label');
    typeLabel.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_TYPE', 'Module type');
    var typeSel = doc.createElement('select');
    typeSel.className = 'form-select form-select-sm';

    var titleLabel = doc.createElement('label');
    titleLabel.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_TITLE', 'Title');
    var titleInput = doc.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'form-control form-control-sm';

    var hint = doc.createElement('div');
    hint.className = 'customize-add-hint';
    hint.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_HINT', 'Set a type and title, then drag the bar onto the page.');

    var msg = doc.createElement('div');
    msg.className = 'customize-status is-warn customize-hidden';

    var cancel = doc.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn-secondary btn-sm';
    cancel.textContent = t('COM_MENUS_CUSTOMIZE_CANCEL', 'Cancel');

    form.appendChild(bar);
    form.appendChild(typeLabel);
    form.appendChild(typeSel);
    form.appendChild(titleLabel);
    form.appendChild(titleInput);
    form.appendChild(hint);
    form.appendChild(msg);
    form.appendChild(cancel);
    panel.appendChild(form);

    function refreshBar() {
      var title = titleInput.value.trim();
      bar.textContent = '☰ ' + (title || t('PLG_CUSTOMIZE_POSITION_ADD_BAR', 'New module'));
    }
    refreshBar();
    titleInput.addEventListener('input', refreshBar);

    JC.callAction('position', 'moduletypes', {}).then(function (res) {
      (res && res.types || []).forEach(function (tp) {
        var o = doc.createElement('option');
        o.value = tp.element;
        o.textContent = tp.name;
        typeSel.appendChild(o);
      });
    });

    function close() {
      form.remove();
      btn.classList.remove('customize-hidden');
    }

    function fail() {
      msg.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_FAILED', 'Could not add the module.');
      msg.classList.remove('customize-hidden');
    }

    cancel.addEventListener('click', close);

    bar.addEventListener('dragstart', function (e) {
      var title = titleInput.value.trim();

      if (!title || !typeSel.value) {
        e.preventDefault();
        msg.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_NEEDINFO', 'Choose a type and enter a title first.');
        msg.classList.remove('customize-hidden');
        return;
      }

      msg.classList.add('customize-hidden');

      try {
        e.dataTransfer.effectAllowed = 'copy';
        e.dataTransfer.setData('text/plain', title);
      } catch (err) {
        // some browsers restrict dataTransfer
      }

      bar.classList.add('is-dragging');

      JC.beginExternalDrag('module', { module: typeSel.value, title: title }, function (info) {
        var position = info.target.getAttribute('data-customize-position') || info.target.getAttribute('data-customize-droppos');

        if (!position) {
          return;
        }

        JC.callAction('position', 'add', { title: info.payload.title, module: info.payload.module, position: position }).then(function (res) {
          if (res && res.success) {
            newModuleId = res.id;
            close();
            reloadFrame();
          } else {
            fail();
          }
        }).catch(fail);
      });
    });

    bar.addEventListener('dragend', function () {
      bar.classList.remove('is-dragging');
      JC.endExternalDrag();
    });

    titleInput.focus();
  }

  // After the iframe reloads, highlight the freshly created module and pop its toolbar so it can
  // be grabbed and dragged into place.
  JC.on('customize:frame-ready', function (e) {
    var doc = e.detail && e.detail.doc;

    if (!doc || !newModuleId) {
      return;
    }

    var m = doc.querySelector('[data-customize-type="module"][data-customize-id="' + newModuleId + '"]');
    newModuleId = null;

    if (!m) {
      return;
    }

    m.classList.add('customize-new');

    try {
      m.scrollIntoView({ block: 'center' });
      m.dispatchEvent(new doc.defaultView.MouseEvent('mouseover', { bubbles: true }));
    } catch (err) {
      // non-fatal
    }
  });

  function buildAddModule() {
    var panel = JC.panel && JC.panel();

    if (!panel) {
      return;
    }

    var doc = window.document;
    var btn = doc.createElement('button');
    btn.type = 'button';
    btn.className = 'customize-add-module btn btn-primary btn-sm';
    btn.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_BTN', 'Add module');
    panel.appendChild(btn);

    btn.addEventListener('click', function () {
      openAddForm(doc, panel, btn);
    });
  }

  buildAddModule();

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
