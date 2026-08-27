<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.tagselect
 *
 * @copyright   (C) 2026 SuperSoft
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Language\Text;
use Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

FormHelper::addFieldPath(__DIR__ . '/fields');

/**
 * Fields Tag Select Plugin
 */
class PlgFieldsTagselect extends FieldsPlugin implements SubscriberInterface
{
    /**
     * Cached tag rows keyed by tag id.
     *
     * @var    array|null
     */
    protected $tagRows;

    /**
     * Cached managed-row sets keyed by config hash.
     *
     * @var    array
     */
    protected $managedRows = [];

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     */
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onContentBeforeSave' => 'handleContentBeforeSave',
            'onContentAfterSave'  => 'handleContentAfterSave',
        ]);
    }

    /**
     * Add the field node for the custom field and enforce tag picker behavior.
     *
     * @param   object      $field   The custom field object.
     * @param   DOMElement  $parent  Parent DOM element.
     * @param   Form        $form    Form object.
     *
     * @return  DOMElement|boolean
     */
    public function onCustomFieldsPrepareDom($field, DOMElement $parent, Form $form)
    {
        $fieldNode = parent::onCustomFieldsPrepareDom($field, $parent, $form);

        if (!$fieldNode) {
            return $fieldNode;
        }

        $mode = 'ajax';
        $multiple = true;
        $allowTagCreation = false;

        if (isset($field->fieldparams) && is_object($field->fieldparams) && method_exists($field->fieldparams, 'get')) {
            $configuredMode = strtolower((string) $field->fieldparams->get('mode', 'ajax'));

            if ($configuredMode === 'nested') {
                $mode = 'nested';
            }

            $multiple = (int) $field->fieldparams->get('multiple', 1) === 1;
            $allowTagCreation = (int) $field->fieldparams->get('allow_tag_creation', 0) === 1;
        }

        $fieldNode->setAttribute('mode', $mode);
        $fieldNode->setAttribute('custom', $allowTagCreation ? 'allow' : 'deny');
        $fieldNode->setAttribute('multiple', $multiple ? 'true' : 'false');

        return $fieldNode;
    }

    /**
     * Prepare the display value for native tag backed fields before rendering the layout.
     *
     * @param   string     $context  The render context.
     * @param   \stdClass  $item     The item.
     * @param   \stdClass  $field    The field.
     *
     * @return  ?string
     */
    public function onCustomFieldsPrepareField($context, $item, $field)
    {
        if ($this->isTypeSupported($field->type)) {
            $field->tagselectRenderTags = $this->getRenderableTagsForField($field, $item, (string) $context);
            $field->tagselectTags       = $field->tagselectRenderTags;
            $field->tagselectTagIds     = $this->extractRenderableTagIds($field->tagselectRenderTags);

            if ($this->getNativeContextConfig((string) $context, $item) && $this->isNativeTagsField($field)) {
                $field->value = $this->extractRenderableTagTitles($field->tagselectRenderTags);
            }
        }

        return parent::onCustomFieldsPrepareField($context, $item, $field);
    }

    /**
     * Merge native-tag-backed field selections into the Joomla item tag payload.
     *
     * @param   BeforeSaveEvent  $event  The event.
     *
     * @return  void
     */
    public function handleContentBeforeSave(BeforeSaveEvent $event): void
    {
        $context = (string) $event->getArgument('context', '');
        $item    = $event->getArgument('subject');
        $nativeContext = $this->getNativeContextConfig($context, $item);

        if (!$nativeContext) {
            return;
        }

        $data = $event->getArgument('data', []);

        if (!is_object($item) || !is_array($data) || empty($data['com_fields']) || !is_array($data['com_fields'])) {
            return;
        }

        $fields = $this->getNativeTagselectFields($nativeContext, $item);

        if (!$fields) {
            return;
        }

        $nativeTagIds = $this->normaliseTagIds(
            property_exists($item, 'newTags')
                ? $item->newTags
                : $this->loadNativeTagIdsForItem($item, $nativeContext['tag_type_alias'])
        );

        foreach ($fields as $field) {
            if (!array_key_exists($field->name, $data['com_fields'])) {
                continue;
            }

            $config           = $this->getStorageConfig($field->fieldparams ?? null);
            $submittedTagIds  = $this->filterManagedTagIds(
                $this->normaliseTagIds($data['com_fields'][$field->name]),
                $config
            );
            $managedCurrentIds = $this->filterManagedTagIds($nativeTagIds, $config);

            $nativeTagIds = array_values(array_unique(array_merge(
                array_diff($nativeTagIds, $managedCurrentIds),
                $submittedTagIds
            )));
        }

        $item->newTags = $nativeTagIds;

        if (method_exists($event, 'setArgument')) {
            $data['tags'] = $nativeTagIds;
            $event->setArgument('data', $data);
        }
    }

    /**
     * Clear redundant custom-field storage after native tag backed saves.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  void
     */
    public function handleContentAfterSave(AfterSaveEvent $event): void
    {
        $context = (string) $event->getArgument('context', '');
        $item    = $event->getArgument('subject');
        $nativeContext = $this->getNativeContextConfig($context, $item);

        if (!$nativeContext) {
            return;
        }

        if (!is_object($item) || empty($item->id)) {
            return;
        }

        $fields = $this->getNativeTagselectFields($nativeContext, $item);

        if (!$fields) {
            return;
        }

        $model = Factory::getApplication()->bootComponent('com_fields')->getMVCFactory()
            ->createModel('Field', 'Administrator', ['ignore_request' => true]);

        foreach ($fields as $field) {
            $model->setFieldValue((int) $field->id, (int) $item->id, null);
        }
    }

    /**
     * Map supported event/render contexts to their custom-field context and native tag alias.
     *
     * @param   string  $context  Event or render context.
     * @param   object  $item     Current item.
     *
     * @return  array|null
     */
    protected function getNativeContextConfig(string $context, $item = null): ?array
    {
        if ($context === 'com_content.article') {
            return [
                'fields_context' => 'com_content.article',
                'tag_type_alias' => 'com_content.article',
            ];
        }

        if ($context === 'com_categories.category') {
            if (!is_object($item) || (string) ($item->extension ?? '') !== 'com_content') {
                return null;
            }

            $item->catid = (int) ($item->id ?? 0);

            return [
                'fields_context' => 'com_content.categories',
                'tag_type_alias' => 'com_content.category',
            ];
        }

        if (in_array($context, ['com_content.categories', 'com_content.category'], true)) {
            if (is_object($item) && !isset($item->catid)) {
                $item->catid = (int) ($item->id ?? 0);
            }

            return [
                'fields_context' => 'com_content.categories',
                'tag_type_alias' => 'com_content.category',
            ];
        }

        return null;
    }

    /**
     * Determine whether a field uses native Joomla tags as its backing store.
     *
     * @param   object  $field  The field definition.
     *
     * @return  boolean
     */
    protected function isNativeTagsField($field): bool
    {
        if (!is_object($field) || empty($field->type) || !$this->isTypeSupported($field->type)) {
            return false;
        }

        return $this->getStorageConfig($field->fieldparams ?? null)['storage_mode'] === 'native_tags';
    }

    /**
     * Get the native tag backed field definitions for the current item.
     *
     * @param   array   $nativeContext  Native context mapping.
     * @param   object  $item     The item or save table.
     *
     * @return  array
     */
    protected function getNativeTagselectFields(array $nativeContext, $item): array
    {
        $fields = FieldsHelper::getFields($nativeContext['fields_context'], $item);

        if (!$fields) {
            return [];
        }

        return array_values(array_filter($fields, function ($field) {
            return $this->isNativeTagsField($field);
        }));
    }

    /**
     * Build the normalized storage/scope config from field params.
     *
     * The current UI exposes a single scope selector with explicit all/include/exclude modes.
     * Legacy storage/scope params are still read as a fallback for older field definitions.
     *
     * @param   mixed  $params  Field params.
     *
     * @return  array
     */
    protected function getStorageConfig($params): array
    {
        if (!$params instanceof Registry) {
            $params = new Registry($params);
        }

        $fieldType = strtolower(trim((string) $params->get('field_type', '')));

        if ($fieldType === '') {
            $legacyStorageMode = strtolower(trim((string) $params->get('storage_mode', 'field_value')));
            $fieldType         = $legacyStorageMode === 'native_tags' ? 'native_article_tags' : 'independent';
        }

        if ($fieldType !== 'native_article_tags') {
            $fieldType = 'independent';
        }

        $storageMode = $fieldType === 'native_article_tags' ? 'native_tags' : 'field_value';

        $scopeRootIds = $this->normaliseConfiguredIds($params->get('scope_root_ids', []));

        if (!$scopeRootIds) {
            $scopeRootIds = $this->normaliseConfiguredIds($params->get('root_parent_id', []));
        }

        $scopeMode = strtolower(trim((string) $params->get('tag_scope_mode', '')));

        if (!in_array($scopeMode, ['all', 'include', 'exclude'], true)) {
            $legacyExcludedRootIds = $storageMode === 'native_tags' ? $this->normaliseConfiguredIds($params->get('excluded_root_ids', [])) : [];

            if ($scopeRootIds) {
                $scopeMode = 'include';
            } elseif ($legacyExcludedRootIds) {
                $scopeMode   = 'exclude';
                $scopeRootIds = $legacyExcludedRootIds;
            } else {
                $scopeMode = 'all';
            }
        }

        if (!$scopeRootIds || $scopeMode === 'all') {
            $scopeMode   = 'all';
            $scopeRootIds = [];
        }

        $allowTagCreation = $this->toBool($params->get('allow_tag_creation', '0'));
        $scopeDepth       = max(0, (int) $params->get('scope_depth', 0));

        return [
            'field_type'                => $fieldType,
            'storage_mode'              => $storageMode,
            'scope_mode'                => $scopeMode === 'all' ? 'none' : $scopeMode,
            'root_parent_ids'           => $scopeMode === 'include' ? $scopeRootIds : [],
            'excluded_root_ids'         => $scopeMode === 'exclude' ? $scopeRootIds : [],
            'scope_depth'               => $scopeMode === 'exclude' ? 0 : $scopeDepth,
            'leaf_only'                 => $this->toBool($params->get('leaf_only', '0')),
            'allow_tag_creation'        => $allowTagCreation,
            'allow_root_level_creation' => $allowTagCreation && $this->toBool($params->get('allow_root_level_creation', '0')),
        ];
    }

    /**
     * Build frontend renderable tag objects for a field instance.
     *
     * @param   object  $field    Field definition.
     * @param   mixed   $item     Current item.
     * @param   string  $context  Render context.
     *
     * @return  array
     */
    protected function getRenderableTagsForField($field, $item, string $context): array
    {
        if (!is_object($field) || empty($field->type) || !$this->isTypeSupported($field->type)) {
            return [];
        }

        $config = $this->getStorageConfig($field->fieldparams ?? null);
        $nativeContext = $this->getNativeContextConfig($context, $item);

        if ($nativeContext && $config['storage_mode'] === 'native_tags') {
            return $this->buildRenderableTagsFromIds(
                $this->filterManagedTagIds(
                    $this->loadNativeTagIdsForItem($item, $nativeContext['tag_type_alias']),
                    $config
                )
            );
        }

        $rawValue = property_exists($field, 'rawvalue') ? $field->rawvalue : (property_exists($field, 'value') ? $field->value : []);
        $tagIds   = $this->normaliseTagIds($rawValue);

        if (!$tagIds && property_exists($field, 'value')) {
            $tagIds = $this->normaliseTagIds($field->value);
        }

        return $this->buildRenderableTagsFromIds($tagIds);
    }

    /**
     * Build renderable tag objects from tag ids.
     *
     * @param   array  $tagIds  Tag ids in display order.
     *
     * @return  array
     */
    protected function buildRenderableTagsFromIds(array $tagIds): array
    {
        if (!$tagIds) {
            return [];
        }

        $rows = $this->loadRowsByIds($tagIds);
        $tags = [];

        foreach ($tagIds as $tagId) {
            $tagId = (int) $tagId;

            if ($tagId <= 0 || !isset($rows[$tagId])) {
                continue;
            }

            $row = $rows[$tagId];

            $tags[] = (object) [
                'id'      => $tagId,
                'tag_id'  => $tagId,
                'title'   => (string) $row->title,
                'alias'   => (string) ($row->alias ?? ''),
                'slug'    => $tagId . ':' . (string) ($row->alias ?? ''),
                'access'  => isset($row->access) ? (int) $row->access : 1,
                'params'  => (string) ($row->params ?? ''),
                'display' => (string) $row->title,
            ];
        }

        return $tags;
    }

    /**
     * Extract display titles from renderable tag objects.
     *
     * @param   array  $tags  Renderable tag objects.
     *
     * @return  array
     */
    protected function extractRenderableTagTitles(array $tags): array
    {
        $titles = [];

        foreach ($tags as $tag) {
            if (!is_object($tag)) {
                continue;
            }

            $title = trim((string) ($tag->display ?? $tag->title ?? ''));

            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Extract ids from renderable tag objects.
     *
     * @param   array  $tags  Renderable tag objects.
     *
     * @return  array
     */
    protected function extractRenderableTagIds(array $tags): array
    {
        $ids = [];

        foreach ($tags as $tag) {
            if (!is_object($tag)) {
                continue;
            }

            $tagId = (int) ($tag->tag_id ?? $tag->id ?? 0);

            if ($tagId > 0) {
                $ids[] = $tagId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Load the current native tag ids for the given item.
     *
     * @param   object  $item       Render/save item.
     * @param   string  $typeAlias  Joomla tag type alias.
     *
     * @return  array
     */
    protected function loadNativeTagIdsForItem($item, string $typeAlias): array
    {
        if (is_object($item) && property_exists($item, 'newTags')) {
            $tagIds = $this->normaliseTagIds($item->newTags);

            if ($tagIds || empty($item->id)) {
                return $tagIds;
            }
        }

        if (is_object($item) && property_exists($item, 'tags')) {
            $tagIds = $this->normaliseTagIds($item->tags);

            if ($tagIds || empty($item->id)) {
                return $tagIds;
            }
        }

        if (!is_object($item) || empty($item->id)) {
            return [];
        }

        $helper = new TagsHelper();
        $helper->getTagIds((int) $item->id, $typeAlias);

        return $this->normaliseTagIds($helper);
    }

    /**
     * Filter a tag-id list to the subset managed by a single native-tag config.
     *
     * @param   array  $tagIds   Candidate native tag ids.
     * @param   array  $config   Storage/scope config.
     *
     * @return  array
     */
    protected function filterManagedTagIds(array $tagIds, array $config): array
    {
        if (!$tagIds || $config['storage_mode'] !== 'native_tags') {
            return [];
        }

        $managedRows = $this->getManagedRows($config);

        if (!$managedRows) {
            return [];
        }

        $managedLookup = array_fill_keys(array_keys($managedRows), true);

        return array_values(array_filter($tagIds, static function ($tagId) use ($managedLookup) {
            return isset($managedLookup[$tagId]);
        }));
    }

    /**
     * Return the managed row set keyed by tag id for a specific config.
     *
     * @param   array  $config  Storage/scope config.
     *
     * @return  array
     */
    protected function getManagedRows(array $config): array
    {
        if ($config['storage_mode'] !== 'native_tags') {
            return [];
        }

        $cacheKey = md5(json_encode($config));

        if (isset($this->managedRows[$cacheKey])) {
            return $this->managedRows[$cacheKey];
        }

        $rows = [];

        foreach ($this->loadAllTagRows() as $row) {
            if (!$this->isRowManagedByConfig($row, $config)) {
                continue;
            }

            $rows[(int) $row->value] = $row;
        }

        if ($config['leaf_only']) {
            $rows = $this->filterLeafRows($rows);
        }

        $this->managedRows[$cacheKey] = $rows;

        return $this->managedRows[$cacheKey];
    }

    /**
     * Decide whether a row belongs to the managed native-tag subset.
     *
     * @param   object  $row     Tag row.
     * @param   array   $config  Storage/scope config.
     *
     * @return  boolean
     */
    protected function isRowManagedByConfig($row, array $config): bool
    {
        if ($config['scope_mode'] === 'none') {
            return $config['scope_depth'] === 0 || (int) $row->level <= $config['scope_depth'];
        }

        if ($config['scope_mode'] === 'include') {
            if ($config['allow_root_level_creation'] && $this->isRootLevelTagRow($row)) {
                return true;
            }

            $roots = $this->loadRowsByIds($config['root_parent_ids']);

            if (!$roots) {
                return false;
            }

            foreach ($roots as $root) {
                if ($this->isRowWithinScopeDepth($row, $root, $config['scope_depth'])) {
                    return true;
                }
            }

            return false;
        }

        if ($config['scope_mode'] === 'exclude') {
            $excludedRoots = $this->loadRowsByIds($config['excluded_root_ids']);

            foreach ($excludedRoots as $root) {
                if ((int) $row->value === (int) $root->value) {
                    return false;
                }

                if ((int) $row->lft > (int) $root->lft && (int) $row->rgt < (int) $root->rgt) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check whether a row is a descendant within the configured number of levels.
     *
     * @param   object   $row         Candidate row.
     * @param   object   $root        Scope root row.
     * @param   integer  $scopeDepth  Zero for unlimited, otherwise maximum relative depth.
     *
     * @return  boolean
     */
    protected function isRowWithinScopeDepth($row, $root, int $scopeDepth): bool
    {
        if ((int) $row->lft <= (int) $root->lft || (int) $row->rgt >= (int) $root->rgt) {
            return false;
        }

        return $scopeDepth === 0 || ((int) $row->level - (int) $root->level) <= $scopeDepth;
    }

    /**
     * Keep only leaf nodes inside the managed row set.
     *
     * @param   array  $rows  Managed rows keyed by tag id.
     *
     * @return  array
     */
    protected function filterLeafRows(array $rows): array
    {
        $hasChildren = [];

        foreach ($rows as $row) {
            $hasChildren[(int) $row->parent_id] = true;
        }

        return array_filter($rows, static function ($row) use ($hasChildren) {
            return !isset($hasChildren[(int) $row->value]);
        });
    }

    /**
     * Load all tag rows needed for native-tag scope calculations.
     *
     * @return  array
     */
    protected function loadAllTagRows(): array
    {
        if ($this->tagRows !== null) {
            return $this->tagRows;
        }

        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('a.id', 'value'),
                    $db->quoteName('a.path'),
                    $db->quoteName('a.title', 'text'),
                    $db->quoteName('a.title'),
                    $db->quoteName('a.alias'),
                    $db->quoteName('a.access'),
                    $db->quoteName('a.params'),
                    $db->quoteName('a.level'),
                    $db->quoteName('a.parent_id'),
                    $db->quoteName('a.lft'),
                    $db->quoteName('a.rgt'),
                ]
            )
            ->from($db->quoteName('#__tags', 'a'))
            ->where($db->quoteName('a.lft') . ' > 0')
            ->order($db->quoteName('a.lft') . ' ASC');

        $db->setQuery($query);

        try {
            $rows = $db->loadObjectList();
        } catch (RuntimeException $e) {
            $rows = [];
        }

        $this->tagRows = [];

        foreach ($rows as $row) {
            $this->tagRows[(int) $row->value] = $row;
        }

        return $this->tagRows;
    }

    /**
     * Load arbitrary tag rows by ids.
     *
     * @param   array  $ids  Tag ids.
     *
     * @return  array
     */
    protected function loadRowsByIds(array $ids): array
    {
        $lookup = $this->loadAllTagRows();
        $result = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0 && isset($lookup[$id])) {
                $result[$id] = $lookup[$id];
            }
        }

        return $result;
    }

    /**
     * Determine whether a row is a top-level Joomla tag.
     *
     * @param   object  $row  Tag row.
     *
     * @return  boolean
     */
    protected function isRootLevelTagRow($row): bool
    {
        return (int) $row->parent_id === $this->getTagRootId();
    }

    /**
     * Get the hidden Joomla tag-tree root id.
     *
     * @return  integer
     */
    protected function getTagRootId(): int
    {
        static $tagRootId = null;

        if ($tagRootId !== null) {
            return $tagRootId;
        }

        $tagTable  = Factory::getApplication()->bootComponent('com_tags')->getMVCFactory()->createTable('Tag', 'Administrator');
        $tagRootId = (int) $tagTable->getRootId();

        return $tagRootId;
    }

    /**
     * Normalize a configured tag-id list.
     *
     * @param   mixed  $value  Raw param value.
     *
     * @return  array
     */
    protected function normaliseConfiguredIds($value): array
    {
        if ($value instanceof Registry) {
            $value = $value->toArray();
        }

        if (is_int($value)) {
            $value = [$value];
        } elseif (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return [];
            }

            if ($value[0] === '[' || $value[0] === '{') {
                $decoded = json_decode($value, true);
                $value   = is_array($decoded) ? $decoded : explode(',', $value);
            } else {
                $value = explode(',', $value);
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        array_walk_recursive($value, static function ($item) use (&$ids) {
            $item = trim((string) $item);

            if ($item !== '' && is_numeric($item)) {
                $ids[] = (int) $item;
            }
        });

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Normalize a native tag-id payload.
     *
     * @param   mixed  $value  Raw value.
     *
     * @return  array
     */
    protected function normaliseTagIds($value): array
    {
        if ($value instanceof TagsHelper) {
            $value = empty($value->tags) ? [] : $value->tags;
        }

        if (is_object($value) && !($value instanceof \Traversable)) {
            $tagId = $this->extractNumericTagId($value);

            return $tagId > 0 ? [$tagId] : [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        } elseif (is_int($value)) {
            $value = [$value];
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $tagId = $this->extractNumericTagId($item);

            if ($tagId > 0) {
                $ids[] = $tagId;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Extract a numeric tag id from mixed Joomla tag payloads.
     *
     * @param   mixed  $item  Candidate tag payload.
     *
     * @return  integer
     */
    protected function extractNumericTagId($item): int
    {
        if (is_array($item)) {
            foreach (['value', 'id', 'tag_id'] as $key) {
                if (array_key_exists($key, $item) && is_numeric((string) $item[$key])) {
                    return (int) $item[$key];
                }
            }

            return 0;
        }

        if (is_object($item)) {
            foreach (['value', 'id', 'tag_id'] as $property) {
                if (isset($item->$property) && is_numeric((string) $item->$property)) {
                    return (int) $item->$property;
                }
            }

            return 0;
        }

        $item = trim((string) $item);

        return $item !== '' && is_numeric($item) ? (int) $item : 0;
    }

    /**
     * Normalize yes/no style values.
     *
     * @param   mixed  $value  Raw param value.
     *
     * @return  boolean
     */
    protected function toBool($value): bool
    {
        return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'off'], true);
    }
}
