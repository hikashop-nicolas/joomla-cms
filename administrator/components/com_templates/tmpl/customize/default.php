<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\DispatcherInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

$wa = $this->getDocument()->getWebAssetManager();

// Register the engine assets from their WebAsset manifest (media/customize/joomla.asset.json) and use
// them by name. The engine is an ES module that imports the customize.api module (declared
// importmap:true), so the manager adds the api to the page import map.
$wa->getRegistry()->addExtensionRegistryFile('customize');
$wa->useStyle('customize.style')
    ->useScript('customize.engine')
    // Editing actions open core admin screens in a JoomlaDialog iframe; register it so the
    // engine's dynamic import('joomla.dialog') resolves through the page import map.
    ->useScript('joomla.dialog');

// Load the active site template's own customize behaviors, so a template can extend Customize without
// shipping a plugin. Convention: the template ships an ES module at media/templates/site/<element>/
// js/customize.js that imports customize.api. We load it by its resolved media path rather than as a
// named WebAsset, because in the admin Joomla resolves a site-template asset's relative path against
// the admin's own active template (Atum), so an asset-by-name would not be found. A child template
// (no media of its own) falls back to the parent's file, which it inherits.
foreach (array_values(array_unique(array_filter([$this->template, $this->parentTemplate]))) as $czEl) {
    $czJs = 'media/templates/site/' . $czEl . '/js/customize.min.js';

    if (is_file(JPATH_ROOT . '/' . $czJs)) {
        $wa->registerAndUseScript(
            $czEl . '.customize',
            Uri::root(true) . '/' . $czJs . '?' . (@filemtime(JPATH_ROOT . '/' . $czJs) ?: '1'),
            [],
            ['type' => 'module'],
            ['customize.api']
        );

        break;
    }
}

// Each customize plugin registers its own admin-side JS in onCustomizeAdminInit (dispatched below),
// gated on the permission needed to use it, so editing UI the user cannot use is never loaded.

$this->getDocument()->addScriptOptions('customize', [
    'ajaxBase' => 'index.php?option=com_ajax&group=customize&format=json',
    'token'    => Session::getFormToken(),
    'frameId'  => 'customize-frame',
    // The template style being customized and the template element it belongs to, so a template's own
    // customize JS (and the template-scoped server actions) know their context.
    'styleId'  => (int) $this->styleId,
    'template' => $this->template,
    // Signed customize token carried by the iframe, plus the endpoint + cadence the engine uses to
    // refresh it so a long editing session never lapses.
    'frameToken'     => $this->customizeToken,
    'tokenUrl'       => 'index.php?option=com_templates&task=ajax.customizeToken&format=json&' . Session::getFormToken() . '=1',
    'tokenRefreshMs' => 1800000,
    // Version by file mtime so an iframe-CSS deploy busts the cache automatically (no hard reload).
    'frameCss'   => Uri::root() . 'media/customize/css/customize-frame.css?' . (@filemtime(JPATH_ROOT . '/media/customize/css/customize-frame.css') ?: $this->getDocument()->getMediaVersion()),
    'tinymceSrc' => Uri::root() . 'media/vendor/tinymce/tinymce.min.js',
]);

// Register the engine's JS strings for Joomla.Text.
Text::script('COM_TEMPLATES_CUSTOMIZE_SAVE');
Text::script('COM_TEMPLATES_CUSTOMIZE_CANCEL');
Text::script('COM_TEMPLATES_CUSTOMIZE_SAVING');
Text::script('COM_TEMPLATES_CUSTOMIZE_INSERT_IMAGE');
Text::script('COM_TEMPLATES_CUSTOMIZE_REMOVE');
Text::script('COM_TEMPLATES_CUSTOMIZE_REMOVE_CONFIRM');
Text::script('COM_TEMPLATES_CUSTOMIZE_REMOVE_HINT');
Text::script('COM_TEMPLATES_CUSTOMIZE_AREA_ROLEDESCRIPTION');
Text::script('COM_TEMPLATES_CUSTOMIZE_AREA_HINT');
Text::script('COM_TEMPLATES_CUSTOMIZE_AREA_MOVE_HINT');
Text::script('COM_TEMPLATES_CUSTOMIZE_AREA_MOVED');
Text::script('COM_TEMPLATES_CUSTOMIZE_AREA_MOVED_TO');
Text::script('COM_TEMPLATES_CUSTOMIZE_AREA_DELETE_HINT');

// Let customize plugins register their own JS strings and admin assets.
$app = Factory::getApplication();
PluginHelper::importPlugin('customize');
Factory::getContainer()->get(DispatcherInterface::class)
    ->dispatch('onCustomizeAdminInit', new GenericEvent('onCustomizeAdminInit', ['subject' => $app]));
?>
<div class="customize-host">
    <div class="customize-stage">
        <?php if ($this->previewUrl) : ?>
            <iframe id="customize-frame" class="customize-frame"
                src="<?php echo htmlspecialchars($this->previewUrl, ENT_QUOTES); ?>"
                title="<?php echo $this->escape(Text::_('COM_TEMPLATES_CUSTOMIZE_TOOLBAR')); ?>"></iframe>
        <?php else : ?>
            <div class="customize-empty"><?php echo Text::_('COM_TEMPLATES_CUSTOMIZE_FRAMEWORK_MISSING'); ?></div>
        <?php endif; ?>
    </div>
    <aside class="customize-panel">
        <?php if ($this->previewUrl) : ?>
            <a class="customize-open-external btn btn-outline-secondary btn-sm"
                href="<?php echo htmlspecialchars($this->previewUrl, ENT_QUOTES); ?>"
                target="_blank" rel="noopener"><?php echo Text::_('COM_TEMPLATES_CUSTOMIZE_OPEN_FRONTEND'); ?></a>
        <?php endif; ?>
    </aside>
</div>

<?php
// Hidden Joomla media field reused by customize plugins to open the native media picker.
$mediaForm = new Form('customize_media');
$mediaForm->load('<form><field name="image" type="media" id="customize-media-field" /></form>');
?>
<div id="customize-media-host" style="position:absolute;left:-9999px;top:0;" aria-hidden="true">
    <?php echo $mediaForm->renderField('image'); ?>
</div>
