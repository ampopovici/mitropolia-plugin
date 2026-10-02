<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source\Type;

defined('_JEXEC') or die;

use Mitropolia\Plugin\System\MitropoliaSources\Source\Repository;

/** Adds two lists to every Article in YOOtheme's dynamic content. */
class ArticleExtension
{
    public static function config(): array
    {
        return [
            'fields' => [
                'mitropolia_parishes' => [
                    'type' => ['listOf' => 'MitropoliaParishAssignment'],
                    'metadata' => ['label' => 'Mitropolia: Parishes served', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::parishes'],
                ],
                'mitropolia_parish' => [
                    'type' => 'Article',
                    'metadata' => ['label' => 'Mitropolia: Parish (of this event or visit)', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::parish'],
                ],
                'mitropolia_events' => [
                    'type' => ['listOf' => 'Article'],
                    'args' => ['upcoming' => ['type' => 'Boolean'], 'limit' => ['type' => 'Int']],
                    'metadata' => [
                        'label' => 'Mitropolia: Events at this parish',
                        'group' => 'Mitropolia',
                        'fields' => [
                            'upcoming' => ['label' => 'Upcoming only', 'type' => 'checkbox', 'text' => 'Only events from today on', 'default' => true],
                            'limit' => ['label' => 'Limit', 'type' => 'number', 'default' => 10],
                        ],
                    ],
                    'extensions' => ['call' => __CLASS__ . '::events'],
                ],
                'mitropolia_visits' => [
                    'type' => ['listOf' => 'Article'],
                    'args' => ['upcoming' => ['type' => 'Boolean'], 'limit' => ['type' => 'Int']],
                    'metadata' => [
                        'label' => 'Mitropolia: Pastoral visits at this parish',
                        'group' => 'Mitropolia',
                        'fields' => [
                            'upcoming' => ['label' => 'Upcoming only', 'type' => 'checkbox', 'text' => 'Only visits from today on', 'default' => false],
                            'limit' => ['label' => 'Limit', 'type' => 'number', 'default' => 10],
                        ],
                    ],
                    'extensions' => ['call' => __CLASS__ . '::visits'],
                ],
                'mitropolia_clergy' => [
                    'type' => ['listOf' => 'MitropoliaParishClergy'],
                    'metadata' => ['label' => 'Mitropolia: Clergy of this parish', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::clergy'],
                ],
            ],
        ];
    }

    public static function parishes($article)
    {
        return Repository::safe(fn () => Repository::parishesOf((int) ($article->id ?? 0)));
    }

    public static function parish($article)
    {
        $list = Repository::safe(fn () => array_filter([Repository::parishOf((int) ($article->id ?? 0))]));
        return $list[0] ?? null;
    }

    public static function events($article, $args = [])
    {
        return Repository::safe(fn () => Repository::atParish('event', (int) ($article->id ?? 0), $args));
    }

    public static function visits($article, $args = [])
    {
        return Repository::safe(fn () => Repository::atParish('itinerary', (int) ($article->id ?? 0), $args));
    }

    public static function clergy($article)
    {
        return Repository::safe(fn () => Repository::clergyOf((int) ($article->id ?? 0)));
    }
}
