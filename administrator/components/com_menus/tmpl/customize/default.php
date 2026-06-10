<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
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
    ->useScript('customize.engine');

// Each customize plugin registers its own admin-side JS in onCustomizeAdminInit (dispatched below),
// gated on the permission needed to use it, so editing UI the user cannot use is never loaded.

$this->getDocument()->addScriptOptions('customize', [
    'ajaxBase' => 'index.php?option=com_ajax&group=customize&format=json',
    'token'    => Session::getFormToken(),
    'frameId'  => 'customize-frame',
    // Version by file mtime so an iframe-CSS deploy busts the cache automatically (no hard reload).
    'frameCss'   => Uri::root() . 'media/customize/css/customize-frame.css?' . (@filemtime(JPATH_ROOT . '/media/customize/css/customize-frame.css') ?: $this->getDocument()->getMediaVersion()),
    'tinymceSrc' => Uri::root() . 'media/vendor/tinymce/tinymce.min.js',
]);

// Register the engine's JS strings for Joomla.Text.
Text::script('COM_MENUS_CUSTOMIZE_SAVE');
Text::script('COM_MENUS_CUSTOMIZE_CANCEL');
Text::script('COM_MENUS_CUSTOMIZE_SAVING');
Text::script('COM_MENUS_CUSTOMIZE_INSERT_IMAGE');
Text::script('COM_MENUS_CUSTOMIZE_REMOVE');
Text::script('COM_MENUS_CUSTOMIZE_REMOVE_CONFIRM');
Text::script('COM_MENUS_CUSTOMIZE_REMOVE_HINT');
Text::script('COM_MENUS_CUSTOMIZE_STATUS_ACTIVE');
Text::script('COM_MENUS_CUSTOMIZE_STATUS_CROSS_ORIGIN');
Text::script('COM_MENUS_CUSTOMIZE_STATUS_INACTIVE');
Text::script('COM_MENUS_CUSTOMIZE_AREA_ROLEDESCRIPTION');
Text::script('COM_MENUS_CUSTOMIZE_AREA_HINT');
Text::script('COM_MENUS_CUSTOMIZE_AREA_MOVE_HINT');
Text::script('COM_MENUS_CUSTOMIZE_AREA_MOVED');
Text::script('COM_MENUS_CUSTOMIZE_AREA_MOVED_TO');
Text::script('COM_MENUS_CUSTOMIZE_AREA_DELETE_HINT');

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
                title="<?php echo $this->escape(Text::_('COM_MENUS_TOOLBAR_CUSTOMIZE')); ?>"></iframe>
        <?php else : ?>
            <div class="customize-empty"><?php echo Text::_('COM_MENUS_CUSTOMIZE_FRAMEWORK_MISSING'); ?></div>
        <?php endif; ?>
    </div>
    <aside class="customize-panel">
        <?php if ($this->previewUrl) : ?>
            <div id="customize-status" class="customize-status" role="status"></div>
            <a class="customize-open-external btn btn-outline-secondary btn-sm"
                href="<?php echo htmlspecialchars($this->previewUrl, ENT_QUOTES); ?>"
                target="_blank" rel="noopener"><?php echo Text::_('COM_MENUS_CUSTOMIZE_OPEN_FRONTEND'); ?></a>
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
