<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Reads the clergy assignment rows straight from Joomla's custom field tables
 * and loads articles through Joomla's own site Articles model, so access,
 * publishing and language rules stay Joomla's.
 */
final class Repository
{
    private static ?Registry $params = null;
    private static array $fields = [];
    private static ?array $rows = null;
    private static array $articles = [];

    public static function setParams(Registry $params): void
    {
        self::$params = $params;
    }

    /** Run a resolver without ever breaking the page. */
    public static function safe(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::add('Mitropolia sources: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
            return [];
        }
    }

    /** Parishes a clergy article serves, in the order entered. */
    public static function parishesOf(int $clergyId): array
    {
        $out = [];
        foreach (self::rows()[$clergyId] ?? [] as $row) {
            $out[] = ['parish' => $row['parish'], 'role_key' => $row['role']];
        }
        $parishes = self::articles(array_column($out, 'parish'));

        $result = [];
        foreach ($out as $r) {
            if (isset($parishes[$r['parish']])) {
                $result[] = (object) [
                    'parish'   => $parishes[$r['parish']],
                    'role'     => self::roleLabel($r['role_key']),
                    'role_key' => $r['role_key'],
                ];
            }
        }
        return $result;
    }

    /** Clergy serving a parish, in directory order (group, last name). */
    public static function clergyOf(int $parishId): array
    {
        $found = [];
        foreach (self::rows() as $clergyId => $rows) {
            foreach ($rows as $row) {
                if ($row['parish'] === $parishId) {
                    $found[] = ['clergy' => $clergyId, 'role_key' => $row['role']];
                }
            }
        }
        if (!$found) {
            return [];
        }

        $clergy   = self::articles(array_column($found, 'clergy'));
        $group    = self::valuesOf((string) self::param('group_field', 'clergy-group'), array_keys($clergy));
        $order    = self::optionOrder((string) self::param('group_field', 'clergy-group'));
        $lastName = self::valuesOf((string) self::param('lastname_field', 'last-name'), array_keys($clergy));

        $result = [];
        foreach ($found as $f) {
            if (!isset($clergy[$f['clergy']])) {
                continue;
            }
            $a = $clergy[$f['clergy']];
            $result[] = (object) [
                'clergy'   => $a,
                'role'     => self::roleLabel($f['role_key']),
                'role_key' => $f['role_key'],
                '_g'       => $order[$group[$a->id] ?? ''] ?? 99,
                '_n'       => mb_strtolower($lastName[$a->id] ?? $a->title),
            ];
        }
        usort($result, fn ($x, $y) => [$x->_g, $x->_n] <=> [$y->_g, $y->_n]);
        return $result;
    }

    /**
     * Events ('event') or itinerary stops ('itinerary') whose parish picker points at this parish.
     * Upcoming = date from today on, soonest first; otherwise newest first.
     * Only items in the page language or "All" are returned (Joomla's own language filter).
     */
    public static function atParish(string $kind, int $parishId, array $args = []): array
    {
        if ($parishId <= 0) {
            return [];
        }
        $parishField = self::field(self::param($kind . '_parish_field', $kind . '-parish'));
        $dateName    = $kind === 'event' ? self::param('event_start_field', 'event-start') : self::param('itinerary_date_field', 'itinerary-date');
        if (!$parishField) {
            return [];
        }

        $db  = self::db();
        $fid = (int) $parishField->id;
        $pid = (string) $parishId;
        $q   = $db->getQuery(true)
            ->select($db->quoteName('item_id'))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = :fid')
            ->where($db->quoteName('value') . ' = :pid')
            ->bind(':fid', $fid, ParameterType::INTEGER)
            ->bind(':pid', $pid);
        $ids = array_map('intval', $db->setQuery($q)->loadColumn());
        if (!$ids) {
            return [];
        }

        $items = self::articles($ids, true);
        $dates = self::valuesOf($dateName, array_keys($items));

        $upcoming = !empty($args['upcoming']);
        $today    = gmdate('Y-m-d');
        $rows = [];
        foreach ($items as $id => $item) {
            $d = (string) ($dates[$id] ?? '');
            if ($upcoming && ($d === '' || substr($d, 0, 10) < $today)) {
                continue;
            }
            $rows[] = [$d, $item];
        }
        usort($rows, fn ($a, $b) => $upcoming ? strcmp($a[0], $b[0]) : strcmp($b[0], $a[0]));

        $limit = (int) ($args['limit'] ?? 0);
        $out = array_column($rows, 1);
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /** The parish an event or itinerary stop points at (first parish field found). */
    public static function parishOf(int $articleId): ?object
    {
        if ($articleId <= 0) {
            return null;
        }
        foreach (['event', 'itinerary'] as $kind) {
            $field = self::param($kind . '_parish_field', $kind . '-parish');
            $value = self::valuesOf($field, [$articleId])[$articleId] ?? '';
            if ((int) $value > 0) {
                return self::articles([(int) $value])[(int) $value] ?? null;
            }
        }
        return null;
    }

    /** A plugin option, for the renderers (for example the CARTO map key). */
    public static function option(string $key, string $default = ''): string
    {
        return self::param($key, $default);
    }

    private static function param(string $key, string $default): string
    {
        return self::$params ? (string) self::$params->get($key, $default) : $default;
    }

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** Field row (id, fieldparams) by name, for com_content articles. */
    private static function field(string $name): ?object
    {
        if ($name === '') {
            return null;
        }
        if (!array_key_exists($name, self::$fields)) {
            $db = self::db();
            $q  = $db->getQuery(true)
                ->select($db->quoteName(['id', 'fieldparams']))
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('name') . ' = :name')
                ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('state') . ' = 1')
                ->bind(':name', $name);
            self::$fields[$name] = $db->setQuery($q)->loadObject() ?: null;
        }
        return self::$fields[$name];
    }

    /** All assignment rows: [clergyId => [['parish' => int, 'role' => string], ...]] */
    private static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }
        self::$rows = [];

        $sub    = self::field(self::param('assignments_field', 'clergy-parishes'));
        $parish = self::field(self::param('parish_subfield', 'parish'));
        $role   = self::field(self::param('role_subfield', 'role'));
        if (!$sub || !$parish) {
            return self::$rows;
        }

        $db = self::db();
        $id = (int) $sub->id;
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['item_id', 'value']))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = :fid')
            ->bind(':fid', $id, ParameterType::INTEGER);

        foreach ($db->setQuery($q)->loadObjectList() as $v) {
            $data = json_decode((string) $v->value, true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $row) {
                $p = (int) ($row['field' . $parish->id] ?? 0);
                if ($p > 0) {
                    self::$rows[(int) $v->item_id][] = [
                        'parish' => $p,
                        'role'   => $role ? (string) ($row['field' . $role->id] ?? '') : '',
                    ];
                }
            }
        }
        return self::$rows;
    }

    /** Values of a simple field for several articles: [articleId => value]. */
    private static function valuesOf(string $name, array $ids): array
    {
        $field = self::field($name);
        if (!$field || !$ids) {
            return [];
        }
        $db  = self::db();
        $fid = (int) $field->id;
        $q   = $db->getQuery(true)
            ->select($db->quoteName(['item_id', 'value']))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = :fid')
            ->whereIn($db->quoteName('item_id'), array_map('strval', $ids), ParameterType::STRING)
            ->bind(':fid', $fid, ParameterType::INTEGER);
        return array_map('strval', $db->setQuery($q)->loadAssocList('item_id', 'value'));
    }

    /** Option value => position, from a list field's options. */
    private static function optionOrder(string $name): array
    {
        $field = self::field($name);
        if (!$field) {
            return [];
        }
        $opts = (new Registry($field->fieldparams))->get('options', []);
        $i = 0;
        $out = [];
        foreach ((array) $opts as $o) {
            $out[(string) ((array) $o)['value']] = $i++;
        }
        return $out;
    }

    /** Role option label, translated when it is a language key. */
    private static function roleLabel(string $value): string
    {
        $field = self::field(self::param('role_subfield', 'role'));
        if ($field && $value !== '') {
            foreach ((array) (new Registry($field->fieldparams))->get('options', []) as $o) {
                $o = (array) $o;
                if ((string) ($o['value'] ?? '') === $value) {
                    return Text::_((string) $o['name']);
                }
            }
        }
        return $value;
    }

    /** Published, accessible articles by id: [id => item], via Joomla's site model. */
    private static function articles(array $ids, bool $currentLanguage = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($currentLanguage) {
            // Page-language items only: loaded separately, not cached with the language-free set.
            return self::load($ids, true);
        }
        $need = array_values(array_diff($ids, array_keys(self::$articles)));

        if ($need) {
            foreach (self::load($need, false) as $id => $item) {
                self::$articles[$id] = $item;
            }
            foreach ($need as $id) {
                self::$articles[$id] ??= null;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (!empty(self::$articles[$id])) {
                $out[$id] = self::$articles[$id];
            }
        }
        return $out;
    }

    /** Published, accessible articles by id through Joomla's site Articles model. */
    private static function load(array $ids, bool $currentLanguage): array
    {
        if (!$ids) {
            return [];
        }
        $app   = Factory::getApplication();
        $model = $app->bootComponent('com_content')->getMVCFactory()
            ->createModel('Articles', 'Site', ['ignore_request' => true]);
        $model->setState('params', ComponentHelper::getParams('com_content'));
        $model->setState('filter.article_id', $ids);
        $model->setState('filter.article_id.include', true);
        $model->setState('filter.published', 1);
        $model->setState('filter.access', true);
        $model->setState('filter.language', $currentLanguage && $app->isClient('site') && $app->getLanguageFilter());
        $model->setState('list.start', 0);
        $model->setState('list.limit', 0);
        $model->setState('list.ordering', 'a.id');
        $model->setState('list.direction', 'ASC');

        $out = [];
        foreach ($model->getItems() ?: [] as $item) {
            $out[(int) $item->id] = $item;
        }
        return $out;
    }
}
