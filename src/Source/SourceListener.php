<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Mitropolia\Plugin\System\MitropoliaSources\Source\Type\ArticleExtension;
use Mitropolia\Plugin\System\MitropoliaSources\Source\Type\ParishAssignmentType;
use Mitropolia\Plugin\System\MitropoliaSources\Source\Type\ParishClergyType;

class SourceListener
{
    /**
     * Static, as YOOtheme 5 expects: its EventLoader calls [Class, 'method']
     * directly unless the method name is prefixed with '@'.
     * A failure here is logged and skipped so the builder keeps working.
     *
     * @param \YOOtheme\Builder\Source $source
     */
    public static function initSource($source): void
    {
        try {
            $source->objectType('MitropoliaParishAssignment', ParishAssignmentType::config());
            $source->objectType('MitropoliaParishClergy', ParishClergyType::config());
            $source->objectType('Article', ArticleExtension::config());
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('Mitropolia sources not registered: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'mitropolia');
        }
    }
}
