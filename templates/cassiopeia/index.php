<?php

/**
 * @package     Joomla.Site
 * @subpackage  Templates.cassiopeia
 *
 * @copyright   (C) 2017 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Customize\LayoutHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/** @var Joomla\CMS\Document\HtmlDocument $this */

$app   = Factory::getApplication();
$input = $app->getInput();
$wa    = $this->getWebAssetManager();

// Customize mode: emit a few hooks (sidebar resize handles, position drop targets) only when the
// request carries a valid customize token, so normal visitors get untouched markup.
$customizeActive = CustomizeMode::isActive();

// Browsers support SVG favicons
$this->addHeadLink(HTMLHelper::_('image', 'joomla-favicon.svg', '', [], true, 1), 'icon', 'rel', ['type' => 'image/svg+xml']);
$this->addHeadLink(HTMLHelper::_('image', 'favicon.ico', '', [], true, 1), 'alternate icon', 'rel', ['type' => 'image/vnd.microsoft.icon']);
$this->addHeadLink(HTMLHelper::_('image', 'joomla-favicon-pinned.svg', '', [], true, 1), 'mask-icon', 'rel', ['color' => '#000']);

// Detecting Active Variables
$option   = $input->getCmd('option', '');
$view     = $input->getCmd('view', '');
$layout   = $input->getCmd('layout', '');
$task     = $input->getCmd('task', '');
$itemid   = $input->getCmd('Itemid', '');
$sitename = htmlspecialchars($app->get('sitename'), ENT_QUOTES, 'UTF-8');
$menu     = $app->getMenu()->getActive();
$pageclass = $menu !== null ? $menu->getParams()->get('pageclass_sfx', '') : '';

// Color Theme
$paramsColorName = $this->params->get('colorName', 'colors_standard');
$assetColorName  = 'theme.' . $paramsColorName;

// Use a font scheme if set in the template style options
$paramsFontScheme = $this->params->get('useFontScheme', false);
$fontStyles       = '';

if ($paramsFontScheme) {
    if (stripos($paramsFontScheme, 'https://') === 0) {
        $this->getPreloadManager()->preconnect('https://fonts.googleapis.com/', ['crossorigin' => 'anonymous']);
        $this->getPreloadManager()->preconnect('https://fonts.gstatic.com/', ['crossorigin' => 'anonymous']);
        $this->getPreloadManager()->preload($paramsFontScheme, ['as' => 'style', 'crossorigin' => 'anonymous']);
        $wa->registerAndUseStyle('fontscheme.current', $paramsFontScheme, [], ['rel' => 'lazy-stylesheet', 'crossorigin' => 'anonymous']);

        if (preg_match_all('/family=([^?:]*):/i', $paramsFontScheme, $matches) > 0) {
            $fontStyles = '--cassiopeia-font-family-body: "' . str_replace('+', ' ', $matches[1][0]) . '", sans-serif;
			--cassiopeia-font-family-headings: "' . str_replace('+', ' ', $matches[1][1] ?? $matches[1][0]) . '", sans-serif;
			--cassiopeia-font-weight-normal: 400;
			--cassiopeia-font-weight-headings: 700;';
        }
    } elseif ($paramsFontScheme === 'system') {
        $fontStylesBody    = $this->params->get('systemFontBody', '');
        $fontStylesHeading = $this->params->get('systemFontHeading', '');

        if ($fontStylesBody) {
            $fontStyles = '--cassiopeia-font-family-body: ' . $fontStylesBody . ';
            --cassiopeia-font-weight-normal: 400;';
        }
        if ($fontStylesHeading) {
            $fontStyles .= '--cassiopeia-font-family-headings: ' . $fontStylesHeading . ';
    		--cassiopeia-font-weight-headings: 700;';
        }
    } else {
        $wa->registerAndUseStyle('fontscheme.current', $paramsFontScheme, ['version' => 'auto'], ['rel' => 'lazy-stylesheet']);
        $this->getPreloadManager()->preload($wa->getAsset('style', 'fontscheme.current')->getUri() . '?' . $this->getMediaVersion(), ['as' => 'style']);
    }
}

// Enable assets
$wa->usePreset('template.cassiopeia.' . ($this->direction === 'rtl' ? 'rtl' : 'ltr'))
    ->useStyle('template.active.language')
    ->registerAndUseStyle($assetColorName, 'global/' . $paramsColorName . '.css')
    ->useStyle('template.user')
    ->useScript('template.user')
    ->addInlineStyle(":root {

		--hue: 214;
		--template-bg-light: #f0f4fb;
		--template-text-dark: #495057;
		--template-text-light: #ffffff;
		--template-link-color: var(--link-color);
		--template-special-color: #001B4C;
		$fontStyles
	}");

// Override 'template.active' asset to set correct ltr/rtl dependency
$wa->registerStyle('template.active', '', [], [], ['template.cassiopeia.' . ($this->direction === 'rtl' ? 'rtl' : 'ltr')]);

// Customize-mode layout overrides (main/sidebar ratio), written by the Customize host into this
// template's media dir. Injected inline after the template CSS whenever present, so the choices apply
// on the live site too. A child template has no media of its own, so fall back to the parent's file.
$czCssPath = JPATH_ROOT . '/media/templates/site/' . $app->getTemplate() . '/css/customize.css';

if (!is_file($czCssPath) && ($czParent = $app->getTemplate(true)->parent ?? '')) {
    $czCssPath = JPATH_ROOT . '/media/templates/site/' . $czParent . '/css/customize.css';
}

if (is_file($czCssPath)) {
    $wa->addInlineStyle(file_get_contents($czCssPath));
}

// Logo file or site title param
if ($this->params->get('logoFile')) {
    $logo = HTMLHelper::_('image', Uri::root(false) . htmlspecialchars($this->params->get('logoFile'), ENT_QUOTES), $sitename, ['loading' => 'eager', 'decoding' => 'async'], false, 0);
} elseif ($this->params->get('siteTitle')) {
    $logo = '<span title="' . $sitename . '">' . htmlspecialchars($this->params->get('siteTitle'), ENT_COMPAT, 'UTF-8') . '</span>';
} else {
    $logo = HTMLHelper::_('image', 'logo.svg', $sitename, ['class' => 'logo d-inline-block', 'loading' => 'eager', 'decoding' => 'async'], true, 0);
}

$hasClass = '';

if ($this->countModules('sidebar-left', true)) {
    $hasClass .= ' has-sidebar-left';
}

if ($this->countModules('sidebar-right', true)) {
    $hasClass .= ' has-sidebar-right';
}

// Container
$wrapper = $this->params->get('fluidContainer') ? 'wrapper-fluid' : 'wrapper-static';

$this->setMetaData('viewport', 'width=device-width, initial-scale=1');

$stickyHeader = $this->params->get('stickyHeader') ? 'position-sticky sticky-top' : '';

// Defer fontawesome for increased performance. Once the page is loaded javascript changes it to a stylesheet.
$wa->getAsset('style', 'fontawesome')->setAttribute('rel', 'lazy-stylesheet');
?>
<!DOCTYPE html>
<html lang="<?php echo $this->language; ?>" dir="<?php echo $this->direction; ?>">

<head>
    <jdoc:include type="metas" />
    <jdoc:include type="styles" />
    <jdoc:include type="scripts" />
</head>

<body id="top" class="site <?php echo $option
    . ' ' . $wrapper
    . ' view-' . $view
    . ($layout ? ' layout-' . $layout : ' no-layout')
    . ($task ? ' task-' . $task : ' no-task')
    . ($itemid ? ' itemid-' . $itemid : '')
    . ($pageclass ? ' ' . $pageclass : '')
    . $hasClass
    . ($this->direction == 'rtl' ? ' rtl' : '');
?>">
    <header class="header container-header full-width<?php echo $stickyHeader ? ' ' . $stickyHeader : ''; ?>">

        <?php if ($this->countModules('topbar')) : ?>
            <div class="container-topbar">
                <jdoc:include type="modules" name="topbar" style="none" />
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('below-top')) : ?>
            <div class="grid-child container-below-top">
                <jdoc:include type="modules" name="below-top" style="none" />
            </div>
        <?php endif; ?>

        <?php if ($this->params->get('brand', 1)) : ?>
            <div class="grid-child">
                <div class="navbar-brand">
                    <a class="brand-logo" href="<?php echo $this->baseurl; ?>/">
                        <?php echo $logo; ?>
                    </a>
                    <?php if ($this->params->get('siteDescription')) : ?>
                        <div class="site-description"><?php echo htmlspecialchars($this->params->get('siteDescription')); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('menu', true) || $this->countModules('search', true)) : ?>
            <div class="grid-child container-nav">
                <?php if ($this->countModules('menu', true)) : ?>
                    <jdoc:include type="modules" name="menu" style="none" />
                <?php endif; ?>
                <?php if ($this->countModules('search', true)) : ?>
                    <div class="container-search">
                        <jdoc:include type="modules" name="search" style="none" />
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="site-grid">
        <?php if ($this->countModules('banner', true) || LayoutHelper::isSplit('banner')) : ?>
            <div class="container-banner full-width"<?php echo LayoutHelper::positionAttrs('banner'); ?>>
                <?php echo LayoutHelper::position('banner', '<jdoc:include type="modules" name="banner" style="none" />', ['addedStyle' => 'none']); ?>
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('top-a', true) || LayoutHelper::isSplit('top-a')) : ?>
            <div class="grid-child container-top-a"<?php echo LayoutHelper::positionAttrs('top-a'); ?>>
                <?php echo LayoutHelper::position('top-a', '<jdoc:include type="modules" name="top-a" style="card" />'); ?>
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('top-b', true) || LayoutHelper::isSplit('top-b')) : ?>
            <div class="grid-child container-top-b"<?php echo LayoutHelper::positionAttrs('top-b'); ?>>
                <?php echo LayoutHelper::position('top-b', '<jdoc:include type="modules" name="top-b" style="card" />'); ?>
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('sidebar-left', true) || LayoutHelper::isSplit('sidebar-left')) : ?>
            <div class="grid-child container-sidebar-left"<?php echo LayoutHelper::positionAttrs('sidebar-left'); ?>>
                <?php if ($customizeActive) : ?>
                    <span class="customize-region-boundary" data-customize-edge="left" title="<?php echo htmlspecialchars(Text::_('TPL_CASSIOPEIA_CUSTOMIZE_RATIO_HINT'), ENT_QUOTES, 'UTF-8'); ?>"></span>
                <?php endif; ?>
                <?php echo LayoutHelper::position('sidebar-left', '<jdoc:include type="modules" name="sidebar-left" style="card" />'); ?>
            </div>
        <?php endif; ?>

        <div class="grid-child container-component"<?php echo $customizeActive ? ' data-customize-grid="main"' : ''; ?>>
            <?php echo LayoutHelper::grid('main', [
                ['id' => 'breadcrumbs', 'position' => 'breadcrumbs', 'html' => '<jdoc:include type="modules" name="breadcrumbs" style="none" />'],
                ['id' => 'main-top', 'position' => 'main-top', 'html' => '<jdoc:include type="modules" name="main-top" style="card" />'],
                ['id' => 'component', 'html' => '<jdoc:include type="message" /><main><jdoc:include type="component" /></main>', 'removable' => false],
                ['id' => 'main-bottom', 'position' => 'main-bottom', 'html' => '<jdoc:include type="modules" name="main-bottom" style="card" />'],
            ]); ?>
        </div>

        <?php if ($this->countModules('sidebar-right', true) || LayoutHelper::isSplit('sidebar-right')) : ?>
            <div class="grid-child container-sidebar-right"<?php echo LayoutHelper::positionAttrs('sidebar-right'); ?>>
                <?php if ($customizeActive) : ?>
                    <span class="customize-region-boundary" data-customize-edge="right" title="<?php echo htmlspecialchars(Text::_('TPL_CASSIOPEIA_CUSTOMIZE_RATIO_HINT'), ENT_QUOTES, 'UTF-8'); ?>"></span>
                <?php endif; ?>
                <?php echo LayoutHelper::position('sidebar-right', '<jdoc:include type="modules" name="sidebar-right" style="card" />'); ?>
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('bottom-a', true) || LayoutHelper::isSplit('bottom-a')) : ?>
            <div class="grid-child container-bottom-a"<?php echo LayoutHelper::positionAttrs('bottom-a'); ?>>
                <?php echo LayoutHelper::position('bottom-a', '<jdoc:include type="modules" name="bottom-a" style="card" />'); ?>
            </div>
        <?php endif; ?>

        <?php if ($this->countModules('bottom-b', true) || LayoutHelper::isSplit('bottom-b')) : ?>
            <div class="grid-child container-bottom-b"<?php echo LayoutHelper::positionAttrs('bottom-b'); ?>>
                <?php echo LayoutHelper::position('bottom-b', '<jdoc:include type="modules" name="bottom-b" style="card" />'); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($this->countModules('footer', true) || LayoutHelper::isSplit('footer')) : ?>
        <footer class="container-footer footer full-width">
            <div class="grid-child"<?php echo LayoutHelper::positionAttrs('footer'); ?>>
                <?php echo LayoutHelper::position('footer', '<jdoc:include type="modules" name="footer" style="none" />', ['addedStyle' => 'none']); ?>
            </div>
        </footer>
    <?php endif; ?>

    <?php if ($this->params->get('backTop') == 1) : ?>
        <a href="#top" id="back-top" class="back-to-top-link" aria-label="<?php echo Text::_('TPL_CASSIOPEIA_BACKTOTOP'); ?>">
            <span class="icon-arrow-up icon-fw" aria-hidden="true"></span>
        </a>
    <?php endif; ?>

    <jdoc:include type="modules" name="debug" style="none" />
</body>

</html>
