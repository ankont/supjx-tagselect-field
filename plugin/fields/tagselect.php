<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.tagselect
 *
 * @copyright   (C) 2026 SuperSoft
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\TagField;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Joomla\String\StringHelper;
use Joomla\Utilities\ArrayHelper;

/**
 * Form Field class for the Joomla tagselect custom field type.
 */
class JFormFieldTagselect extends TagField
{
    /**
     * The form field type.
     *
     * @var    string
     */
    public $type = 'tagselect';

    /**
     * Cached restriction state.
     *
     * @var    array|null
     */
    protected $restrictionConfig;

    /**
     * Cached allowed rows.
     *
     * @var    array|null
     */
    protected $allowedRows;

    /**
     * Cached configured root rows keyed by tag id.
     *
     * @var    array|null
     */
    protected $rootRows;

    /**
     * Cached excluded root rows keyed by tag id.
     *
     * @var    array|null
     */
    protected $excludedRootRows;

    /**
     * Cached Joomla tag tree root id.
     *
     * @var    integer|null
     */
    protected $tagRootId;

    /**
     * Setup the field and force settings required by this custom field type.
     *
     * @param   SimpleXMLElement  $element  XML element.
     * @param   mixed             $value    Field value.
     * @param   string            $group    Group.
     *
     * @return  boolean
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $mode = strtolower((string) ($element['mode'] ?? 'ajax'));

        $modeSource = trim((string) ($element['sync_mode_from'] ?? ''));

        if ($modeSource !== '' && $this->form) {
            $modeGroup = trim((string) ($element['sync_mode_group'] ?? 'fieldparams'));
            $modeValue = $this->form->getValue($modeSource, $modeGroup, $mode);

            if (is_scalar($modeValue)) {
                $mode = strtolower(trim((string) $modeValue));
            }
        }

        if ($mode !== 'ajax' && $mode !== 'nested') {
            $mode = 'ajax';
        }

        $element['mode'] = $mode;

        $allowTagCreation = $this->toBool((string) ($element['allow_tag_creation'] ?? '0'));
        $element['custom'] = $allowTagCreation ? 'allow' : 'deny';

        $multipleAttr = strtolower((string) ($element['multiple'] ?? 'true'));
        $isMultiple   = !in_array($multipleAttr, ['0', 'false', 'no'], true);
        $element['multiple'] = $isMultiple ? 'true' : 'false';

        $this->restrictionConfig = null;
        $this->allowedRows       = null;
        $this->rootRows          = null;
        $this->excludedRootRows  = null;
        $this->tagRootId         = null;

        $result = parent::setup($element, $value, $group);

        if (
            $result
            && $this->usesNativeTagsStorage()
            && !$this->hasSubmittedFieldValue($group, (string) ($element['name'] ?? ''))
        ) {
            $this->value = $this->getManagedNativeTagIds();

            if (!$this->multiple) {
                $this->value = $this->value[0] ?? '';
            }
        }

        return $result;
    }

    /**
     * Determines if the field allows or denies custom values.
     *
     * @return  boolean
     */
    public function allowCustom()
    {
        if (!$this->getRestrictionConfig()['allow_tag_creation']) {
            return false;
        }

        return parent::allowCustom();
    }

    /**
     * Check whether the field should enable core remote search.
     *
     * When subtree or leaf restrictions are active, use the same searchable UI
     * with preloaded server-filtered options instead of the unrestricted core endpoint.
     *
     * @return  boolean
     */
    public function isRemoteSearch()
    {
        if ($this->hasActiveRestrictions()) {
            return false;
        }

        return parent::isRemoteSearch();
    }

    /**
     * Method to get a list of tags.
     *
     * @return  object[]  The field option objects.
     */
    protected function getOptions()
    {
        if (!$this->hasActiveRestrictions()) {
            return parent::getOptions();
        }

        $options = array_values($this->getAllowedRows());
        $options = array_merge($options, $this->getPreservedCurrentRows(array_keys($this->getAllowedRows())));

        if ($this->isNested()) {
            $this->formatNestedOptions($options);
        } else {
            $this->formatFlatOptions($options);
        }

        return $options;
    }

    /**
     * Filter and normalize the submitted value.
     *
     * @param   mixed      $value  The submitted value.
     * @param   string     $group  The optional form group.
     * @param   ?Registry  $input  Full form input.
     *
     * @return  mixed
     */
    public function filter($value, $group = null, ?Registry $input = null)
    {
        $submitted = $this->normaliseSubmittedValues($value);
        $resolved  = [];
        $config    = $this->getRestrictionConfig();

        foreach ($submitted as $item) {
            if (is_string($item) && str_starts_with($item, '#new#')) {
                $tagId = $this->createOrResolveTag(substr($item, 5), $config);

                if ($tagId > 0) {
                    $resolved[] = $tagId;
                }

                continue;
            }

            $resolved[] = (int) $item;
        }

        $resolved = array_values(array_unique(array_filter($resolved, static function ($id) {
            return $id > 0;
        })));

        if ($this->hasActiveRestrictions()) {
            $allowedLookup = array_fill_keys(array_keys($this->getAllowedRows()), true);
            $currentLookup = array_fill_keys($this->getCurrentValueIds(), true);

            $resolved = array_values(array_filter($resolved, static function ($id) use ($allowedLookup, $currentLookup) {
                return isset($allowedLookup[$id]) || isset($currentLookup[$id]);
            }));
        }

        if (!$this->multiple) {
            return $resolved[0] ?? '';
        }

        return $resolved;
    }

    /**
     * Return the parsed restriction config.
     *
     * @return  array
     */
    protected function getRestrictionConfig()
    {
        if ($this->restrictionConfig !== null) {
            return $this->restrictionConfig;
        }

        $fieldType = strtolower(trim((string) $this->getAttribute('field_type', '')));

        if ($fieldType === '') {
            $legacyStorageMode = strtolower(trim((string) $this->getAttribute('storage_mode', 'field_value')));
            $fieldType         = $legacyStorageMode === 'native_tags' ? 'native_article_tags' : 'independent';
        }

        if ($fieldType !== 'native_article_tags' || !$this->isArticleNativeTagsForm()) {
            $fieldType   = 'independent';
            $storageMode = 'field_value';
        } else {
            $storageMode = 'native_tags';
        }

        $scopeRootIds = $this->normaliseConfiguredIds($this->getAttribute('scope_root_ids', []));

        if (!$scopeRootIds) {
            $scopeRootIds = $this->normaliseConfiguredIds($this->getAttribute('root_parent_id', []));
        }

        $scopeMode = strtolower(trim((string) $this->getAttribute('tag_scope_mode', '')));

        if (!in_array($scopeMode, ['all', 'include', 'exclude'], true)) {
            $legacyExcludedRootIds = $storageMode === 'native_tags'
                ? $this->normaliseConfiguredIds($this->getAttribute('excluded_root_ids', []))
                : [];

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

        $allowTagCreation  = $this->toBool($this->getAttribute('allow_tag_creation', '0'));
        $includeDescendants = $fieldType === 'native_article_tags'
            ? true
            : ($scopeMode === 'include' && $scopeRootIds
                ? $this->toBool($this->getAttribute('include_descendants', '1'))
                : true);

        $this->restrictionConfig = [
            'field_type'                => $fieldType,
            'storage_mode'              => $storageMode,
            'scope_mode'                => $scopeMode === 'all' ? 'none' : $scopeMode,
            'root_parent_ids'           => $scopeMode === 'include' ? $scopeRootIds : [],
            'excluded_root_ids'         => $scopeMode === 'exclude' ? $scopeRootIds : [],
            'include_descendants'       => $includeDescendants,
            'leaf_only'                 => $this->toBool($this->getAttribute('leaf_only', '0')),
            'allow_tag_creation'        => $allowTagCreation,
            'allow_root_level_creation' => $allowTagCreation && $this->toBool($this->getAttribute('allow_root_level_creation', '0')),
        ];

        return $this->restrictionConfig;
    }

    /**
     * Determine whether selection restrictions are active.
     *
     * @return  boolean
     */
    protected function hasActiveRestrictions()
    {
        $config = $this->getRestrictionConfig();

        return $config['scope_mode'] !== 'none' || $config['leaf_only'];
    }

    /**
     * Return the cached allowed rows keyed by tag id.
     *
     * @return  array
     */
    protected function getAllowedRows()
    {
        if ($this->allowedRows !== null) {
            return $this->allowedRows;
        }

        $rows   = [];
        $config = $this->getRestrictionConfig();
        $roots  = $this->getRootRows();

        foreach ($this->loadCandidateRows() as $row) {
            if (!$this->isRowAllowed($row, $roots, $config)) {
                continue;
            }

            $rows[(int) $row->value] = $row;
        }

        if ($config['leaf_only']) {
            $rows = $this->filterLeafRows($rows);
        }

        $this->allowedRows = $rows;

        return $this->allowedRows;
    }

    /**
     * Load the configured root rows keyed by tag id.
     *
     * @return  array
     */
    protected function getRootRows()
    {
        if ($this->rootRows !== null) {
            return $this->rootRows;
        }

        $rootParentIds = $this->getRestrictionConfig()['root_parent_ids'];

        if (!$rootParentIds) {
            $this->rootRows = [];

            return $this->rootRows;
        }

        $this->rootRows = $this->loadRowsByIds($rootParentIds);

        return $this->rootRows;
    }

    /**
     * Load the configured excluded root rows keyed by tag id.
     *
     * @return  array
     */
    protected function getExcludedRootRows()
    {
        if ($this->excludedRootRows !== null) {
            return $this->excludedRootRows;
        }

        $excludedRootIds = $this->getRestrictionConfig()['excluded_root_ids'];

        if (!$excludedRootIds) {
            $this->excludedRootRows = [];

            return $this->excludedRootRows;
        }

        $this->excludedRootRows = $this->loadRowsByIds($excludedRootIds);

        return $this->excludedRootRows;
    }

    /**
     * Load candidate tag rows using the same baseline filters as the core field.
     *
     * @return  array
     */
    protected function loadCandidateRows()
    {
        $published = (string) $this->element['published'] ?: [0, 1];
        $app       = Factory::getApplication();
        $language  = null;
        $db        = $this->getDatabase();
        $query     = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('a.id', 'value'),
                    $db->quoteName('a.path'),
                    $db->quoteName('a.title', 'text'),
                    $db->quoteName('a.title'),
                    $db->quoteName('a.level'),
                    $db->quoteName('a.parent_id'),
                    $db->quoteName('a.published'),
                    $db->quoteName('a.lft'),
                    $db->quoteName('a.rgt'),
                ]
            )
            ->from($db->quoteName('#__tags', 'a'))
            ->where($db->quoteName('a.lft') . ' > 0')
            ->order($db->quoteName('a.lft') . ' ASC');

        if ($app->isClient('site') && Multilanguage::isEnabled()) {
            if (ComponentHelper::getParams('com_tags')->get('tag_list_language_filter') === 'current_language') {
                $language = [$app->getLanguage()->getTag(), '*'];
            }
        } elseif (!empty($this->element['language'])) {
            if (str_contains((string) $this->element['language'], ',')) {
                $language = explode(',', (string) $this->element['language']);
            } else {
                $language = [(string) $this->element['language']];
            }
        }

        if ($language) {
            $query->whereIn($db->quoteName('a.language'), $language, ParameterType::STRING);
        }

        if (is_numeric($published)) {
            $published = (int) $published;
            $query->where($db->quoteName('a.published') . ' = :published')
                ->bind(':published', $published, ParameterType::INTEGER);
        } elseif (is_array($published)) {
            $published = ArrayHelper::toInteger($published);
            $query->whereIn($db->quoteName('a.published'), $published);
        }

        $db->setQuery($query);

        try {
            return $db->loadObjectList();
        } catch (RuntimeException $e) {
            return [];
        }
    }

    /**
     * Decide whether a candidate row belongs to the configured selectable scope.
     *
     * @param   object       $row     Candidate row.
     * @param   array  $roots   Root rows keyed by id.
     * @param   array  $config  Restriction config.
     *
     * @return  boolean
     */
    protected function isRowAllowed($row, array $roots, array $config)
    {
        if ($config['scope_mode'] === 'none') {
            return true;
        }

        if ($config['scope_mode'] === 'exclude') {
            foreach ($this->getExcludedRootRows() as $root) {
                if ((int) $row->value === (int) $root->value) {
                    return false;
                }

                if ((int) $row->lft > (int) $root->lft && (int) $row->rgt < (int) $root->rgt) {
                    return false;
                }
            }

            return true;
        }

        if ($config['allow_root_level_creation'] && $this->isRootLevelTagRow($row)) {
            return true;
        }

        if (!$roots) {
            return false;
        }

        if ($config['include_descendants']) {
            foreach ($roots as $root) {
                if ((int) $row->lft > (int) $root->lft && (int) $row->rgt < (int) $root->rgt) {
                    return true;
                }
            }

            return false;
        }

        return in_array((int) $row->parent_id, $config['root_parent_ids'], true);
    }

    /**
     * Keep only rows that are leaf nodes inside the current allowed scope.
     *
     * @param   array  $rows  Allowed rows keyed by tag id.
     *
     * @return  array
     */
    protected function filterLeafRows(array $rows)
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
     * Return currently selected rows that are no longer in scope.
     *
     * @param   array  $allowedIds  Already allowed ids.
     *
     * @return  array
     */
    protected function getPreservedCurrentRows(array $allowedIds)
    {
        $currentIds = array_diff($this->getCurrentValueIds(), $allowedIds);

        if (!$currentIds) {
            return [];
        }

        $rows = $this->loadRowsByIds($currentIds);
        $list = [];

        foreach ($currentIds as $id) {
            if (isset($rows[$id])) {
                $row = $rows[$id];
            } else {
                $row = (object) [
                    'value'     => $id,
                    'text'      => Text::sprintf('PLG_FIELDS_TAGSELECT_INVALID_SELECTION_MISSING', $id),
                    'title'     => Text::sprintf('PLG_FIELDS_TAGSELECT_INVALID_SELECTION_MISSING', $id),
                    'path'      => '',
                    'level'     => 1,
                    'parent_id' => 0,
                    'lft'       => 0,
                    'rgt'       => 0,
                ];
            }

            $row->isInvalidSelection = true;
            $list[] = $row;
        }

        return $list;
    }

    /**
     * Load arbitrary tag rows by ids.
     *
     * @param   array  $ids  Tag ids.
     *
     * @return  array
     */
    protected function loadRowsByIds(array $ids)
    {
        $ids = ArrayHelper::toInteger($ids);
        $ids = array_values(array_filter(array_unique($ids)));

        if (!$ids) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('a.id', 'value'),
                    $db->quoteName('a.path'),
                    $db->quoteName('a.title', 'text'),
                    $db->quoteName('a.title'),
                    $db->quoteName('a.level'),
                    $db->quoteName('a.parent_id'),
                    $db->quoteName('a.published'),
                    $db->quoteName('a.lft'),
                    $db->quoteName('a.rgt'),
                ]
            )
            ->from($db->quoteName('#__tags', 'a'))
            ->whereIn($db->quoteName('a.id'), $ids);

        $db->setQuery($query);

        try {
            $rows = $db->loadObjectList();
        } catch (RuntimeException $e) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->value] = $row;
        }

        return $result;
    }

    /**
     * Format rows for nested mode, rebasing indentation when a subtree root is configured.
     *
     * @param   array  $options  Options by reference.
     *
     * @return  void
     */
    protected function formatNestedOptions(array &$options)
    {
        $roots = $this->getRootRows();

        foreach ($options as $option) {
            if (!isset($option->value)) {
                continue;
            }

            $label = $option->text;

            if (empty($option->isInvalidSelection)) {
                $relativeLevel = max(0, (int) $option->level - $this->getBaseLevelForRow($option, $roots));
                $label         = str_repeat('- ', $relativeLevel) . $label;
            }

            if (!empty($option->isInvalidSelection)) {
                $label = Text::sprintf('PLG_FIELDS_TAGSELECT_INVALID_SELECTION_LABEL', $label);
            }

            $option->text = $label;
        }
    }

    /**
     * Format rows for flat mode using relative subtree paths where possible.
     *
     * @param   array  $options  Options by reference.
     *
     * @return  void
     */
    protected function formatFlatOptions(array &$options)
    {
        $rootPathTexts = $this->getRootPathTexts();

        $options = TagsHelper::convertPathsToNames($options);
        $prepared = [];
        $relativeCounts = [];
        $fullCounts = [];

        foreach ($options as $option) {
            if (!isset($option->value)) {
                continue;
            }

            $fullLabel = (string) $option->text;
            $label = $fullLabel;

            foreach ($rootPathTexts as $rootPathText) {
                if (str_starts_with($label, $rootPathText . '/')) {
                    $label = substr($label, strlen($rootPathText) + 1);
                    break;
                }
            }

            $prepared[] = [
                'option' => $option,
                'full' => $fullLabel,
                'relative' => $label,
            ];

            $relativeKey = $this->normaliseLookupText($label);
            $fullKey = $this->normaliseLookupText($fullLabel);

            if ($relativeKey !== '') {
                $relativeCounts[$relativeKey] = ($relativeCounts[$relativeKey] ?? 0) + 1;
            }

            if ($fullKey !== '') {
                $fullCounts[$fullKey] = ($fullCounts[$fullKey] ?? 0) + 1;
            }
        }

        foreach ($prepared as $item) {
            $option = $item['option'];
            $label = $item['relative'];
            $relativeKey = $this->normaliseLookupText($item['relative']);
            $fullKey = $this->normaliseLookupText($item['full']);

            if ($relativeKey !== '' && ($relativeCounts[$relativeKey] ?? 0) > 1) {
                $label = $item['full'];
            }

            if ($fullKey !== '' && ($fullCounts[$fullKey] ?? 0) > 1 && !empty($option->value)) {
                $label = sprintf('%s [#%d]', $label, (int) $option->value);
            }

            if (!empty($option->isInvalidSelection)) {
                $label = Text::sprintf('PLG_FIELDS_TAGSELECT_INVALID_SELECTION_LABEL', $label);
            }

            $option->text = $label;
        }
    }

    /**
     * Return the named path for a tag row.
     *
     * @param   object  $row  Tag row.
     *
     * @return  string
     */
    protected function getNamedPathText($row)
    {
        $rows = TagsHelper::convertPathsToNames([clone $row]);

        return $rows[0]->text ?? (string) $row->text;
    }

    /**
     * Return all comparable labels that may identify a row from user input.
     *
     * @param   object  $row  Tag row.
     *
     * @return  array
     */
    protected function getComparableLabelsForRow($row)
    {
        $labels = [
            (string) $row->title,
            $this->getNamedPathText($row),
            $this->getRelativeNamedPathText($row),
        ];

        $labels = array_map('strval', $labels);
        $labels = array_map('trim', $labels);

        return array_values(array_unique(array_filter($labels, static function ($label) {
            return $label !== '';
        })));
    }

    /**
     * Return a root-relative named path where possible.
     *
     * @param   object  $row  Tag row.
     *
     * @return  string
     */
    protected function getRelativeNamedPathText($row)
    {
        $label = $this->getNamedPathText($row);

        foreach ($this->getRootPathTexts() as $rootPathText) {
            if ($label === $rootPathText) {
                return $label;
            }

            if (str_starts_with($label, $rootPathText . '/')) {
                return substr($label, strlen($rootPathText) + 1);
            }
        }

        return $label;
    }

    /**
     * Get the relative indentation base level for a row.
     *
     * @param   object  $row    Tag row.
     * @param   array   $roots  Root rows keyed by id.
     *
     * @return  integer
     */
    protected function getBaseLevelForRow($row, array $roots)
    {
        if (!$roots) {
            return 1;
        }

        $baseLevel = 1;

        foreach ($roots as $root) {
            if ((int) $row->lft > (int) $root->lft && (int) $row->rgt < (int) $root->rgt) {
                $baseLevel = max($baseLevel, (int) $root->level + 1);
            }
        }

        return $baseLevel;
    }

    /**
     * Return named root path prefixes sorted by longest first.
     *
     * @return  array
     */
    protected function getRootPathTexts()
    {
        $paths = [];

        foreach ($this->getRootRows() as $root) {
            $path = $this->getNamedPathText($root);

            if ($path !== '') {
                $paths[] = $path;
            }
        }

        usort($paths, static function ($left, $right) {
            return strlen($right) <=> strlen($left);
        });

        return $paths;
    }

    /**
     * Get current numeric tag ids from the field value.
     *
     * @return  array
     */
    protected function getCurrentValueIds()
    {
        return $this->normaliseStoredValues($this->value);
    }

    /**
     * Normalize stored values to numeric ids.
     *
     * @param   mixed  $value  Current field value.
     *
     * @return  array
     */
    protected function normaliseStoredValues($value)
    {
        if ($value instanceof TagsHelper) {
            $value = empty($value->tags) ? [] : $value->tags;
        }

        if (is_object($value) && !($value instanceof \Traversable)) {
            $tagId = $this->extractStoredTagId($value);

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
            $tagId = $this->extractStoredTagId($item);

            if ($tagId > 0) {
                $ids[] = $tagId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Normalize submitted values and keep custom-tag tokens when present.
     *
     * @param   mixed  $value  Submitted field value.
     *
     * @return  array
     */
    protected function normaliseSubmittedValues($value)
    {
        if (is_string($value)) {
            $value = $this->multiple ? explode(',', $value) : [$value];
        } elseif (is_int($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                continue;
            }

            $item = trim((string) $item);

            if ($item === '') {
                continue;
            }

            if (str_starts_with($item, '#new#')) {
                $items[] = $item;

                continue;
            }

            if (is_numeric($item)) {
                $items[] = (int) $item;
            }
        }

        return $items;
    }

    /**
     * Create a new tag or reuse an existing one inside the allowed scope.
     *
     * @param   string  $title   Submitted tag title.
     * @param   array   $config  Restriction config.
     *
     * @return  integer
     */
    protected function createOrResolveTag($title, array $config)
    {
        $title = trim($title);

        if ($title === '' || !$this->allowCustom()) {
            return 0;
        }

        $existingId = $this->findAllowedTagIdByInput($title);

        if ($existingId > 0) {
            return $existingId;
        }

        [$parentId, $newTitle] = $this->resolveCreationTarget($title, $config);

        if ($parentId <= 0 || $newTitle === '') {
            return 0;
        }

        $existingId = $this->findAllowedTagIdByTitle($newTitle, $parentId);

        if ($existingId > 0) {
            return $existingId;
        }

        return $this->createTag($newTitle, $parentId);
    }

    /**
     * Find an existing selectable tag by user input.
     *
     * @param   string  $input  Submitted text.
     *
     * @return  integer
     */
    protected function findAllowedTagIdByInput($input)
    {
        $tagId = $this->findAllowedTagIdByPath($input);

        if ($tagId > 0) {
            return $tagId;
        }

        return $this->findAllowedTagIdByTitle($input);
    }

    /**
     * Find an existing selectable tag by title.
     *
     * @param   string        $title     Tag title.
     * @param   integer|null  $parentId  Optional parent constraint.
     *
     * @return  integer
     */
    protected function findAllowedTagIdByTitle($title, ?int $parentId = null)
    {
        $needle = $this->normaliseLookupText($title);

        foreach ($this->getAllowedRows() as $row) {
            if ($parentId !== null && (int) $row->parent_id !== $parentId) {
                continue;
            }

            if ($this->normaliseLookupText((string) $row->title) === $needle) {
                return (int) $row->value;
            }
        }

        return 0;
    }

    /**
     * Find an existing selectable tag by path-like input.
     *
     * @param   string  $path  Submitted path text.
     *
     * @return  integer
     */
    protected function findAllowedTagIdByPath($path)
    {
        $needle = $this->normaliseLookupText($path);

        if ($needle === '' || !str_contains($needle, '/')) {
            return 0;
        }

        foreach ($this->getAllowedRows() as $row) {
            foreach ($this->getComparableLabelsForRow($row) as $label) {
                if ($this->normaliseLookupText($label) === $needle) {
                    return (int) $row->value;
                }
            }
        }

        return 0;
    }

    /**
     * Resolve the intended creation parent and final title from user input.
     *
     * @param   string  $input   Submitted text.
     * @param   array   $config  Restriction config.
     *
     * @return  array{0:int,1:string}
     */
    protected function resolveCreationTarget($input, array $config)
    {
        $segments = $this->splitPathInput($input);

        if (!$segments) {
            return [0, ''];
        }

        $newTitle = array_pop($segments);

        if (!$segments) {
            return [$this->getCreationParentId($config), $newTitle];
        }

        $parentId = $this->findCreationParentIdByPath(implode('/', $segments), $config);

        return [$parentId, $newTitle];
    }

    /**
     * Find a safe creation parent from a slash-separated path.
     *
     * @param   string  $path    Parent path.
     * @param   array   $config  Restriction config.
     *
     * @return  integer
     */
    protected function findCreationParentIdByPath($path, array $config)
    {
        $needle = $this->normaliseLookupText($path);

        if ($needle === '' || !$this->allowCustom()) {
            return 0;
        }

        $matches = [];

        foreach ($this->getCreatableParentRows($config) as $row) {
            foreach ($this->getComparableLabelsForRow($row) as $label) {
                if ($this->normaliseLookupText($label) === $needle) {
                    $matches[(int) $row->value] = true;
                    break;
                }
            }
        }

        return count($matches) === 1 ? (int) array_key_first($matches) : 0;
    }

    /**
     * Get rows that may safely act as parents for a newly created tag.
     *
     * @param   array  $config  Restriction config.
     *
     * @return  array
     */
    protected function getCreatableParentRows(array $config)
    {
        if (!$config['allow_tag_creation']) {
            return [];
        }

        if ($config['scope_mode'] !== 'include') {
            $rows = [];

            foreach ($this->loadCandidateRows() as $row) {
                if ($config['scope_mode'] === 'exclude' && !$this->isRowAllowed($row, [], $config)) {
                    continue;
                }

                $rows[(int) $row->value] = $row;
            }

            return $rows;
        }

        $roots = $this->getRootRows();

        if (!$roots) {
            return [];
        }

        $rows = $roots;

        if ($config['include_descendants']) {
            foreach ($this->loadCandidateRows() as $row) {
                foreach ($roots as $root) {
                    if ((int) $row->lft > (int) $root->lft && (int) $row->rgt < (int) $root->rgt) {
                        $rows[(int) $row->value] = $row;
                        break;
                    }
                }
            }
        }

        if ($config['allow_root_level_creation']) {
            foreach ($this->loadCandidateRows() as $row) {
                if ($this->isRootLevelTagRow($row)) {
                    $rows[(int) $row->value] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Resolve the parent for new tags.
     *
     * @param   array  $config  Restriction config.
     *
     * @return  integer
     */
    protected function getCreationParentId(array $config)
    {
        if ($config['scope_mode'] === 'include' && count($config['root_parent_ids']) === 1) {
            $rootParentId = $config['root_parent_ids'][0];

            return isset($this->getRootRows()[$rootParentId]) ? $rootParentId : 0;
        }

        if ($config['allow_root_level_creation']) {
            return $this->getTagRootId();
        }

        return 0;
    }

    /**
     * Determine whether the field is bound to native article tags.
     *
     * @return  boolean
     */
    protected function usesNativeTagsStorage()
    {
        return $this->getRestrictionConfig()['storage_mode'] === 'native_tags';
    }

    /**
     * Determine whether the current form is the article edit form.
     *
     * @return  boolean
     */
    protected function isArticleNativeTagsForm()
    {
        return $this->form && $this->form->getName() === 'com_content.article';
    }

    /**
     * Determine whether the current form data already contains a value for this field.
     *
     * This lets native-tags mode distinguish between an initial empty custom-field value and an
     * explicit empty submission where the editor cleared the selection.
     *
     * @param   string|null  $group  Field group.
     * @param   string       $name   Field name.
     *
     * @return  boolean
     */
    protected function hasSubmittedFieldValue($group, $name)
    {
        if (!$this->form) {
            return false;
        }

        $group = trim((string) $group);
        $name  = trim((string) $name);

        if ($name === '') {
            return false;
        }

        $key = $group !== '' ? $group . '.' . $name : $name;

        return $this->form->getData()->exists($key);
    }

    /**
     * Get the current native article tag ids constrained to this field's managed subset.
     *
     * @return  array
     */
    protected function getManagedNativeTagIds()
    {
        if (!$this->form) {
            return [];
        }

        $nativeTagIds = $this->normaliseStoredValues($this->form->getValue('tags', null, []));

        if (!$nativeTagIds) {
            return [];
        }

        if (!$this->hasActiveRestrictions()) {
            return $nativeTagIds;
        }

        $allowedLookup = array_fill_keys(array_keys($this->getAllowedRows()), true);

        return array_values(array_filter($nativeTagIds, static function ($tagId) use ($allowedLookup) {
            return isset($allowedLookup[$tagId]);
        }));
    }

    /**
     * Get the hidden Joomla root node id for the tag tree.
     *
     * @return  integer
     */
    protected function getTagRootId()
    {
        if ($this->tagRootId !== null) {
            return $this->tagRootId;
        }

        $tagTable = Factory::getApplication()->bootComponent('com_tags')->getMVCFactory()->createTable('Tag', 'Administrator');
        $this->tagRootId = (int) $tagTable->getRootId();

        return $this->tagRootId;
    }

    /**
     * Determine whether a row is a top-level Joomla tag.
     *
     * @param   object  $row  Tag row.
     *
     * @return  boolean
     */
    protected function isRootLevelTagRow($row)
    {
        return (int) $row->parent_id === $this->getTagRootId();
    }

    /**
     * Create a tag under the given parent.
     *
     * @param   string   $title     Tag title.
     * @param   integer  $parentId  Parent tag id.
     *
     * @return  integer
     */
    protected function createTag($title, $parentId)
    {
        $model = Factory::getApplication()->bootComponent('com_tags')
            ->getMVCFactory()->createModel('Tag', 'Administrator', ['ignore_request' => true]);

        $data = [
            'id'          => 0,
            'title'       => $title,
            'alias'       => $this->generateUniqueAlias($title),
            'parent_id'   => (int) $parentId,
            'published'   => 1,
            'description' => '',
            'language'    => '*',
            'access'      => 1,
        ];

        if (!$model->save($data)) {
            return 0;
        }

        $this->allowedRows = null;

        return (int) $model->getState('tag.id');
    }

    /**
     * Generate a globally unique tag alias.
     *
     * @param   string  $title  Tag title.
     *
     * @return  string
     */
    protected function generateUniqueAlias($title)
    {
        $db    = $this->getDatabase();
        $alias = ApplicationHelper::stringURLSafe($title, '*');

        if (trim(str_replace('-', '', $alias)) === '') {
            $alias = Factory::getDate()->format('Y-m-d-H-i-s');
        }

        $candidate = $alias;

        while (true) {
            $query = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__tags'))
                ->where($db->quoteName('alias') . ' = :alias')
                ->bind(':alias', $candidate);

            $db->setQuery($query, 0, 1);

            if (!$db->loadResult()) {
                return $candidate;
            }

            $candidate = StringHelper::increment($candidate, 'dash');
        }
    }

    /**
     * Extract a numeric tag id from mixed stored/native payloads.
     *
     * @param   mixed  $item  Candidate tag payload.
     *
     * @return  integer
     */
    protected function extractStoredTagId($item)
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
     * @param   string  $value  Raw attribute value.
     *
     * @return  boolean
     */
    protected function toBool($value)
    {
        return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'off'], true);
    }

    /**
     * Split a slash-separated user input path into cleaned segments.
     *
     * @param   string  $value  Raw input.
     *
     * @return  array
     */
    protected function splitPathInput($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $parts = array_map('trim', explode('/', $value));

        return array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));
    }

    /**
     * Normalize tag lookup text for case-insensitive matching.
     *
     * @param   string  $value  Raw input.
     *
     * @return  string
     */
    protected function normaliseLookupText($value)
    {
        $segments = $this->splitPathInput($value);

        if (!$segments) {
            return '';
        }

        return mb_strtolower(implode('/', $segments));
    }

    /**
     * Normalize configured tag ids to a unique integer list.
     *
     * @param   mixed  $value  Raw attribute value.
     *
     * @return  array
     */
    protected function normaliseConfiguredIds($value)
    {
        if ($value instanceof SimpleXMLElement) {
            $value = (string) $value;
        }

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

                if (is_array($decoded)) {
                    $value = $decoded;
                } else {
                    $value = explode(',', $value);
                }
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
}
