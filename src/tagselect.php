<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.tagselect
 *
 * @copyright   (C) 2026 SuperSoft
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin;

FormHelper::addFieldPath(__DIR__ . '/fields');

/**
 * Fields Tag Select Plugin
 */
class PlgFieldsTagselect extends FieldsPlugin
{
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

        if (isset($field->fieldparams) && is_object($field->fieldparams) && method_exists($field->fieldparams, 'get')) {
            $configuredMode = strtolower((string) $field->fieldparams->get('mode', 'ajax'));

            if ($configuredMode === 'nested') {
                $mode = 'nested';
            }

            $multiple = (int) $field->fieldparams->get('multiple', 1) === 1;
        }

        $fieldNode->setAttribute('mode', $mode);
        $fieldNode->setAttribute('custom', 'deny');
        $fieldNode->setAttribute('multiple', $multiple ? 'true' : 'false');

        return $fieldNode;
    }
}