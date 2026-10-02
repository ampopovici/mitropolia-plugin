<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source\Type;

defined('_JEXEC') or die;

class ParishClergyType
{
    public static function config(): array
    {
        return [
            'fields' => [
                'clergy' => [
                    'type' => 'Article',
                    'metadata' => ['label' => 'Clergy member'],
                ],
                'role' => [
                    'type' => 'String',
                    'metadata' => ['label' => 'Role (page language)'],
                ],
                'role_key' => [
                    'type' => 'String',
                    'metadata' => ['label' => 'Role key'],
                ],
            ],
            'metadata' => ['type' => true, 'label' => 'Parish clergy'],
        ];
    }
}
