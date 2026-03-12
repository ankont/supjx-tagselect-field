<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.tagselect
 *
 * @copyright   (C) 2026 SuperSoft
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

if (empty($field->value)) {
    return;
}

$values = $field->value;

if (!is_array($values)) {
    $values = explode(',', (string) $values);
}

$values = array_values(array_filter(array_map('trim', $values), 'strlen'));

if (!$values) {
    return;
}

echo htmlspecialchars(implode(', ', $values), ENT_QUOTES, 'UTF-8');
