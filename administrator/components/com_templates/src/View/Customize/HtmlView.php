<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Templates\Administrator\View\Customize;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Hosts the live frontend, rendered with a chosen template style, in an iframe for visual customization.
 *
 * @since  __DEPLOY_VERSION__
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The template style id being customized.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    protected $styleId = 0;

    /**
     * The template element (e.g. "cassiopeia") the style belongs to.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $template = '';

    /**
     * The parent template element, for a child style (so the host can fall back to the parent's
     * customize JS / assets, which the child inherits).
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $parentTemplate = '';

    /**
     * The style title, for the page heading.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $styleTitle = '';

    /**
     * The routed, customize-flagged frontend URL to load in the iframe.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $previewUrl = '';

    /**
     * The signed customize token carried in the preview URL and refreshed by the engine.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $customizeToken = '';

    /**
     * Execute and display a template script.
     *
     * @param   string  $tpl  The name of the template file to parse.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        if (!$this->getCurrentUser()->authorise('core.edit', 'com_templates')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->styleId = $app->getInput()->getInt('id', 0);
        $style         = $this->loadStyle($this->styleId);

        // Only site styles render a frontend page that can be customized.
        if ($style && (int) $style->client_id === 0) {
            $this->template       = (string) $style->template;
            $this->parentTemplate = (string) ($style->parent ?? '');
            $this->styleTitle     = (string) $style->title;
            $this->customizeToken = CustomizeMode::mintToken((int) $this->getCurrentUser()->id);
            $this->previewUrl     = $this->buildPreviewUrl($this->styleId);
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Load a template style row.
     *
     * @param   integer  $id  The style id.
     *
     * @return  \stdClass|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadStyle(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'template', 'parent', 'client_id', 'home', 'title']))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * Build the frontend preview URL: open the page this style is assigned to (else the site home),
     * force the style with templateStyle, and carry the signed customize token.
     *
     * @param   integer  $styleId  The template style id.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function buildPreviewUrl(int $styleId): string
    {
        $itemId = $this->resolvePreviewItemId($styleId);

        $query = 'index.php?'
            . ($itemId > 0 ? 'Itemid=' . $itemId . '&' : '')
            . 'templateStyle=' . $styleId
            . '&customize=' . $this->customizeToken;

        $url = Route::link('site', $query, false, Route::TLS_IGNORE, true);

        if (empty($url)) {
            $url = Uri::root() . $query;
        }

        return $url;
    }

    /**
     * Find a published site menu item to open the preview on: one explicitly assigned this style
     * (preferring the home item), else the site home item, else 0 (the routed site root).
     *
     * @param   integer  $styleId  The template style id.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function resolvePreviewItemId(int $styleId): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $assigned = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('template_style_id') . ' = :sid')
            ->order($db->quoteName('home') . ' DESC')
            ->order($db->quoteName('lft') . ' ASC')
            ->bind(':sid', $styleId, ParameterType::INTEGER)
            ->setLimit(1);

        $id = (int) $db->setQuery($assigned)->loadResult();

        if ($id > 0) {
            return $id;
        }

        $home = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('home') . ' = 1')
            ->order($db->quoteName('language') . ' = ' . $db->quote('*') . ' ASC')
            ->setLimit(1);

        return (int) $db->setQuery($home)->loadResult();
    }

    /**
     * Add the page title and the back button.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $title = $this->styleTitle !== '' ? $this->styleTitle : Text::_('COM_TEMPLATES_MANAGER_STYLES');
        ToolbarHelper::title(Text::sprintf('COM_TEMPLATES_CUSTOMIZE_VIEW_TITLE', $title), 'paint-brush thememanager');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->linkButton('back', 'JTOOLBAR_BACK')
            ->url('index.php?option=com_templates&view=styles')
            ->icon('icon-arrow-left');
    }
}
