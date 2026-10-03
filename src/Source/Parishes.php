<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Parish directory and parish page, rendered for the "Parish directory" and "Parish page" builder elements.
 * Data: published articles in the Parishes (ALL) category and their Mitropolia fields.
 * One article holds all three languages; the page language picks the name, feasts, schedule and texts.
 * Labels come from the plugin language files (MIT_PAR_*, MIT_O_*), so each language page is translated.
 */
final class Parishes
{
    private const ROOT_ALIAS = 'parishes';
    private const DIOCESES = ['archdiocese' => 'MIT_DIR_DIOCESE_USA', 'canada' => 'MIT_DIR_DIOCESE_CANADA', 'south-america' => 'MIT_DIR_DIOCESE_SA'];
    private const TYPE_ORDER = ['cathedral' => 0, 'monastery' => 1, 'parish' => 2, 'mission' => 3, 'chapel' => 4];
    private const STATES = [
        'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut','DE'=>'Delaware','DC'=>'District of Columbia','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma','OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming',
        'AB'=>'Alberta','BC'=>'British Columbia','MB'=>'Manitoba','NB'=>'New Brunswick','NL'=>'Newfoundland and Labrador','NS'=>'Nova Scotia','ON'=>'Ontario','PE'=>'Prince Edward Island','QC'=>'Quebec','SK'=>'Saskatchewan',
    ];
    private const FIELDS = ['parish-name-ro', 'parish-name-en', 'parish-name-es', 'parish-type', 'parish-diocese', 'parish-deanery',
        'parish-street', 'parish-city', 'parish-state', 'parish-postal', 'parish-country', 'parish-location', 'parish-mailing',
        'parish-phone', 'parish-email', 'parish-website', 'parish-facebook', 'parish-about-ro', 'parish-about-en', 'parish-about-es',
        'parish-feasts', 'parish-services', 'parish-lay-leaders',
        'feast-ro', 'feast-en', 'feast-es', 'sched-day-ro', 'sched-day-en', 'sched-day-es', 'sched-service-ro', 'sched-service-en',
        'sched-service-es', 'sched-time', 'lay-name', 'lay-roles', 'lay-phone', 'lay-email'];

    /* ------------------------------------------------------------------ data */

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private static function lang(): string
    {
        return strtolower(substr(Factory::getApplication()->getLanguage()->getTag(), 0, 2));
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function fold(string $s): string
    {
        return Directory::fold($s);
    }

    /** Field name => id, for the fields we read. */
    private static function fieldIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $db = self::db();
            $q  = $db->getQuery(true)
                ->select($db->quoteName(['id', 'name']))
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('state') . ' = 1')
                ->whereIn($db->quoteName('name'), self::FIELDS, ParameterType::STRING);
            $ids = array_flip($db->setQuery($q)->loadAssocList('id', 'name'));
        }
        return $ids;
    }

    /** [itemId][fieldName] => raw value */
    private static function values(array $itemIds): array
    {
        $ids = self::fieldIds();
        if (!$ids || !$itemIds) {
            return [];
        }
        $byId = array_flip($ids);
        $db = self::db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['field_id', 'item_id', 'value']))
            ->from($db->quoteName('#__fields_values'))
            ->whereIn($db->quoteName('field_id'), array_map('intval', array_values($ids)))
            ->whereIn($db->quoteName('item_id'), array_map('strval', $itemIds), ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $v) {
            $name = $byId[(int) $v->field_id];
            // list fields with several values store one row per value
            if (isset($out[(int) $v->item_id][$name])) {
                $out[(int) $v->item_id][$name] = (array) $out[(int) $v->item_id][$name];
                $out[(int) $v->item_id][$name][] = $v->value;
            } else {
                $out[(int) $v->item_id][$name] = $v->value;
            }
        }
        return $out;
    }

    /** Rows of a repeatable (subform) field as [[subfieldName => value]]. */
    private static function subrows($raw): array
    {
        $data = json_decode(is_array($raw) ? (string) reset($raw) : (string) $raw, true);
        if (!is_array($data)) {
            return [];
        }
        $byId = array_flip(self::fieldIds());
        $rows = [];
        foreach ($data as $row) {
            $r = [];
            foreach ((array) $row as $k => $v) {
                $id = (int) preg_replace('/\D/', '', (string) $k);
                if (isset($byId[$id])) {
                    $r[$byId[$id]] = $v;
                }
            }
            if (array_filter($r, fn ($x) => is_array($x) ? $x : trim((string) $x) !== '')) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    private static function str($v): string
    {
        return trim(is_array($v) ? (string) reset($v) : (string) $v);
    }

    /** Text for the page language, falling back to Romanian, then English. */
    private static function pick(array $v, string $base): string
    {
        foreach ([self::lang(), 'ro', 'en', 'es'] as $l) {
            $t = self::str($v[$base . '-' . $l] ?? '');
            if ($t !== '') {
                return $t;
            }
        }
        return '';
    }

    private static function point(string $raw): ?array
    {
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)/', $raw, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
            if (abs($lat) <= 90 && abs($lng) <= 180 && ($lat || $lng)) {
                return [$lat, $lng];
            }
        }
        return null;
    }

    /** Parishes (ALL) category id. */
    private static function categoryId(): int
    {
        static $id = null;
        if ($id === null) {
            $db = self::db();
            $q  = $db->getQuery(true)->select('id')->from($db->quoteName('#__categories'))
                ->where($db->quoteName('alias') . ' = ' . $db->quote(self::ROOT_ALIAS))
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                ->where($db->quoteName('published') . ' = 1');
            $id = (int) $db->setQuery($q)->loadResult();
        }
        return $id;
    }

    /** All published parishes, sorted by type then name, with what the cards and map need. */
    private static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cat = self::categoryId();
        if (!$cat) {
            return $cache = [];
        }
        $app   = Factory::getApplication();
        $model = $app->bootComponent('com_content')->getMVCFactory()->createModel('Articles', 'Site', ['ignore_request' => true]);
        $model->setState('params', ComponentHelper::getParams('com_content'));
        $model->setState('filter.category_id', [$cat]);
        $model->setState('filter.subcategories', true);
        $model->setState('filter.published', 1);
        $model->setState('filter.access', true);
        $model->setState('filter.language', false);
        $model->setState('list.start', 0);
        $model->setState('list.limit', 0);
        $model->setState('list.ordering', 'a.title');
        $model->setState('list.direction', 'ASC');
        $items = $model->getItems() ?: [];
        $vals  = self::values(array_map(fn ($i) => (int) $i->id, $items));

        $out = [];
        foreach ($items as $item) {
            $id = (int) $item->id;
            $out[] = self::row($item, $vals[$id] ?? []);
        }
        usort($out, fn ($a, $b) => [self::TYPE_ORDER[$a['type']] ?? 9, self::fold($a['name'])] <=> [self::TYPE_ORDER[$b['type']] ?? 9, self::fold($b['name'])]);
        return $cache = $out;
    }

    private static function row(object $item, array $v): array
    {
        $id     = (int) $item->id;
        $type   = self::str($v['parish-type'] ?? '') ?: 'parish';
        $state  = self::str($v['parish-state'] ?? '');
        $images = json_decode((string) ($item->images ?? ''), true) ?: [];
        $img    = (string) ($images['image_intro'] ?? '') ?: (string) ($images['image_fulltext'] ?? '');
        return [
            'id'      => $id,
            'item'    => $item,
            'v'       => $v,
            'name'    => self::pick($v, 'parish-name') ?: (string) $item->title,
            'type'    => isset(self::TYPE_ORDER[$type]) ? $type : 'parish',
            'diocese' => self::str($v['parish-diocese'] ?? ''),
            'deanery' => self::str($v['parish-deanery'] ?? ''),
            'street'  => self::str($v['parish-street'] ?? ''),
            'city'    => self::str($v['parish-city'] ?? ''),
            'state'   => $state,
            'stateName' => self::STATES[strtoupper($state)] ?? $state,
            'postal'  => self::str($v['parish-postal'] ?? ''),
            'country' => self::str($v['parish-country'] ?? ''),
            'point'   => self::point(self::str($v['parish-location'] ?? '')),
            'image'   => $img !== '' ? explode('#', $img, 2)[0] : '',
            'url'     => Route::_(RouteHelper::getArticleRoute($item->slug ?? $id, (int) $item->catid, $item->language)),
        ];
    }

    /** Heading worded for the type when a text exists (e.g. MIT_PAR_ABOUT_MONASTERY), otherwise the parish wording. */
    private static function byType(string $key, string $type): string
    {
        $k = $key . '_' . strtoupper($type);
        return Factory::getApplication()->getLanguage()->hasKey($k) ? Text::_($k) : Text::_($key);
    }

    private static function typeLabel(string $type): string
    {
        return Text::_('MIT_O_PARISH_TYPE_' . strtoupper($type));
    }

    private static function cityLine(array $r): string
    {
        return implode(', ', array_filter([$r['city'], $r['state']]));
    }

    /** Directions button, or nothing when there is neither an address nor a map point. */
    private static function dirBtn(array $r, string $cls): string
    {
        $u = self::directions($r);
        return $u === '' ? '' : '<a class="mpx-btn mpx-red' . $cls . '" href="' . self::e($u) . '" target="_blank" rel="noopener">' . self::I_NAV . ' ' . self::e(Text::_('MIT_PAR_DIRECTIONS')) . '</a>';
    }

    private static function directions(array $r): string
    {
        // Navigation goes to the street address (what a driver needs); the map coordinates are only a fallback
        $addr = implode(', ', array_filter([$r['street'], $r['city'], trim($r['state'] . ' ' . $r['postal']), $r['country']]));
        $dest = $r['street'] !== '' || !$r['point'] ? $addr : $r['point'][0] . ',' . $r['point'][1];
        if ($dest === '') {
            return '';
        }
        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($dest);
    }

    /* ------------------------------------------------------------------ shared pieces */

    private const I_PIN = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
    private const I_NAV = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 11l18-8-8 18-2-8-8-2z"/></svg>';
    private const I_CHURCH = '<svg width="46" height="46" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M24 4v8M20.5 7.5h7"/><path d="M17 20c0-5 7-8 7-8s7 3 7 8"/><path d="M14 44V24h20v20"/><path d="M6 44V30l8-5M42 44V30l-8-5"/><path d="M21 44v-8a3 3 0 0 1 6 0v8"/><path d="M4 44h40"/></svg>';
    private const I_MAIL = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>';
    private const I_PHONE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>';
    private const I_GLOBE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>';
    private const I_FB = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8z"/></svg>';
    private const I_SEARCH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>';

    /** Breadcrumb from Joomla's pathway, starting at this language's home page; the last part can be replaced. */
    private static function crumbs(?string $last = null): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $parts = [];
        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        if ($home) {
            $parts[] = '<a href="' . $e(Route::_('index.php?Itemid=' . (int) $home->id)) . '">' . $e((string) $home->title) . '</a>';
        }
        $path = $app->getPathway()->getPathway();
        $n = count($path);
        foreach ($path as $i => $p) {
            $name = trim(strip_tags((string) $p->name));
            if ($i === $n - 1 && $last !== null) {
                $name = $last;
            }
            if ($name === '') {
                continue;
            }
            $link = (string) ($p->link ?? '');
            $parts[] = ($i < $n - 1 && $link !== '' && $link !== '#')
                ? '<a href="' . $e(Route::_($link)) . '">' . $e($name) . '</a>'
                : '<span' . ($i === $n - 1 ? ' aria-current="page"' : '') . '>' . $e($name) . '</span>';
        }
        return '<nav class="mpx-crumbs" aria-label="' . $e(Text::_('MIT_DIR_BREADCRUMB')) . '">'
            . implode('<span aria-hidden="true">›</span>', $parts) . '</nav>';
    }

    /** Map tiles: CARTO Voyager when a key is set in the plugin options, otherwise OpenStreetMap. */
    public static function tiles(): array
    {
        $key = trim(Repository::option('carto_key', ''));
        if ($key !== '') {
            return ['https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png?key=' . rawurlencode($key),
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'];
        }
        return ['https://tile.openstreetmap.org/{z}/{x}/{y}.png', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'];
    }

    /* ------------------------------------------------------------------ directory */

    public static function directory(array $props): string
    {
        try {
            return self::directoryHtml(self::all(), $props);
        } catch (\Throwable $e) {
            Log::add('Parish directory: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function directoryText(array $props): string
    {
        try {
            $out = [];
            foreach (self::all() as $r) {
                $out[] = $r['name'] . ' – ' . self::cityLine($r);
            }
            return '<p>' . implode('<br>', array_map([self::class, 'e'], $out)) . '</p>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function card(array $r, int $i): string
    {
        $e = [self::class, 'e'];
        $ph = $r['image'] !== ''
            ? '<img src="' . $e(Uri::root(true) . '/' . ltrim($r['image'], '/')) . '" alt="" loading="lazy">'
            : self::I_CHURCH;
        $q = self::fold($r['name'] . ' ' . $r['city'] . ' ' . $r['stateName'] . ' ' . $r['state']);
        return '<article class="mpd-card" data-i="' . $i . '" data-d="' . $e($r['diocese']) . '" data-t="' . $e($r['type']) . '" data-s="' . $e($r['state']) . '" data-q="' . $e($q) . '">'
            . '<a class="mpd-ph" href="' . $e($r['url']) . '" aria-label="' . $e($r['name']) . '" tabindex="-1">' . $ph . '</a>'
            . '<div class="mpd-bd"><span class="mpx-type mpx-' . $e($r['type']) . '">' . $e(self::typeLabel($r['type'])) . '</span>'
            . '<h3><a href="' . $e($r['url']) . '">' . $e($r['name']) . '</a></h3>'
            . (self::cityLine($r) !== '' ? '<span class="mpd-loc">' . self::I_PIN . $e(self::cityLine($r)) . '</span>' : '')
            . '<div class="mpd-acts">' . self::dirBtn($r, '')
            . (($web = self::str($r['v']['parish-website'] ?? '')) !== '' ? '<a class="mpx-btn mpx-line" href="' . $e($web) . '" target="_blank" rel="noopener">' . self::I_GLOBE . ' ' . $e(Text::_('MIT_PAR_WEBSITE')) . '</a>' : '') . '</div>'
            . '</div></article>';
    }

    private static function directoryHtml(array $rows, array $props): string
    {
        $e = [self::class, 'e'];
        $uid = 'mpd' . substr(md5(json_encode($props) . count($rows)), 0, 6);

        // Cards grouped by diocese, in the clergy directory's order
        $body = '';
        $pts  = [];
        foreach (array_keys(self::DIOCESES) + [99 => ''] as $d) {
            $group = array_values(array_filter($rows, fn ($r) => $d === '' ? !isset(self::DIOCESES[$r['diocese']]) : $r['diocese'] === $d));
            if (!$group) {
                continue;
            }
            $n = count($group);
            $title = $d !== '' ? Text::_(self::DIOCESES[$d]) : Text::_('MIT_DIR_DIOCESE_ALL');
            $body .= '<h2 class="mpd-h" data-h="' . $e($d) . '">' . $e($title) . ' <small>' . $e(sprintf(Text::_($n === 1 ? 'MIT_PAR_COUNT_ONE' : 'MIT_PAR_COUNT'), $n)) . '</small></h2>';
            foreach ($group as $r) {
                $i = count($pts);
                $pts[] = $r['point'] ? [$r['point'][0], $r['point'][1], $r['type'], $r['name'], self::cityLine($r), $r['url']] : null;
                $body .= self::card($r, $i);
            }
        }

        // State or province list, grouped like the cards
        $states = '';
        foreach (array_keys(self::DIOCESES) as $d) {
            $list = [];
            foreach ($rows as $r) {
                if ($r['diocese'] === $d && $r['state'] !== '') {
                    $list[$r['state']] = $r['stateName'];
                }
            }
            if ($list) {
                asort($list);
                $states .= '<optgroup label="' . $e(Text::_(self::DIOCESES[$d])) . '">';
                foreach ($list as $k => $name) {
                    $states .= '<option value="' . $e($k) . '">' . $e($name) . '</option>';
                }
                $states .= '</optgroup>';
            }
        }

        $present = array_unique(array_column($rows, 'type'));
        $chips = '<button type="button" class="mpx-chip on" data-ty="">' . $e(Text::_('MIT_PAR_ALL')) . '</button>';
        foreach (array_keys(self::TYPE_ORDER) as $t) {
            if (in_array($t, $present, true)) {
                $chips .= '<button type="button" class="mpx-chip" data-ty="' . $t . '">' . $e(Text::_('MIT_PAR_CHIP_' . strtoupper($t))) . '</button>';
            }
        }

        $dio = '<select class="mpx-sel" data-f="d" aria-label="' . $e(Text::_('MIT_DIR_DIOCESE')) . '"><option value="">' . $e(Text::_('MIT_DIR_DIOCESE_ALL')) . '</option>';
        foreach (self::DIOCESES as $k => $key) {
            $dio .= '<option value="' . $k . '">' . $e(Text::_($key)) . '</option>';
        }
        $dio .= '</select>';

        $title = trim((string) ($props['title'] ?? ''));
        if ($title === '') {
            $item = Factory::getApplication()->getMenu()->getActive();
            $title = $item && trim((string) $item->getParams()->get('page_heading', '')) !== '' ? trim((string) $item->getParams()->get('page_heading')) : Text::_('MIT_PAR_TITLE');
        }
        $intro = trim((string) ($props['intro'] ?? '')) ?: Text::_('MIT_PAR_INTRO');

        [$tileUrl, $attr] = self::tiles();
        $cfg = json_encode(['pts' => $pts, 'tiles' => $tileUrl, 'attr' => $attr, 'page' => Text::_('MIT_PAR_PAGE'),
            'types' => array_combine(array_keys(self::TYPE_ORDER), array_map([self::class, 'typeLabel'], array_keys(self::TYPE_ORDER)))], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        $controls = '<div class="mpd-controls"><label class="mpd-search">' . self::I_SEARCH . '<input type="search" data-f="q" placeholder="' . $e(Text::_('MIT_PAR_SEARCH')) . '" aria-label="' . $e(Text::_('MIT_PAR_SEARCH_LABEL')) . '"></label>'
            . $dio
            . '<select class="mpx-sel" data-f="s" aria-label="' . $e(Text::_('MIT_PAR_STATE')) . '"><option value="">' . $e(Text::_('MIT_PAR_STATE')) . '</option>' . $states . '</select>'
            . '<button type="button" class="mpx-btn mpx-line mpd-near">' . self::I_NAV . ' ' . $e(Text::_('MIT_PAR_NEAR')) . '</button></div>';
        $chipRow = '<div class="mpd-chiprow"><div class="mpd-chips">' . $chips . '</div>'
            . '<div class="mpd-legend"><span><i class="mpx-dot-c"></i>' . $e(self::typeLabel('cathedral')) . '</span><span><i class="mpx-dot-m"></i>' . $e(self::typeLabel('monastery')) . '</span><span><i class="mpx-dot-p"></i>' . $e(Text::_('MIT_PAR_LEGEND_OTHER')) . '</span></div></div>';
        // parts, for pages whose header is built with YOOtheme elements (the script then works on the whole page)
        $part = (string) ($props['part'] ?? '');
        if ($part === 'search') {
            return '<div class="mpd mpd-tools" data-mpd-part>' . $controls . '</div>' . self::assets();
        }
        if ($part === 'chips') {
            return '<div class="mpd mpd-tools" data-mpd-part>' . $chipRow . '</div>' . self::assets();
        }
        if ($part === 'body') {
            return '<div class="mpd' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '" data-mpd-part>'
                . '<div class="mpd-map"><div class="mpd-mapin" role="region" aria-label="' . $e(Text::_('MIT_PAR_MAP')) . '"></div></div>'
                . '<p class="mpd-count" data-c1="' . $e(Text::_('MIT_PAR_COUNT_ONE')) . '" data-cn="' . $e(Text::_('MIT_PAR_COUNT')) . '" data-cm="' . $e(Text::_('MIT_PAR_COUNT_MANY')) . '"></p>'
                . '<div class="mpd-grid">' . $body . '</div>'
                . '<p class="mpd-none" hidden>' . $e(Text::_('MIT_PAR_NONE')) . '</p>'
                . '<script type="application/json" class="mpd-cfg">' . $cfg . '</script>'
                . '</div>' . self::assets();
        }

        return '<div class="mpd' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '"' . (!empty($props['id']) ? ' id="' . $e((string) $props['id']) . '"' : '') . ' data-mpd="' . $uid . '">'
            . '<div class="mpd-hero"><div class="mpd-hero-in">' . self::crumbs()
            . '<div class="mpd-hero-row"><div class="mpd-hero-text"><h1 class="mpx-h1">' . $e($title) . '</h1><p class="mpd-intro">' . $e($intro) . '</p></div>'
            . '<div class="mpd-controls"><label class="mpd-search">' . self::I_SEARCH . '<input type="search" data-f="q" placeholder="' . $e(Text::_('MIT_PAR_SEARCH')) . '" aria-label="' . $e(Text::_('MIT_PAR_SEARCH_LABEL')) . '"></label>'
            . $dio
            . '<select class="mpx-sel" data-f="s" aria-label="' . $e(Text::_('MIT_PAR_STATE')) . '"><option value="">' . $e(Text::_('MIT_PAR_STATE')) . '</option>' . $states . '</select>'
            . '<button type="button" class="mpx-btn mpx-line mpd-near">' . self::I_NAV . ' ' . $e(Text::_('MIT_PAR_NEAR')) . '</button></div></div>'
            . '<div class="mpd-chiprow"><div class="mpd-chips">' . $chips . '</div>'
            . '<div class="mpd-legend"><span><i class="mpx-dot-c"></i>' . $e(self::typeLabel('cathedral')) . '</span><span><i class="mpx-dot-m"></i>' . $e(self::typeLabel('monastery')) . '</span><span><i class="mpx-dot-p"></i>' . $e(Text::_('MIT_PAR_LEGEND_OTHER')) . '</span></div></div>'
            . '</div></div>'
            . '<div class="mpd-map"><div class="mpd-mapin" role="region" aria-label="' . $e(Text::_('MIT_PAR_MAP')) . '"></div></div>'
            . '<p class="mpd-count" data-c1="' . $e(Text::_('MIT_PAR_COUNT_ONE')) . '" data-cn="' . $e(Text::_('MIT_PAR_COUNT')) . '" data-cm="' . $e(Text::_('MIT_PAR_COUNT_MANY')) . '"></p>'
            . '<div class="mpd-grid">' . $body . '</div>'
            . '<p class="mpd-none" hidden>' . $e(Text::_('MIT_PAR_NONE')) . '</p>'
            . '<script type="application/json" class="mpd-cfg">' . $cfg . '</script>'
            . '</div>' . self::assets();
    }

    /* ------------------------------------------------------------------ parish page */

    public static function page(array $props): string
    {
        try {
            $id = self::currentId($props);
            if (!$id) {
                return '';
            }
            $r = null;
            foreach (self::all() as $x) {
                if ($x['id'] === $id) {
                    $r = $x;
                    break;
                }
            }
            if (!$r) {
                return '';
            }
            return self::pageHtml($r, $props);
        } catch (\Throwable $e) {
            Log::add('Parish page: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function pageText(array $props): string
    {
        try {
            $id = self::currentId($props);
            foreach (self::all() as $r) {
                if ($r['id'] === $id) {
                    return '<p>' . self::e($r['name'] . ' – ' . self::cityLine($r)) . '</p>' . self::pick($r['v'], 'parish-about');
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    private static function currentId(array $props): int
    {
        $id = (int) ($props['article_id'] ?? 0);
        if ($id) {
            return $id;
        }
        $in = Factory::getApplication()->getInput();
        return $in->get('option') === 'com_content' && $in->get('view') === 'article' ? $in->getInt('id') : 0;
    }

    /** Clergy of this parish with title, photo and contact. */
    private static function clergy(int $parishId): array
    {
        $list = Repository::safe(fn () => Repository::clergyOf($parishId));
        if (!$list) {
            return [];
        }
        $ids = array_map(fn ($c) => (int) $c->clergy->id, $list);
        $db = self::db();
        $q = $db->getQuery(true)->select($db->quoteName(['f.name', 'v.item_id', 'v.value']))
            ->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->whereIn($db->quoteName('f.name'), ['clergy-title', 'clergy-title-ro', 'clergy-title-en', 'clergy-title-es', 'clergy-email', 'clergy-phone'], ParameterType::STRING)
            ->whereIn($db->quoteName('v.item_id'), array_map('strval', $ids), ParameterType::STRING);
        $vals = [];
        foreach ($db->setQuery($q)->loadObjectList() as $x) {
            $vals[(int) $x->item_id][$x->name] = (string) $x->value;
        }
        $out = [];
        foreach ($list as $c) {
            $a = $c->clergy;
            $v = $vals[(int) $a->id] ?? [];
            $key = $v['clergy-title'] ?? '';
            $custom = trim((string) ($v['clergy-title-' . self::lang()] ?? ''));
            $images = json_decode((string) ($a->images ?? ''), true) ?: [];
            $img = (string) ($images['image_intro'] ?? '') ?: (string) ($images['image_fulltext'] ?? '');
            $out[] = [
                'id'    => (int) $a->id,
                'name'  => (string) $a->title,
                'title' => $custom !== '' ? $custom : ($key !== '' && $key !== 'other' ? Text::_('MIT_O_CLERGY_TITLE_' . strtoupper($key)) : ''),
                'role'  => (string) $c->role,
                'email' => trim((string) ($v['clergy-email'] ?? '')),
                'phone' => trim((string) ($v['clergy-phone'] ?? '')),
                'image' => $img !== '' ? explode('#', $img, 2)[0] : '',
            ];
        }
        return $out;
    }

    private static function initials(string $name): string
    {
        $p = preg_split('/\s+/', trim($name));
        return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
    }

    /** E-mail and phone links are written in by the script from base64, so the address never appears in the HTML. */
    private static function mailLink(string $email, string $label, string $class = ''): string
    {
        return '<a' . ($class ? ' class="' . $class . '"' : '') . ' href="#" data-m="' . base64_encode($email) . '">' . self::I_MAIL . ' ' . self::e($label) . '</a>';
    }

    private static function telLink(string $phone, string $label, string $class = ''): string
    {
        return '<a' . ($class ? ' class="' . $class . '"' : '') . ' href="tel:' . self::e(preg_replace('/[^0-9+]/', '', $phone)) . '">' . self::I_PHONE . ' ' . self::e($label) . '</a>';
    }

    private static function nearby(array $r, int $n = 3): array
    {
        if (!$r['point']) {
            return [];
        }
        [$la, $lo] = $r['point'];
        $d = [];
        foreach (self::all() as $x) {
            if ($x['id'] === $r['id'] || !$x['point']) {
                continue;
            }
            $dLa = deg2rad($x['point'][0] - $la);
            $dLo = deg2rad($x['point'][1] - $lo);
            $h = sin($dLa / 2) ** 2 + cos(deg2rad($la)) * cos(deg2rad($x['point'][0])) * sin($dLo / 2) ** 2;
            $d[] = [2 * 6371 * asin(min(1, sqrt($h))), $x];
        }
        usort($d, fn ($a, $b) => $a[0] <=> $b[0]);
        return array_column(array_slice($d, 0, $n), 1);
    }

    /** Plain text cut to $max characters at a word boundary. */
    private static function clip(string $html, int $max): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($t) <= $max) {
            return $t;
        }
        $cut = mb_substr($t, 0, $max - 1);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:–-") . '…';
    }

    /**
     * Search engine data for a parish page: the meta description (type and place, then the start of
     * the "About" text in the page language) and schema.org JSON-LD (name, address, map point, phone,
     * website, Facebook, photo). The e-mail address is left out on purpose, as on the page itself.
     */
    private static function seo($doc, array $r): void
    {
        if (!method_exists($doc, 'addCustomTag')) {
            return;
        }
        $v = $r['v'];
        $place = implode(', ', array_filter([$r['city'], $r['stateName']]));
        $lead = $place !== '' ? Text::sprintf('MIT_PAR_META_' . strtoupper($r['type']), $place) : '';
        $about = self::clip(self::pick($v, 'parish-about'), 400);
        $desc = self::clip(trim($lead . ' ' . $about), 160);
        if ($desc !== '') {
            $doc->setDescription($desc);
        }

        $abs = function (string $u): string {
            if ($u === '' || preg_match('#^https?://#i', $u)) {
                return $u;
            }
            return $u[0] === '/' ? rtrim(Uri::getInstance()->toString(['scheme', 'host', 'port']), '/') . $u : Uri::root() . $u;
        };
        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => $r['type'] === 'monastery' ? 'PlaceOfWorship' : 'Church',
            'name'     => $r['name'],
            'url'      => $abs($r['url']),
        ];
        if ($desc !== '') {
            $ld['description'] = $desc;
        }
        $addr = array_filter([
            'streetAddress'   => $r['street'],
            'addressLocality' => $r['city'],
            'addressRegion'   => $r['state'],
            'postalCode'      => $r['postal'],
            'addressCountry'  => $r['country'],
        ], fn ($x) => $x !== '');
        if ($addr) {
            $ld['address'] = ['@type' => 'PostalAddress'] + $addr;
        }
        if ($r['point']) {
            $ld['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $r['point'][0], 'longitude' => $r['point'][1]];
            $ld['hasMap'] = 'https://www.google.com/maps/search/?api=1&query=' . $r['point'][0] . ',' . $r['point'][1];
        }
        if (($phone = self::str($v['parish-phone'] ?? '')) !== '') {
            $ld['telephone'] = $phone;
        }
        if ($r['image'] !== '') {
            $ld['image'] = $abs($r['image']);
        }
        $same = array_values(array_filter([self::str($v['parish-website'] ?? ''), self::str($v['parish-facebook'] ?? '')]));
        if ($same) {
            $ld['sameAs'] = count($same) === 1 ? $same[0] : $same;
        }
        $doc->addCustomTag('<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>');
    }

    private static function pageHtml(array $r, array $props): string
    {
        $e = [self::class, 'e'];
        $v = $r['v'];
        $L = self::lang();

        // Browser tab shows the name in the page language (the article title keeps the city for editors)
        try {
            $doc = Factory::getApplication()->getDocument();
            $app  = Factory::getApplication();
            $site = (string) $app->get('sitename');
            // Same order and separator as the rest of the site (Global Configuration > Site Name in Page Titles)
            switch ((int) $app->get('sitename_pagetitles', 0)) {
                case 1:
                    $title = Text::sprintf('JPAGETITLE', $site, $r['name']);
                    break;
                case 2:
                    $title = Text::sprintf('JPAGETITLE', $r['name'], $site);
                    break;
                default:
                    $title = $r['name'];
            }
            $doc->setTitle($title);
            self::seo($doc, $r);
        } catch (\Throwable $x) {
        }

        $addr1 = $r['street'];
        $addr2 = trim(implode(', ', array_filter([$r['city'], trim($r['state'] . ' ' . $r['postal'])])));
        $heroAddr = implode(', ', array_filter([$r['street'], $r['city'], $r['stateName']]));
        $website = self::str($v['parish-website'] ?? '');
        $webShow = preg_replace('#^https?://(www\.)?#i', '', rtrim($website, '/'));
        $facebook = self::str($v['parish-facebook'] ?? '');
        $phone = self::str($v['parish-phone'] ?? '');
        $email = self::str($v['parish-email'] ?? '');

        // ----- hero
        $hero = '<div class="mpp-hero"><div class="mpp-hero-in">' . self::crumbs($r['name'])
            . '<div class="mpp-hero-text"><h1 class="mpx-h1">' . $e($r['name']) . '</h1>'
            . ($heroAddr !== '' ? '<p class="mpp-addr">' . self::I_PIN . $e($heroAddr) . '</p>' : '')
            . '<div class="mpp-hero-acts">' . self::dirBtn($r, ' mpx-big')
            . ($website !== '' ? '<a class="mpx-btn mpx-ghost mpx-big" href="' . $e($website) . '" target="_blank" rel="noopener">' . self::I_GLOBE . ' ' . $e(Text::_('MIT_PAR_WEBSITE')) . '</a>' : '')
            . '</div></div></div></div>';

        // ----- main column
        $main = '';
        $about = self::pick($v, 'parish-about');
        if ($about !== '') {
            $main .= '<div class="mpp-sect"><h2 class="mpx-h2">' . $e(self::byType('MIT_PAR_ABOUT', $r['type'])) . '</h2><div class="mpp-prose">' . $about . '</div></div>';
        }
        $sched = '';
        foreach (self::subrows($v['parish-services'] ?? '') as $row) {
            $day = self::pick($row, 'sched-day');
            $svc = self::pick($row, 'sched-service');
            $time = self::str($row['sched-time'] ?? '');
            $sched .= '<tr><td>' . $e($day) . '</td><td>' . $e($svc) . '</td><td>' . $e($time) . '</td></tr>';
        }
        if ($sched !== '') {
            $main .= '<div class="mpp-sect"><h2 class="mpx-h2">' . $e(Text::_('MIT_PAR_SCHEDULE')) . '</h2><table class="mpp-sched">' . $sched . '</table></div>';
        }
        // Same card as the clergy directory; the line under the name shows the role at this parish
        $cl = Directory::cardsFor(array_map(fn ($c) => ['id' => $c['id'], 'line' => $c['role']], self::clergy($r['id'])));
        if ($cl !== '') {
            $main .= '<div class="mpp-sect"><h2 class="mpx-h2">' . $e(self::byType('MIT_PAR_CLERGY', $r['type'])) . '</h2>' . $cl . '</div>';
        }
        $ld = '';
        // Council order: chanter, president, choir director, religious education, AROLA, ROYA, then anything else
        $rank = ['chanter' => 0, 'council-president' => 1, 'choir-director' => 2, 'religious-education' => 3, 'arola-director' => 4, 'roya-director' => 5];
        $leaders = self::subrows($v['parish-lay-leaders'] ?? '');
        $keyed = [];
        foreach (array_values($leaders) as $i => $row) {
            $best = 9;
            foreach ((array) ($row['lay-roles'] ?? []) as $x) {
                $best = min($best, $rank[(string) $x] ?? 8);
            }
            $keyed[] = [$best, $i, $row];
        }
        usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach (array_column($keyed, 2) as $row) {
            $name = self::str($row['lay-name'] ?? '');
            if ($name === '') {
                continue;
            }
            $roles = array_filter(array_map('strval', (array) ($row['lay-roles'] ?? [])));
            $roleTxt = implode(' · ', array_map(fn ($x) => Text::_('MIT_O_LAY_ROLES_' . strtoupper(str_replace('-', '_', $x))), $roles));
            $ph = self::str($row['lay-phone'] ?? '');
            $em = self::str($row['lay-email'] ?? '');
            $links = ($ph !== '' ? self::telLink($ph, $ph) : '') . ($em !== '' ? self::mailLink($em, Text::_('MIT_DIR_EMAIL')) : '');
            $ld .= '<div class="mpp-ld">' . ($roleTxt !== '' ? '<span>' . $e($roleTxt) . '</span>' : '') . '<b>' . $e($name) . '</b>'
                . ($links !== '' ? '<div class="mpp-ld-c">' . $links . '</div>' : '') . '</div>';
        }
        if ($ld !== '') {
            $main .= '<div class="mpp-sect"><h2 class="mpx-h2">' . $e(Text::_('MIT_PAR_LEADERSHIP')) . '</h2><div class="mpp-lead">' . $ld . '</div></div>';
        }

        // ----- side column
        $side = '';
        $feasts = [];
        foreach (self::subrows($v['parish-feasts'] ?? '') as $row) {
            $f = self::pick($row, 'feast');
            if ($f !== '') {
                $feasts[] = $f;
            }
        }
        if ($feasts) {
            $side .= '<div class="mpp-feast"><div class="mpx-type mpp-gold">' . $e(Text::_(count($feasts) > 1 ? 'MIT_PAR_FEASTS' : 'MIT_PAR_FEAST')) . '</div>'
                . implode('', array_map(fn ($f) => '<div class="mpp-feast-d">' . $e($f) . '</div>', $feasts)) . '</div>';
        }
        $ci = '';
        if ($addr1 !== '' || $addr2 !== '') {
            $ci .= '<div class="mpp-ci">' . self::I_PIN . '<span>' . $e($addr1) . ($addr1 !== '' && $addr2 !== '' ? '<br>' : '') . $e($addr2) . '</span></div>';
        }
        if ($phone !== '') {
            $ci .= '<div class="mpp-ci">' . self::telLink($phone, $phone) . '</div>';
        }
        if ($email !== '') {
            $ci .= '<div class="mpp-ci">' . self::mailLink($email, Text::_('MIT_PAR_EMAIL')) . '</div>';
        }
        if ($website !== '') {
            $ci .= '<div class="mpp-ci"><a href="' . $e($website) . '" target="_blank" rel="noopener">' . self::I_GLOBE . ' ' . $e($webShow) . '</a></div>';
        }
        if ($facebook !== '') {
            $ci .= '<div class="mpp-ci"><a href="' . $e($facebook) . '" target="_blank" rel="noopener">' . self::I_FB . ' Facebook</a></div>';
        }
        [$tileUrl, $attr] = self::tiles();
        $mapCfg = $r['point'] ? json_encode(['pt' => $r['point'], 'tiles' => $tileUrl, 'attr' => $attr], JSON_HEX_TAG | JSON_HEX_AMP) : '';
        if ($ci !== '' || $mapCfg !== '') {
            $side .= '<div class="mpp-box' . ($mapCfg === '' ? ' mpp-nomap' : '') . '">' . ($mapCfg !== '' ? '<div class="mpp-map" data-map=\'' . $mapCfg . '\'></div>' : '') . '<div class="mpp-cis">' . $ci . '</div></div>';
        }
        $dd = '';
        if ($r['deanery'] !== '') {
            $dd .= '<div><span>' . $e(Text::_('MIT_PAR_DEANERY')) . '</span><b>' . $e(Text::_('MIT_O_PARISH_DEANERY_' . strtoupper(str_replace('-', '_', $r['deanery'])))) . '</b></div>';
        }
        if (isset(self::DIOCESES[$r['diocese']])) {
            $dd .= '<div><span>' . $e(Text::_('MIT_DIR_DIOCESE')) . '</span><b>' . $e(Text::_(self::DIOCESES[$r['diocese']])) . '</b></div>';
        }
        if ($dd !== '') {
            $side .= '<div class="mpp-dd">' . $dd . '</div>';
        }

        // ----- nearby parishes
        $near = '';
        foreach (self::nearby($r) as $x) {
            $near .= '<a class="mpp-near" href="' . $e($x['url']) . '"><span class="mpx-type mpx-' . $e($x['type']) . '">' . $e(self::typeLabel($x['type'])) . '</span>'
                . '<h3>' . $e($x['name']) . '</h3><span class="mpd-loc">' . self::I_PIN . $e(self::cityLine($x)) . '</span></a>';
        }
        $dirUrl = '';
        $item = Factory::getApplication()->getMenu()->getActive();
        if ($item) {
            $dirUrl = Route::_('index.php?Itemid=' . (int) $item->id);
        }
        $nearHtml = $near !== '' ? '<div class="mpp-nearband"><div class="mpp-nearhead"><h2 class="mpx-h2">' . $e(Text::_('MIT_PAR_NEARBY')) . '</h2>'
            . ($dirUrl !== '' ? '<a href="' . $e($dirUrl) . '">' . $e(Text::_('MIT_PAR_ALL_PARISHES')) . ' →</a>' : '') . '</div><div class="mpp-neargrid">' . $near . '</div></div>' : '';

        return '<div class="mpp' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '" data-mpp>' . $hero
            . ($main === '' && $side === '' ? '' : ($main === '' ? '<div class="mpp-grid mpp-solo">' : '<div class="mpp-grid">') . '<div class="mpp-main">' . $main . '</div><aside class="mpp-side">' . $side . '</aside></div>')
            . $nearHtml . '</div>' . self::assets();
    }

    /* ------------------------------------------------------------------ CSS and JS (once per page) */

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        // Text of the "use two fingers" hint shown over the maps on touch screens
        return '<script>document.documentElement.dataset.mptf=' . json_encode(Text::_('MIT_PAR_TWO_FINGERS'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>' . <<<'HTML'
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>
<style>
/* Parish directory and parish page (Mitropolia plugin). Brand colors as in the mockups. */
.mpd,.mpp{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-red-h:#8A2530;--mp-gold:#B99755;--mp-gold-l:#D4AF37;--mp-ink:#1B2A4A;--mp-muted:#6B6F7B;--mp-line:#E4D8BE;--mp-line2:#EFE3CB;--mp-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:var(--mp-ink)}
.mpx-h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:56px;line-height:1.05;margin:0 0 14px;color:inherit}
.mpx-h2{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:32px;line-height:1.2;color:var(--mp-navy);margin:0 0 18px}
.mpx-crumbs,.mpx-crumbs a{font-size:14px}
.mpx-crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.mpx-type{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mp-red)}
.mpx-type.mpx-cathedral{color:var(--mp-blue)}.mpx-type.mpx-monastery{color:#6B5A34}
.mpx-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:4px;padding:10px 14px;font-weight:600;font-size:14px;line-height:1.2;border:1px solid transparent;white-space:nowrap;cursor:pointer;text-decoration:none!important;font-family:inherit}
.mpx-red{background:var(--mp-red);color:#fff!important}.mpx-red:hover{background:var(--mp-red-h)}
.mpx-line{border-color:#D9CBAA;color:var(--mp-blue)!important;background:#fff}.mpx-line:hover{border-color:var(--mp-blue)}
.mpx-ghost{border-color:rgba(255,255,255,.5);color:#fff!important;background:transparent}.mpx-ghost:hover{border-color:#fff}
.mpx-big{padding:13px 20px;font-size:15px}
.mpx-sel{height:46px;border:1px solid #D9CBAA;border-radius:4px;padding:0 14px;font:inherit;font-size:16px;background:#fff;color:var(--mp-blue);max-width:100%}
.mpx-chip{font:inherit;font-size:14px;font-weight:600;padding:6px 16px;border-radius:999px;border:1px solid #D9CBAA;background:#fff;color:var(--mp-blue);cursor:pointer}
.mpx-chip.on{background:var(--mp-blue);border-color:var(--mp-blue);color:#fff}
.mpx-chip[hidden]{display:none}
/* directory */
.mpd-hero{position:relative;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax);border-bottom:1px solid var(--mp-line)}
.mpd-hero-in{padding:22px 0 36px}
.mpd .mpx-crumbs a{color:var(--mp-blue)}.mpd .mpx-crumbs span{color:#6B5A34}
.mpd-hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:32px;flex-wrap:wrap;margin-top:34px}
.mpd-hero-text{max-width:640px}.mpd .mpx-h1{color:var(--mp-navy)}
.mpd-intro{font-family:'Source Serif 4',Georgia,serif;font-size:20px;line-height:1.55;color:#3C4A66;margin:0}
.mpd-controls{display:flex;gap:8px;flex-wrap:wrap}
.mpd-search{position:relative;display:inline-flex}
.mpd-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--mp-gold)}
.mpd-search input{height:46px;border:1px solid #D9CBAA;border-radius:4px;padding:0 14px 0 42px;font:inherit;font-size:16px;width:250px;background:#fff;box-sizing:border-box}
.mpd-near{height:46px}
.mpd-chiprow{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center;margin-top:24px}
.mpd-chips{display:flex;gap:8px;flex-wrap:wrap}
.mpd-legend{display:flex;gap:16px;font-size:13px;color:#3C4A66;align-items:center;flex-wrap:wrap}
.mpd-legend i{display:inline-block;width:11px;height:11px;border-radius:50%;margin-right:5px;vertical-align:-1px}
.mpx-dot-c{background:var(--mp-blue)}.mpx-dot-m{background:var(--mp-gold)}.mpx-dot-p{background:var(--mp-red)}
.mpd-map{margin-top:24px;height:460px;border-radius:8px;overflow:hidden;border:1px solid var(--mp-line);background:#E9E4D8;scroll-margin-top:110px}
.mpd-mapin{height:100%;width:100%}
.mpd-count{margin:22px 0 0;font-size:15px;color:var(--mp-muted)}
.mpd-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;padding-bottom:40px}
.mpd-h{grid-column:1/-1;font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:28px;color:var(--mp-navy);margin:22px 0 2px;display:flex;align-items:baseline;gap:12px}
.mpd-h small{font-family:'Source Sans 3',sans-serif;font-size:14px;color:var(--mp-muted)}
@media (max-width:1000px){.mpd-h{flex-wrap:wrap;row-gap:2px}.mpd-h small{flex-basis:100%}}
.mpx-tf{position:absolute;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;text-align:center;background:rgba(23,46,92,.6);color:#fff;font:600 16px/1.3 'Source Sans 3',sans-serif;opacity:0;pointer-events:none;transition:opacity .2s}.mpx-tf.on{opacity:1}
.mpd-card{background:#fff;border:1px solid var(--mp-line);border-radius:6px;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .2s,border-color .2s}
.mpd-card:hover,.mpd-card.hi{box-shadow:0 14px 32px -18px rgba(23,46,92,.45);border-color:var(--mp-gold)}
.mpd-ph{aspect-ratio:16/7;background:linear-gradient(160deg,#F2E7D0,#E6D6B4);display:flex;align-items:center;justify-content:center;color:var(--mp-gold);overflow:hidden}
.mpd-ph[data-zoom]{cursor:pointer}.mpd-ph img{width:100%;height:100%;object-fit:cover}
.mpd-bd{padding:16px 18px 18px;display:flex;flex-direction:column;gap:6px;flex:1}
.mpd-card h3{margin:0;font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:20px;line-height:1.25;color:var(--mp-navy);min-height:75px}
.mpd-card h3 a{color:inherit;text-decoration:none}.mpd-card h3 a:hover{color:var(--mp-red)}
.mpd-loc{font-size:15px;color:#3C4A66;display:flex;gap:6px;align-items:center}.mpd-loc svg{color:var(--mp-gold);flex:none}
.mpd-acts{display:flex;gap:8px;margin-top:auto;padding-top:10px}.mpd-acts .mpx-btn{flex:1}
.mpd-none{text-align:center;color:var(--mp-muted);font-size:18px;padding:40px 0}
.mpx-pin{width:16px;height:16px;border-radius:50%;background:var(--mp-red,#A32D36);border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);box-sizing:border-box;transition:transform .15s}
.mpx-pin.cathedral{background:#203D78}.mpx-pin.monastery{background:#B99755}.mpx-pin.big{transform:scale(1.5)}
.mpd .leaflet-popup-content,.mpp .leaflet-popup-content{font-family:'Source Sans 3',sans-serif;font-size:14px}
.mpd .leaflet-popup-content b{font-family:'Baskervville',Georgia,serif;font-size:17px;font-weight:500;color:#172E5C;display:block;margin:2px 0}
/* parish page */
.mpp-hero{position:relative;z-index:0;isolation:isolate;color:#fff;clip-path:inset(0 -100vmax)}
.mpp-hero::before{content:"";position:absolute;inset:0 -100vmax;background:linear-gradient(120deg,#172E5C 0%,#203D78 55%,#2a4a8c 100%);z-index:-1}
.mpp-hero-in{padding:22px 0 64px}
.mpp .mpx-crumbs{color:#C9D3E8}.mpp .mpx-crumbs a{color:#fff}
.mpp-hero-text{max-width:1060px;margin-top:56px}.mpp-hero .mpx-h1{font-size:52px}
.mpp-addr{font-size:20px;color:#E4E9F4;margin:0;display:flex;gap:8px;align-items:center}.mpp-addr svg{color:var(--mp-gold-l);width:18px;height:18px;flex:none}
.mpp-hero-acts{display:flex;gap:10px;margin-top:28px;flex-wrap:wrap}
.mpp-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:56px;align-items:start;padding:56px 0 80px}
.mpp-sect{padding:40px 0;border-top:1px solid var(--mp-line)}.mpp-sect:first-child{border-top:0;padding-top:0}
.mpp-prose{font-family:'Source Serif 4',Georgia,serif;font-size:18px;line-height:1.65}.mpp-prose p{margin:0 0 16px}
.mpp-sched{width:100%;border-collapse:collapse;font-size:17px}
.mpp-sched td{padding:14px 12px 14px 0;border-bottom:1px solid var(--mp-line2);vertical-align:top}
.mpp-sched td:first-child{width:34%;color:var(--mp-muted);font-weight:600}
.mpp-sched td:last-child{text-align:right;color:var(--mp-navy);font-weight:600;white-space:nowrap;padding-right:0}
.mpp-clgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;max-width:560px}
.mpp-cl{background:#fff;border:1px solid var(--mp-line);border-radius:6px;overflow:hidden;display:flex;flex-direction:column;text-align:center}
.mpp-cl-ph{aspect-ratio:5/4;background:linear-gradient(160deg,#F2E7D0,#E6D6B4);display:flex;align-items:center;justify-content:center;font-family:'Baskervville',Georgia,serif;font-size:56px;color:#6B5A34;overflow:hidden}
.mpp-cl-ph img{width:100%;height:100%;object-fit:cover;object-position:50% 25%}
.mpp-cl-b{padding:14px 16px 16px}.mpp-cl-rk{font-size:13px;color:var(--mp-muted)}
.mpp-cl h3{margin:0 0 4px;font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:21px;color:var(--mp-navy)}
.mpp-cl-role{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mp-gold);margin-bottom:12px}
.mpp-cl-acts{display:flex;gap:8px}.mpp-cl-acts .mpx-btn{flex:1}
.mpp-lead{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.mpp-ld{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:16px 18px;display:flex;flex-direction:column;gap:2px}
.mpp-ld span{font-size:15px;font-weight:600;color:#6B5A34}.mpp-ld b{font-weight:600;color:var(--mp-navy);font-size:17px}
.mpp-ld-c{display:flex;gap:18px;margin-top:10px;padding-top:10px;border-top:1px solid var(--mp-line2);flex-wrap:wrap}
.mpp-ld-c a{display:inline-flex;gap:6px;align-items:center;font-size:15px;font-weight:600;color:var(--mp-blue);text-decoration:none}.mpp-ld-c svg{color:var(--mp-gold)}
.mpp-side{position:sticky;top:100px;display:flex;flex-direction:column;gap:18px}
.mpp-feast{background:var(--mp-blue);color:#fff;border-radius:8px;padding:22px}
.mpp-gold{color:var(--mp-gold-l)}
.mpp-feast-d{font-family:'Baskervville',Georgia,serif;font-size:21px;line-height:1.25;margin-top:6px}
.mpp-feast-d+.mpp-feast-d{padding-top:10px;margin-top:10px;border-top:1px solid rgba(255,255,255,.18)}
.mpp-box{background:#fff;border:1px solid var(--mp-line);border-radius:8px;overflow:hidden}
.mpp-map{height:220px;background:#E9E4D8}
.mpp-solo{grid-template-columns:minmax(0,1fr)}.mpp-solo .mpp-main{display:none}.mpp-solo .mpp-side{position:static;display:grid;grid-template-columns:minmax(0,1fr) 360px;align-items:start}.mpp-solo .mpp-box{grid-column:1;grid-row:1 / span 3;display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)}.mpp-solo .mpp-map{height:100%;min-height:320px}.mpp-solo .mpp-cis{align-self:center}.mpp-solo .mpp-box.mpp-nomap{display:block}.mpp-solo .mpp-side>:not(.mpp-box){grid-column:2}
@media (max-width:980px){.mpp-solo .mpp-side{grid-template-columns:minmax(0,1fr)}.mpp-solo .mpp-box{grid-row:auto;display:block}.mpp-solo .mpp-map{height:260px;min-height:0}.mpp-solo .mpp-side>:not(.mpp-box){grid-column:1}}
.mpp-cis{padding:6px 22px 10px}
.mpp-ci{display:flex;gap:12px;padding:14px 0;border-bottom:1px solid var(--mp-line2);font-size:16px;line-height:1.45}.mpp-ci:last-child{border-bottom:0}
.mpp-ci>svg,.mpp-ci a svg{flex:none;margin-top:3px;color:var(--mp-gold)}
.mpp-ci a{color:var(--mp-blue);font-weight:600;display:inline-flex;gap:12px;align-items:flex-start;text-decoration:none;overflow-wrap:anywhere}.mpp-ci a:hover{text-decoration:underline}
.mpp-dd{background:#fff;border:1px solid var(--mp-line);border-radius:8px;padding:18px 22px;font-size:15px;line-height:1.35;display:flex;flex-direction:column;gap:14px}
.mpp-dd span{display:block;font-size:14px;color:var(--mp-muted);margin-bottom:2px}.mpp-dd b{font-weight:600;color:var(--mp-blue)}
.mpp-nearband{position:relative;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax);border-top:1px solid var(--mp-line);padding:48px 0 64px}
.mpp-nearhead{display:flex;justify-content:space-between;align-items:baseline;gap:16px;flex-wrap:wrap}.mpp-nearhead a{font-weight:600;color:var(--mp-red);text-decoration:none}
.mpp-neargrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.mpp-near{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:16px 18px 18px;display:flex;flex-direction:column;gap:6px;text-decoration:none!important;transition:box-shadow .2s,border-color .2s}
.mpp-near:hover{box-shadow:0 14px 32px -18px rgba(23,46,92,.45);border-color:var(--mp-gold)}
.mpp-near h3{margin:0;font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:18px;line-height:1.25;color:var(--mp-navy);min-height:45px}
@media (max-width:1000px){.mpd-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mpd-map{height:360px}}
@media (max-width:980px){.mpp-hero .mpx-h1{font-size:42px}.mpp-grid{grid-template-columns:minmax(0,1fr);gap:32px}.mpp-side{position:static}}
@media (max-width:640px){.mpx-h1{font-size:40px}.mpp-hero .mpx-h1{font-size:32px;line-height:1.1}.mpd-grid,.mpp-neargrid,.mpp-lead,.mpp-clgrid{grid-template-columns:minmax(0,1fr)}.mpd-search,.mpd-search input,.mpd-controls .mpx-sel,.mpd-near{width:100%}.mpp-hero-text{margin-top:32px}.mpp-sched td:first-child{width:auto}}
</style>
<script>(function(run){if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();})(function(){
(function(){
function ready(f){if(window.L)return f();var n=0,t=setInterval(function(){if(window.L||++n>100){clearInterval(t);if(window.L)f();}},50);}
function fold(s){return (s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toUpperCase();}
function tiles(map,c){L.tileLayer(c.tiles,{attribution:c.attr,maxZoom:18,subdomains:'abcd'}).addTo(map);}
function pin(t,big){return L.divIcon({className:'',html:'<div class="mpx-pin '+t+(big?' big':'')+'"></div>',iconSize:[16,16],iconAnchor:[8,8]});}
function guard(m,el){if(!window.matchMedia||!matchMedia('(pointer:coarse)').matches)return;m.dragging.disable();m.options.tap=false;var h=document.createElement('div');h.className='mpx-tf';h.textContent=document.documentElement.dataset.mptf||'';el.appendChild(h);var t;el.addEventListener('touchstart',function(e){if(e.touches.length===1){h.classList.add('on');clearTimeout(t);t=setTimeout(function(){h.classList.remove('on');},1400);}else{h.classList.remove('on');}},{passive:true});el.addEventListener('touchend',function(e){if(!e.touches.length){clearTimeout(t);t=setTimeout(function(){h.classList.remove('on');},700);}},{passive:true});}
function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
document.querySelectorAll('[data-m]').forEach(function(a){try{a.href='mailto:'+decodeURIComponent(escape(atob(a.dataset.m)));}catch(x){}});
(function(){var r=[].slice.call(document.querySelectorAll('[data-mpd]'));if(!r.length&&document.querySelector('[data-mpd-part]'))r=[document.body];return r;})().forEach(function(root){
 var cfg=JSON.parse(root.querySelector('.mpd-cfg').textContent),q=root.querySelector('[data-f=q]'),d=root.querySelector('[data-f=d]'),s=root.querySelector('[data-f=s]'),cnt=root.querySelector('.mpd-count'),none=root.querySelector('.mpd-none'),T='',map=null,marks=[],box=root.querySelector('.mpd-map');
 ready(function(){map=L.map(root.querySelector('.mpd-mapin'),{scrollWheelZoom:false}).setView([42,-92],4);tiles(map,cfg);guard(map,root.querySelector('.mpd-mapin'));
  cfg.pts.forEach(function(p,i){if(!p){marks.push(null);return;}var m=L.marker([p[0],p[1]],{icon:pin(p[2])}).bindPopup('<span class="mpx-type mpx-'+p[2]+'">'+esc(cfg.types[p[2]]||'')+'</span><b>'+esc(p[3])+'</b>'+esc(p[4])+'<br><a href="'+p[5]+'">'+esc(cfg.page)+' →</a>');marks.push(m);});
  apply(true);});
 function apply(first){var t=fold(q.value.trim()),dv=d.value,sv=s.value,n=0,shown={},vis=[];
  root.querySelectorAll('.mpd-card').forEach(function(c){var ok=(!T||c.dataset.t===T)&&(!dv||c.dataset.d===dv)&&(!sv||c.dataset.s===sv)&&(!t||c.dataset.q.indexOf(t)>-1);c.style.display=ok?'':'none';var m=marks[+c.dataset.i];if(map&&m){ok?m.addTo(map):m.remove();if(ok)vis.push(m.getLatLng());}if(ok){n++;shown[c.dataset.d]=1;}});
  root.querySelectorAll('.mpd-h').forEach(function(h){h.style.display=shown[h.dataset.h]?'':'none';});
  var r=n%100;cnt.textContent=(n===1?cnt.dataset.c1:((r>=20||(r===0&&n>0))&&cnt.dataset.cm?cnt.dataset.cm:cnt.dataset.cn)).replace('%d',n);none.hidden=n>0;
  if(map){if(vis.length&&(t||dv||sv||T)){map.fitBounds(vis,{padding:[40,40],maxZoom:11});}else if(vis.length>1){map.fitBounds(vis,{padding:[40,40],maxZoom:6});}else if(vis.length===1){map.setView(vis[0],11);}}}
 root.querySelectorAll('.mpx-chip').forEach(function(b){b.addEventListener('click',function(){T=b.dataset.ty;root.querySelectorAll('.mpx-chip').forEach(function(x){x.classList.toggle('on',x===b);});apply();});});
 q.addEventListener('input',function(){apply();});d.addEventListener('change',function(){apply();});s.addEventListener('change',function(){apply();});
 root.querySelectorAll('.mpd-card').forEach(function(c){function m(){return marks[+c.dataset.i];}
  c.addEventListener('mouseenter',function(){var x=m();if(x&&x.getElement())x.getElement().firstChild.classList.add('big');});
  c.addEventListener('mouseleave',function(){var x=m();if(x&&x.getElement())x.getElement().firstChild.classList.remove('big');});
  var ph=c.querySelector('[data-zoom]');if(ph)ph.addEventListener('click',function(){var x=m();if(x&&map){box.scrollIntoView({behavior:'smooth'});map.setView(x.getLatLng(),12);x.openPopup();}});});
 root.querySelector('.mpd-near').addEventListener('click',function(){if(!navigator.geolocation||!map)return;box.scrollIntoView({behavior:'smooth'});navigator.geolocation.getCurrentPosition(function(p){map.setView([p.coords.latitude,p.coords.longitude],8);});});
 apply();
});
document.querySelectorAll('.mpp-map[data-map]').forEach(function(el){var c=JSON.parse(el.dataset.map);ready(function(){var m=L.map(el,{scrollWheelZoom:false,zoomControl:false,attributionControl:true}).setView(c.pt,14);tiles(m,c);guard(m,el);L.marker(c.pt,{icon:pin('',true)}).addTo(m);});});
})();
});</script>
HTML;
    }
}
