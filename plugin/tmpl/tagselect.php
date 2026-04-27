<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fields.tagselect
 *
 * @copyright   (C) 2026 SuperSoft
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

$renderTags = [];
$frontendOutput = 'tag_links';

if (isset($field->fieldparams) && is_object($field->fieldparams) && method_exists($field->fieldparams, 'get')) {
    $frontendOutput = (string) $field->fieldparams->get('frontend_output', 'tag_links');
}

if (!in_array($frontendOutput, ['tag_links', 'plain_text'], true)) {
    $frontendOutput = 'tag_links';
}

if (isset($field->tagselectRenderTags) && is_array($field->tagselectRenderTags)) {
    $renderTags = array_values(array_filter($field->tagselectRenderTags, static function ($tag) {
        return is_object($tag) && trim((string) ($tag->title ?? '')) !== '';
    }));
}

if ($renderTags) {
    if ($frontendOutput === 'plain_text') {
        $titles = array_map(static function ($tag) {
            return trim((string) ($tag->display ?? $tag->title ?? ''));
        }, $renderTags);

        $titles = array_values(array_filter($titles, 'strlen'));

        if ($titles) {
            echo htmlspecialchars(implode(', ', $titles), ENT_QUOTES, 'UTF-8');

            return;
        }
    }

    $fragments = [];

    foreach ($renderTags as $index => $tag) {
        $tagParams  = new Registry($tag->params ?? '');
        $linkClass  = trim((string) $tagParams->get('tag_link_class', 'btn btn-sm btn-info'));
        $tagId      = (int) ($tag->tag_id ?? $tag->id ?? 0);
        $slug       = (string) ($tag->slug ?? '');
        $title      = trim((string) ($tag->title ?? ''));

        if ($tagId <= 0 || $title === '') {
            continue;
        }

        if ($slug === '') {
            $slug = $tagId . ':' . (string) ($tag->alias ?? '');
        }

        $fragments[] = sprintf(
            '<a href="%s"%s>%s</a>',
            htmlspecialchars(Route::_('index.php?option=com_tags&view=tag&id=' . $slug), ENT_QUOTES, 'UTF-8'),
            $linkClass !== '' ? ' class="' . htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') . '"' : '',
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        );
    }

    if ($fragments) {
        echo implode(' ', $fragments);

        return;
    }
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
