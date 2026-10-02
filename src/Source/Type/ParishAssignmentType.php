<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source\Type;

defined('_JEXEC') or die;

class ParishAssignmentType
{
    public static function config(): array
    {
        return [
            'fields' => [
                'parish' => [
                    'type' => 'Article',
                    'metadata' => ['label' => 'Parish'],
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
            'metadata' => ['type' => true, 'label' => 'Parish served'],
        ];
    }
}
