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
                'mitropolia_date' => [
                    'type' => 'String',
                    'metadata' => ['label' => 'Mitropolia: Date (page language)', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::date'],
                ],
                'mitropolia_reading' => [
                    'type' => 'String',
                    'metadata' => ['label' => 'Mitropolia: Reading time', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::reading'],
                ],
                'mitropolia_lead' => [
                    'type' => 'String',
                    'metadata' => ['label' => 'Mitropolia: Lead image', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::lead'],
                ],
                'mitropolia_lead_alt' => [
                    'type' => 'String',
                    'metadata' => ['label' => 'Mitropolia: Lead image alt', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::leadAlt'],
                ],
                'mitropolia_lead_caption' => [
                    'type' => 'String',
                    'metadata' => ['label' => 'Mitropolia: Lead image caption', 'group' => 'Mitropolia'],
                    'extensions' => ['call' => __CLASS__ . '::leadCaption'],
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

    public static function date($article)
    {
        return self::str(fn () => \Mitropolia\Plugin\System\MitropoliaSources\Source\News::headDate($article));
    }

    public static function reading($article)
    {
        return self::str(fn () => \Mitropolia\Plugin\System\MitropoliaSources\Source\News::headReading($article));
    }

    public static function lead($article)
    {
        return ltrim(self::str(fn () => \Mitropolia\Plugin\System\MitropoliaSources\Source\News::headLead($article)['src'] ?? ''), '/');
    }

    public static function leadAlt($article)
    {
        return self::str(fn () => \Mitropolia\Plugin\System\MitropoliaSources\Source\News::headLead($article)['alt'] ?? '');
    }

    public static function leadCaption($article)
    {
        return self::str(fn () => \Mitropolia\Plugin\System\MitropoliaSources\Source\News::headLead($article)['cap'] ?? '');
    }

    private static function str(callable $fn): string
    {
        try {
            return (string) $fn();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
