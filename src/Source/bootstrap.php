<?php
defined('_JEXEC') or die;

use Mitropolia\Plugin\System\MitropoliaSources\Source\SourceListener;

return [
    'events' => [
        'source.init' => [
            SourceListener::class => ['initSource', -20],
        ],
    ],
    // Mitropolia builder elements (documented YOOtheme extension point)
    'extend' => [
        \YOOtheme\Builder::class => function ($builder) {
            try {
                $builder->addTypePath(__DIR__ . '/elements/*/element.json');
            } catch (\Throwable $e) {
                // element unavailable, builder keeps working
            }
        },
    ],
];
