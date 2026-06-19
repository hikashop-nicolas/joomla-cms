<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.content
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\Content\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Customize plugin: edit Joomla article text, title and properties directly on the rendered page.
 *
 * Frontend: wraps each article body with data-customize-* markup when customize mode is active.
 * Admin: handles the save / load / saveprops AJAX actions through com_ajax (group=customize).
 *
 * @since  __DEPLOY_VERSION__
 */
final class Content extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language file on instantiation.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * Article fields that may be saved inline.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const EDITABLE_FIELDS = ['introtext', 'fulltext', 'title'];

    /**
     * Returns the events this plugin subscribes to.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Run last so we wrap the final, fully-prepared body.
            'onContentPrepare'     => ['onContentPrepare', Priority::LOW],
            'onAjaxContent'        => 'onAjaxContent',
            'onCustomizeAdminInit' => 'onCustomizeAdminInit',
        ];
    }

    /**
     * Register this plugin's JS strings for Joomla.Text on the customize admin page.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onCustomizeAdminInit(): void
    {
        $identity = $this->getApplication()->getIdentity();

        // Article editing requires content-edit rights; otherwise the content editing UI is not loaded.
        if (!$identity->authorise('core.edit', 'com_content') && !$identity->authorise('core.edit.own', 'com_content')) {
            return;
        }

        $this->loadLanguage();

        $wa = $this->getApplication()->getDocument()->getWebAssetManager();

        if (is_file(JPATH_ROOT . '/media/plg_customize_content/joomla.asset.json')) {
            $wa->getRegistry()->addExtensionRegistryFile('plg_customize_content');
            $wa->useScript('plg_customize_content.admin');
        }

        $keys = [
            'PLG_CUSTOMIZE_CONTENT_ADD_IMAGE',
            'PLG_CUSTOMIZE_CONTENT_ARCHIVED',
            'PLG_CUSTOMIZE_CONTENT_AREA_DETAILS',
            'PLG_CUSTOMIZE_CONTENT_AREA_IMAGE',
            'PLG_CUSTOMIZE_CONTENT_AREA_TEXT',
            'PLG_CUSTOMIZE_CONTENT_AREA_TITLE',
            'PLG_CUSTOMIZE_CONTENT_BTN_ADVANCED',
            'PLG_CUSTOMIZE_CONTENT_BTN_EDIT',
            'PLG_CUSTOMIZE_CONTENT_BTN_PROPERTIES',
            'PLG_CUSTOMIZE_CONTENT_CATEGORY',
            'PLG_CUSTOMIZE_CONTENT_ENTER_CATEGORY_NAME',
            'PLG_CUSTOMIZE_CONTENT_FEATURED',
            'PLG_CUSTOMIZE_CONTENT_FULLTEXT_NOTICE',
            'PLG_CUSTOMIZE_CONTENT_IMAGE_SAVED',
            'PLG_CUSTOMIZE_CONTENT_MEDIA_UNAVAILABLE',
            'PLG_CUSTOMIZE_CONTENT_NEW_CATEGORY',
            'PLG_CUSTOMIZE_CONTENT_NEW_CATEGORY_NAME',
            'PLG_CUSTOMIZE_CONTENT_PARENT_CATEGORY',
            'PLG_CUSTOMIZE_CONTENT_PROPS_LOAD_FAILED',
            'PLG_CUSTOMIZE_CONTENT_PROPS_SAVED',
            'PLG_CUSTOMIZE_CONTENT_PUBLISHED',
            'PLG_CUSTOMIZE_CONTENT_SAVED',
            'PLG_CUSTOMIZE_CONTENT_SAVE_ERROR',
            'PLG_CUSTOMIZE_CONTENT_SAVE_FAILED',
            'PLG_CUSTOMIZE_CONTENT_STATUS',
            'PLG_CUSTOMIZE_CONTENT_TOP_LEVEL',
            'PLG_CUSTOMIZE_CONTENT_UNKNOWN_ERROR',
            'PLG_CUSTOMIZE_CONTENT_UNPUBLISHED',
        ];

        foreach ($keys as $key) {
            Text::script($key);
        }
    }

    /**
     * Wrap an article body with an editable-area marker while customize mode is active.
     *
     * @param   ContentPrepareEvent  $event  The event.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onContentPrepare(ContentPrepareEvent $event): void
    {
        if (!CustomizeMode::isActive()) {
            return;
        }

        if (strncmp($event->getContext(), 'com_content.', 12) !== 0) {
            return;
        }

        $item = $event->getItem();

        if (empty($item->id) || !isset($item->text) || !\is_string($item->text)) {
            return;
        }

        if (!empty($item->customizeWrapped)) {
            return;
        }

        $item->customizeWrapped = true;

        $id    = (int) $item->id;
        $title = isset($item->title) ? (string) $item->title : ('#' . $id);

        // The full single-article view shows introtext+fulltext combined, so inline intro-only
        // editing is unsafe there; list/intro contexts map cleanly to the introtext field.
        $field = ($event->getContext() === 'com_content.article' && !empty($item->fulltext)) ? '' : 'introtext';

        // Save a picked image to the field this view actually renders: the single-article view shows
        // the full image, list/intro contexts show the intro image. Otherwise it saves out of sight.
        $imageTarget = $event->getContext() === 'com_content.article' ? 'full' : 'intro';

        $open = '<div class="customize-content-area" data-customize-type="content"'
            . ' data-customize-id="' . $id . '"'
            . ' data-customize-name="' . htmlspecialchars($title, ENT_QUOTES) . '"'
            . ' data-customize-field="' . $field . '"'
            . ' data-customize-imagetarget="' . $imageTarget . '">';

        $item->text = $open . $item->text . '</div>';
    }

    /**
     * com_ajax entry point (plugin=content&group=customize). Dispatches the requested action.
     *
     * @param   AjaxEvent  $event  The AJAX event.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxContent(AjaxEvent $event): void
    {
        $input = $this->getApplication()->getInput();

        if (!Session::checkToken('post')) {
            $event->addResult($this->fail(Text::_('JINVALID_TOKEN')));

            return;
        }

        $action  = $input->getCmd('action', '');
        $payload = json_decode($input->get('payload', '', 'raw'), true) ?: [];
        $id      = (int) ($payload['id'] ?? 0);

        $model  = $this->articleModel();
        $record = $id > 0 ? $model->getItem($id) : null;

        if (!$record || empty($record->id)) {
            $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_INVALID')));

            return;
        }

        if (!$this->canEdit($record)) {
            $event->addResult($this->fail(Text::_('JERROR_ALERTNOAUTHOR')));

            return;
        }

        switch ($action) {
            case 'save':
                $event->addResult($this->doSave($model, $record, $payload));
                break;

            case 'load':
                $event->addResult($this->doLoad($record));
                break;

            case 'saveprops':
                $event->addResult($this->doSaveProps($model, $record, $payload));
                break;

            case 'saveimage':
                $event->addResult($this->doSaveImage($model, $record, $payload));
                break;

            case 'newcategory':
                $event->addResult($this->doNewCategory($model, $record, $payload));
                break;

            default:
                $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_UNKNOWN_ACTION')));
        }
    }

    /**
     * Save a single editable field (body/fulltext as filtered HTML, title as plain text).
     *
     * @param   object  $model    The article model.
     * @param   object  $record   The current article record.
     * @param   array   $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doSave($model, $record, array $payload): string
    {
        $field = \in_array($payload['field'] ?? '', self::EDITABLE_FIELDS, true) ? $payload['field'] : '';
        $value = (string) ($payload['html'] ?? '');

        if ($field === '') {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_INVALID'));
        }

        if ($field === 'title') {
            $value = trim(strip_tags($value));

            if ($value === '') {
                return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_INVALID'));
            }
        } else {
            // Apply the configured Text Filters for the current user's groups, like the article form does.
            $value = ComponentHelper::filterText($value);
        }

        if (!$model->save(['id' => (int) $record->id, 'catid' => (int) $record->catid, $field => $value])) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_SAVE'));
        }

        return json_encode(['success' => true, 'id' => (int) $record->id, 'field' => $field, 'html' => $value]);
    }

    /**
     * Return the editable properties of an article plus the content category list.
     *
     * @param   object  $record  The current article record.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doLoad($record): string
    {
        return json_encode([
            'success'    => true,
            'id'         => (int) $record->id,
            'title'      => $record->title,
            'catid'      => (int) $record->catid,
            'featured'   => (int) $record->featured,
            'state'      => (int) $record->state,
            'categories' => $this->categories(),
        ]);
    }

    /**
     * Save the article properties (category, featured, state).
     *
     * @param   object  $model    The article model.
     * @param   object  $record   The current article record.
     * @param   array   $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doSaveProps($model, $record, array $payload): string
    {
        $data = [
            'id'       => (int) $record->id,
            'catid'    => (int) ($payload['catid'] ?? $record->catid),
            'featured' => empty($payload['featured']) ? 0 : 1,
            'state'    => (int) ($payload['state'] ?? $record->state),
        ];

        if (!$model->save($data)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_SAVE'));
        }

        return json_encode(['success' => true] + $data);
    }

    /**
     * Set the article's intro or full image from a media-field value.
     *
     * @param   object  $model    The article model.
     * @param   object  $record   The current article record.
     * @param   array   $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doSaveImage($model, $record, array $payload): string
    {
        $url    = (string) ($payload['url'] ?? '');
        $target = (($payload['target'] ?? 'intro') === 'full') ? 'image_fulltext' : 'image_intro';

        $images          = \is_array($record->images) ? $record->images : (new Registry($record->images))->toArray();
        $images[$target] = $url;

        if (!$model->save(['id' => (int) $record->id, 'catid' => (int) $record->catid, 'images' => $images])) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_SAVE'));
        }

        return json_encode(['success' => true, 'target' => $target, 'url' => $url]);
    }

    /**
     * Create a new content category under the chosen parent and assign the article to it.
     *
     * @param   object  $model    The article model.
     * @param   object  $record   The current article record.
     * @param   array   $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doNewCategory($model, $record, array $payload): string
    {
        $name   = trim((string) ($payload['name'] ?? ''));
        $parent = (int) ($payload['parent'] ?? 1);

        if ($name === '') {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_INVALID'));
        }

        if (!$this->getApplication()->getIdentity()->authorise('core.create', 'com_content')) {
            return $this->fail(Text::_('JERROR_ALERTNOAUTHOR'));
        }

        // Reuse the same model the article form uses for inline category creation.
        $categoryModel = $this->getApplication()->bootComponent('com_categories')
            ->getMVCFactory()->createModel('Category', 'Administrator', ['ignore_request' => true]);

        $saved = $categoryModel->save([
            'id'        => 0,
            'title'     => $name,
            'parent_id' => $parent > 1 ? $parent : 1,
            'extension' => 'com_content',
            'language'  => '*',
            'published' => 1,
        ]);

        if (!$saved) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_SAVE'));
        }

        $catid = (int) $categoryModel->getState('category.id');

        if ($catid <= 0) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_SAVE'));
        }

        $data = [
            'id'       => (int) $record->id,
            'catid'    => $catid,
            'featured' => empty($payload['featured']) ? 0 : 1,
            'state'    => (int) ($payload['state'] ?? $record->state),
        ];

        if (!$model->save($data)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_CONTENT_ERROR_SAVE'));
        }

        return json_encode(['success' => true, 'catid' => $catid, 'name' => $name]);
    }

    /**
     * Build a flat, indented list of published content categories.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function categories(): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'title', 'level']))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('level') . ' > 0')
            ->order($db->quoteName('lft') . ' ASC');
        $db->setQuery($query);

        $list = [];

        foreach ($db->loadObjectList() as $row) {
            $list[] = [
                'id'    => (int) $row->id,
                'title' => str_repeat('— ', max(0, (int) $row->level - 1)) . $row->title,
            ];
        }

        return $list;
    }

    /**
     * Whether the current user may edit the given article record.
     *
     * @param   object  $record  The article record.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function canEdit(object $record): bool
    {
        $user  = $this->getApplication()->getIdentity();
        $asset = 'com_content.article.' . (int) $record->id;

        if ($user->authorise('core.edit', $asset)) {
            return true;
        }

        return $user->authorise('core.edit.own', $asset) && (int) $record->created_by === (int) $user->id;
    }

    /**
     * Boot the com_content administrator article model.
     *
     * @return  \Joomla\CMS\MVC\Model\AdminModel
     *
     * @since   __DEPLOY_VERSION__
     */
    private function articleModel()
    {
        return $this->getApplication()->bootComponent('com_content')->getMVCFactory()
            ->createModel('Article', 'Administrator', ['ignore_request' => true]);
    }

    /**
     * Build a JSON failure envelope.
     *
     * @param   string  $message  The message.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fail(string $message): string
    {
        return json_encode(['success' => false, 'message' => $message]);
    }
}
