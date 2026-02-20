<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.tagselect
 *
 * @copyright   (C) 2026 SuperSoft
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\TagField;

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

        if ($mode !== 'ajax' && $mode !== 'nested') {
            $mode = 'ajax';
        }

        $element['mode'] = $mode;

        // Never allow creating new tags from this field.
        $element['custom'] = 'deny';

        $multipleAttr = strtolower((string) ($element['multiple'] ?? 'true'));
        $isMultiple = !in_array($multipleAttr, ['0', 'false', 'no'], true);
        $element['multiple'] = $isMultiple ? 'true' : 'false';

        return parent::setup($element, $value, $group);
    }
}
