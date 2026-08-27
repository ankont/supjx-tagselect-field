<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.tagselect
 *
 * @copyright   (C) 2026 SuperSoft
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\RadioField;

/**
 * Field type selector that only offers native storage in supported Joomla contexts.
 */
class JFormFieldTagselectfieldtype extends RadioField
{
    /**
     * @var    string
     */
    protected $type = 'Tagselectfieldtype';

    /**
     * Force unsupported contexts to use normal custom-field storage.
     *
     * @return  string
     */
    protected function getInput()
    {
        if (!$this->isNativeContextSupported()) {
            $this->value = 'independent';
        }

        return parent::getInput();
    }

    /**
     * Disable the native handler option outside article and category fields.
     *
     * @return  object[]
     */
    protected function getOptions()
    {
        $options = parent::getOptions();

        if ($this->isNativeContextSupported()) {
            return $options;
        }

        foreach ($options as $option) {
            if ((string) $option->value === 'native_article_tags') {
                $option->disable = true;
            }
        }

        return $options;
    }

    /**
     * Check the custom-field definition context.
     *
     * @return  boolean
     */
    protected function isNativeContextSupported()
    {
        $context = $this->form ? trim((string) $this->form->getValue('context')) : '';

        if ($context === '') {
            $context = Factory::getApplication()->getInput()->getString('context', '');
        }

        return in_array($context, ['com_content.article', 'com_content.categories'], true);
    }
}
