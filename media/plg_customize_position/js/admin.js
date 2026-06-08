/**
 * Customize - Position plugin (admin side).
 *
 * Loaded in the com_menus Customize host page. Modules are marked server-side (ModulesRenderer).
 * The drag-to-reorder mechanics (handle on the toolbar title, highlighting, drop targets, empty
 * drop zones, cross-position moves) are generic and live in the engine (customize.core.js); this
 * plugin only declares the module area sortable and persists the new order through com_ajax.
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

  function moduleSelector(position) {
    return '[data-customize-type="module"][data-customize-position="' + (window.CSS ? window.CSS.escape(position) : position) + '"]';
  }

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

  // Declare modules sortable (drag handle on the toolbar title) and persist drops.
  JC.registerAreaType('module', {
    draggable: true,
    onReorder: function (info) {
      // The target is either another module or an empty-position drop zone.
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
