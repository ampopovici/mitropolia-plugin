<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Server-rendered clergy directory for the "Clergy directory" builder element.
 * Data: published articles in the Clergy (ALL) category tree and their Mitropolia fields.
 * Labels come from the plugin language files (MIT_*), so each language page is translated.
 */
final class Directory
{
    private const ROOT_ALIAS = 'clergy';
    private const DIOCESE_BY_ALIAS = ['archdiocese' => 'usa', 'diocese-of-canada' => 'canada', 'south-america' => 'sa'];
    private const GROUP_ORDER = ['hierarch' => 0, 'priest' => 1, 'deacon' => 2];

    public static function render(array $props): string
    {
        try {
            $rows = self::rows();
            return self::html($rows, $props);
        } catch (\Throwable $e) {
            Log::add('Clergy directory: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /** Plain text for search indexing (names and parishes). */
    public static function renderText(array $props): string
    {
        try {
            $out = [];
            foreach (self::rows() as $r) {
                $out[] = trim($r['title'] . ' ' . $r['name']) . ($r['parishes'] ? ' – ' . implode(', ', array_column($r['parishes'], 'name')) : '');
            }
            return '<p>' . implode('<br>', array_map(fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'), $out)) . '</p>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private static function lang(): string
    {
        return strtolower(substr(Factory::getApplication()->getLanguage()->getTag(), 0, 2));
    }

    /** Field id => name for the fields we read. */
    private static function fields(array $names): array
    {
        $db = self::db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name']))
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
            ->where($db->quoteName('state') . ' = 1')
            ->whereIn($db->quoteName('name'), $names, ParameterType::STRING);
        return $db->setQuery($q)->loadAssocList('id', 'name');
    }

    /** [itemId][fieldName] => value */
    private static function values(array $fieldIds, array $itemIds): array
    {
        if (!$fieldIds || !$itemIds) {
            return [];
        }
        $db = self::db();
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['field_id', 'item_id', 'value']))
            ->from($db->quoteName('#__fields_values'))
            ->whereIn($db->quoteName('field_id'), array_map('intval', array_keys($fieldIds)))
            ->whereIn($db->quoteName('item_id'), array_map('strval', $itemIds), ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $v) {
            $out[(int) $v->item_id][$fieldIds[$v->field_id]] = $v->value;
        }
        return $out;
    }

    private static function rows(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $db = self::db();

        // Clergy (ALL) category tree
        $q = $db->getQuery(true)
            ->select($db->quoteName(['c.id', 'c.alias']))
            ->from($db->quoteName('#__categories', 'c'))
            ->join('INNER', $db->quoteName('#__categories', 'r') . ' ON c.lft >= r.lft AND c.rgt <= r.rgt')
            ->where($db->quoteName('r.alias') . ' = ' . $db->quote(self::ROOT_ALIAS))
            ->where($db->quoteName('r.extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('c.published') . ' = 1');
        $cats = $db->setQuery($q)->loadAssocList('id', 'alias');
        if (!$cats) {
            return $cache = [];
        }

        // Published clergy articles, access checked by Joomla's site model
        $app   = Factory::getApplication();
        $model = $app->bootComponent('com_content')->getMVCFactory()->createModel('Articles', 'Site', ['ignore_request' => true]);
        $model->setState('params', ComponentHelper::getParams('com_content'));
        $model->setState('filter.category_id', array_map('intval', array_keys($cats)));
        $model->setState('filter.subcategories', false);
        $model->setState('filter.published', 1);
        $model->setState('filter.access', true);
        $model->setState('filter.language', false);
        $model->setState('list.start', 0);
        $model->setState('list.limit', 0);
        $model->setState('list.ordering', 'a.title');
        $model->setState('list.direction', 'ASC');
        $items = $model->getItems() ?: [];
        if (!$items) {
            return $cache = [];
        }

        $lang  = self::lang();
        $names = ['last-name', 'clergy-group', 'clergy-title', 'clergy-title-ro', 'clergy-title-en', 'clergy-title-es',
                  'clergy-status', 'clergy-email', 'clergy-phone', 'clergy-parishes', 'parish', 'role',
                  'parish-name-ro', 'parish-name-en', 'parish-name-es', 'parish-city'];
        $fields = self::fields($names);
        $byName = array_flip($fields);
        $vals   = self::values($fields, array_map(fn ($i) => (int) $i->id, $items));

        // Parish ids referenced by assignments
        $assign = [];
        $parishIds = [];
        foreach ($vals as $id => $v) {
            $rows = json_decode((string) ($v['clergy-parishes'] ?? ''), true) ?: [];
            foreach ($rows as $row) {
                $p = (int) ($row['field' . ($byName['parish'] ?? 0)] ?? 0);
                if ($p) {
                    $assign[$id][] = ['parish' => $p, 'role' => (string) ($row['field' . ($byName['role'] ?? 0)] ?? '')];
                    $parishIds[$p] = true;
                }
            }
        }
        $parishVals = self::values($fields, array_keys($parishIds));
        $parishTitles = [];
        if ($parishIds) {
            $q = $db->getQuery(true)->select($db->quoteName(['id', 'title']))->from($db->quoteName('#__content'))
                ->whereIn($db->quoteName('id'), array_keys($parishIds))->where($db->quoteName('state') . ' = 1');
            $parishTitles = $db->setQuery($q)->loadAssocList('id', 'title');
        }

        $out = [];
        foreach ($items as $item) {
            $id = (int) $item->id;
            $v  = $vals[$id] ?? [];

            $titleKey = (string) ($v['clergy-title'] ?? '');
            $custom   = trim((string) ($v['clergy-title-' . $lang] ?? ''));
            if ($custom === '' && $titleKey === 'other') {
                $custom = trim((string) ($v['clergy-title-ro'] ?? $v['clergy-title-en'] ?? ''));
            }
            $title = $custom !== '' ? $custom : ($titleKey && $titleKey !== 'other' ? Text::_('MIT_O_CLERGY_TITLE_' . strtoupper($titleKey)) : '');

            $last = trim((string) ($v['last-name'] ?? '')) ?: self::lastWord($item->title);
            $parishes = [];
            foreach ($assign[$id] ?? [] as $a) {
                if (!isset($parishTitles[$a['parish']])) {
                    continue;
                }
                $pv = $parishVals[$a['parish']] ?? [];
                $parishes[] = [
                    'name' => trim((string) ($pv['parish-name-' . $lang] ?? '')) ?: $parishTitles[$a['parish']],
                    'city' => trim((string) ($pv['parish-city'] ?? '')),
                ];
            }

            $images = json_decode((string) ($item->images ?? ''), true) ?: [];
            $img = (string) ($images['image_intro'] ?? '') ?: (string) ($images['image_fulltext'] ?? '');
            $img = $img !== '' ? explode('#', $img, 2)[0] : '';

            $group = (string) ($v['clergy-group'] ?? 'priest');
            $out[] = [
                'id'       => $id,
                'name'     => $item->title,
                'last'     => $last,
                'letter'   => substr(self::fold($last), 0, 1) ?: '#',
                'title'    => $title,
                'group'    => isset(self::GROUP_ORDER[$group]) ? $group : 'priest',
                'retired'  => ($v['clergy-status'] ?? '') === 'retired',
                'email'    => trim((string) ($v['clergy-email'] ?? '')),
                'phone'    => trim((string) ($v['clergy-phone'] ?? '')),
                'diocese'  => self::DIOCESE_BY_ALIAS[$cats[$item->catid] ?? ''] ?? '',
                'parishes' => $parishes,
                'image'    => $img,
            ];
        }
        usort($out, fn ($a, $b) => strcmp(self::fold($a['last'] . ' ' . $a['name']), self::fold($b['last'] . ' ' . $b['name'])));
        return $cache = $out;
    }

    private static function lastWord(string $s): string
    {
        $parts = preg_split('/\s+/', trim($s));
        return (string) end($parts);
    }

    public static function fold(string $s): string
    {
        $map = ['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','á'=>'a','à'=>'a','é'=>'e','è'=>'e','í'=>'i','ó'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
                'Ă'=>'A','Â'=>'A','Î'=>'I','Ș'=>'S','Ş'=>'S','Ț'=>'T','Ţ'=>'T','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ö'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','Ç'=>'C'];
        return strtoupper(strtr($s, $map));
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function initials(string $name): string
    {
        $p = preg_split('/\s+/', trim($name));
        $a = mb_substr($p[0] ?? '', 0, 1);
        $b = count($p) > 1 ? mb_substr(end($p), 0, 1) : '';
        return mb_strtoupper($a . $b);
    }

    private static function card(array $r, array $o = []): string
    {
        $o += ['photo' => true, 'parish' => true, 'contact' => true];
        $e = [self::class, 'e'];
        $mail  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>';
        $phone = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>';
        $copy  = Text::_('MIT_DIR_COPY');

        $photo = $r['image'] !== ''
            ? '<img src="' . $e(Uri::root(true) . '/' . ltrim($r['image'], '/')) . '" alt="" loading="lazy">'
            : '<span class="mcd-ini" aria-hidden="true">' . $e(self::initials($r['name'])) . '</span>';
        $badge = $r['retired'] ? '<span class="mcd-badge">' . $e(Text::_('MIT_O_CLERGY_STATUS_RETIRED')) . '</span>' : '';

        // On a parish page the line under the name shows the role at that parish instead of the parish list
        $par  = isset($o['line']) ? (string) $o['line'] : implode(' · ', array_map(fn ($p) => $p['name'], $r['parishes']));
        $parQ = self::fold(implode(' ', array_map(fn ($p) => $p['name'] . ' ' . $p['city'], $r['parishes'])));

        $acts = '';
        if ($r['email'] !== '') {
            // The address is base64-encoded and written in by the script, so it never appears in the HTML
            // (keeps it away from harvesters and from Joomla's email cloaking, which would rewrite the links).
            $enc = base64_encode($r['email']);
            $acts .= '<span class="mcd-ct"><a class="mcd-btn mcd-red" href="#" data-e="' . $enc . '">' . $mail . ' ' . $e(Text::_('MIT_DIR_EMAIL')) . '</a>'
                . '<span class="mcd-pop"><a href="#" data-e="' . $enc . '" data-t="1"></a><button type="button" data-e="' . $enc . '">' . $e($copy) . '</button></span></span>';
        }
        if ($r['phone'] !== '') {
            $tel = preg_replace('/[^0-9+]/', '', $r['phone']);
            // Same treatment as e-mail: the number and tel: link are written in by the script and only shown in the pop-up.
            $encP = base64_encode($r['phone']);
            $encH = base64_encode('tel:' . $tel);
            $acts .= '<span class="mcd-ct"><a class="mcd-btn mcd-line" href="#" data-u="' . $encH . '">' . $phone . ' ' . $e(Text::_('MIT_DIR_CALL')) . '</a>'
                . '<span class="mcd-pop"><a href="#" data-e="' . $encP . '" data-u="' . $encH . '" data-t="1"></a><button type="button" data-e="' . $encP . '">' . $e($copy) . '</button></span></span>';
        }

        if (!$o['contact']) {
            $acts = '';
        }
        $nm = '<div class="mcd-nm">' . (!$o['photo'] ? $badge : '') . ($r['title'] !== '' ? '<div class="mcd-rk">' . $e($r['title']) . '</div>' : '') . '<h3>' . $e($r['name']) . '</h3></div>';

        return '<div class="mcd-card" data-d="' . $e($r['diocese']) . '" data-g="' . $e($r['group']) . '" data-l="' . $e($r['letter']) . '"'
            . ' data-name="' . $e(self::fold($r['name'])) . '" data-par="' . $e($parQ) . '">'
            . ($o['photo'] ? '<div class="mcd-photo">' . $photo . $badge . '</div>' : '')
            . $nm
            . ($o['parish'] && $par !== '' ? '<div class="mcd-par">' . $e($par) . '</div>' : '')
            . ($acts !== '' ? '<div class="mcd-acts">' . $acts . '</div>' : '')
            . '</div>';
    }

    /**
     * The clergy directory card for chosen clergy (used on parish pages), with the
     * directory's own styles and script, so both places always look the same.
     * @param array $list [['id' => clergy article id, 'line' => text under the name], ...] in display order
     */
    public static function cardsFor(array $list): string
    {
        $byId = [];
        foreach (self::rows() as $r) {
            $byId[$r['id']] = $r;
        }
        // Hierarchs first, then priests, then deacons; within a group the given order is kept (parish priest before attached priests)
        $found = [];
        foreach (array_values($list) as $i => $c) {
            $r = $byId[(int) ($c['id'] ?? 0)] ?? null;
            if ($r) {
                $found[] = [self::GROUP_ORDER[$r['group']] ?? 9, $i, $r, (string) ($c['line'] ?? '')];
            }
        }
        usort($found, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $cards = '';
        foreach ($found as $f) {
            $cards .= self::card($f[2], ['line' => $f[3]]);
        }
        if ($cards === '') {
            return '';
        }
        return '<div class="mcd mcd-inpage" data-mcd="mcdp' . substr(md5($cards), 0, 6) . '" style="--mcd-cols:2;--mcd-cols-l:2;--mcd-cols-m:2;--mcd-cols-s:1">'
            . '<div class="mcd-grid">' . $cards . '</div></div>' . self::assets();
    }

    /** Element option as boolean; missing means the default. */
    private static function on(array $props, string $key, bool $default = true): bool
    {
        return array_key_exists($key, $props) && $props[$key] !== null && $props[$key] !== '' ? (bool) $props[$key] : $default;
    }

    /** Inline CSS variables from the element's Layout and Style options. */
    private static function vars(array $props): string
    {
        $v = [];
        foreach (['columns' => 'cols', 'columns_l' => 'cols-l', 'columns_m' => 'cols-m', 'columns_s' => 'cols-s'] as $k => $n) {
            $c = (int) ($props[$k] ?? 0);
            if ($c >= 1 && $c <= 6) {
                $v[] = '--mcd-' . $n . ':' . $c;
            }
        }
        $gaps = ['small' => '12px', 'large' => '28px'];
        if (isset($gaps[$props['gap'] ?? ''])) {
            $v[] = '--mcd-gap:' . $gaps[$props['gap']];
        }
        if (in_array($props['photo_ratio'] ?? '', ['1/1', '4/5', '3/4', '4/3'], true)) {
            $v[] = '--mcd-ratio:' . $props['photo_ratio'];
        }
        foreach (['color_primary' => 'primary', 'color_accent' => 'accent', 'color_deep' => 'deep', 'color_gold' => 'gold', 'color_line' => 'line'] as $k => $n) {
            $c = trim((string) ($props[$k] ?? ''));
            if ($c !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|(rgb|hsl)a?\([0-9.,%\s\/-]+\)|[a-zA-Z]+)$/', $c)) {
                $v[] = '--mcd-' . $n . ':' . $c;
                if ($n === 'accent') {
                    $v[] = '--mcd-accent-hover:' . $c;
                }
            }
        }
        return $v ? ' style="' . implode(';', $v) . '"' : '';
    }

    private static function html(array $rows, array $props): string
    {
        $e       = [self::class, 'e'];
        $order   = ($props['order'] ?? 'az') === 'group' ? 'group' : 'az';
        $only    = (string) ($props['diocese'] ?? '');
        $filters = self::on($props, 'show_filters');
        $showAz  = self::on($props, 'show_az');
        $showCnt = self::on($props, 'show_count');
        $co      = ['photo' => self::on($props, 'show_photo'), 'parish' => self::on($props, 'show_parish'), 'contact' => self::on($props, 'show_contact')];
        if ($only !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['diocese'] === $only || $r['diocese'] === ''));
        }
        $uid = 'mcd' . substr(md5(json_encode($props) . count($rows)), 0, 6);

        $body = '';
        if ($order === 'group') {
            $groups = [];
            foreach ($rows as $r) {
                $groups[$r['group']][] = $r;
            }
            foreach (array_keys(self::GROUP_ORDER) as $g) {
                if (empty($groups[$g])) {
                    continue;
                }
                $body .= '<h2 class="mcd-h" data-h="' . $g . '">' . $e(Text::_('MIT_O_CLERGY_GROUP_' . strtoupper($g))) . '</h2>';
                foreach ($groups[$g] as $r) {
                    $body .= self::card($r, $co);
                }
            }
        } else {
            $cur = null;
            foreach ($rows as $r) {
                if ($r['letter'] !== $cur) {
                    $cur = $r['letter'];
                    $body .= '<h2 class="mcd-h" data-h="' . $e($cur) . '" id="' . $uid . '-' . $e($cur) . '">' . $e($cur) . '</h2>';
                }
                $body .= self::card($r, $co);
            }
        }

        $letters = array_values(array_unique(array_column($rows, 'letter')));
        sort($letters);
        $az = '';
        if ($order === 'az' && $showAz) {
            foreach ($letters as $L) {
                $az .= '<button type="button" data-az="' . $e($L) . '" aria-pressed="false">' . $e($L) . '</button>';
            }
        }

        $filtersHtml = $chipsHtml = $barHtml = '';
        if ($filters) {
            $dio = '';
            if ($only === '') {
                $dio = '<select class="mcd-sel" data-f="d" aria-label="' . $e(Text::_('MIT_DIR_DIOCESE')) . '">'
                    . '<option value="">' . $e(Text::_('MIT_DIR_DIOCESE_ALL')) . '</option>'
                    . '<option value="usa">' . $e(Text::_('MIT_DIR_DIOCESE_USA')) . '</option>'
                    . '<option value="canada">' . $e(Text::_('MIT_DIR_DIOCESE_CANADA')) . '</option>'
                    . '<option value="sa">' . $e(Text::_('MIT_DIR_DIOCESE_SA')) . '</option></select>';
            }
            // A chip only for groups that have clergy; no chip row at all when there is just one group.
            $present = array_unique(array_column($rows, 'group'));
            $chips = '<button type="button" class="mcd-chip on" data-g="">' . $e(Text::_('MIT_DIR_ALL')) . '</button>';
            foreach (array_keys(self::GROUP_ORDER) as $g) {
                if (!in_array($g, $present, true)) {
                    continue;
                }
                $chips .= '<button type="button" class="mcd-chip" data-g="' . $g . '">' . $e(Text::_('MIT_O_CLERGY_GROUP_' . strtoupper($g))) . '</button>';
            }
            $filtersHtml = '<div class="mcd-controls">'
                . '<label class="mcd-search"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>'
                . '<input type="search" data-f="q" placeholder="' . $e(Text::_('MIT_DIR_SEARCH_NAME')) . '" aria-label="' . $e(Text::_('MIT_DIR_SEARCH_LABEL')) . '"'
                . ' data-ph-name="' . $e(Text::_('MIT_DIR_SEARCH_NAME')) . '" data-ph-par="' . $e(Text::_('MIT_DIR_SEARCH_PARISH')) . '"></label>'
                . '<span class="mcd-seg" role="group"><button type="button" class="on" data-mode="name">' . $e(Text::_('MIT_DIR_BY_NAME')) . '</button><button type="button" data-mode="par">' . $e(Text::_('MIT_DIR_BY_PARISH')) . '</button></span>'
                . $dio
                . '<button type="button" class="mcd-reset" hidden>'
                . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg> '
                . $e(Text::_('MIT_DIR_RESET')) . '</button></div>'
                ;
            $chipsHtml = count($present) > 1 ? '<div class="mcd-chips">' . $chips . '</div>' : '';
            $barHtml = '<div class="mcd-bar"><div class="mcd-az" role="group" aria-label="' . $e(Text::_('MIT_DIR_JUMP')) . '">' . $az . '</div><span class="mcd-count"' . ($showCnt ? '' : ' hidden') . ' data-c1="' . $e(Text::_('MIT_DIR_COUNT_ONE')) . '" data-cn="' . $e(Text::_('MIT_DIR_COUNT')) . '" data-cm="' . $e(Text::_('MIT_DIR_COUNT_MANY')) . '"></span></div>';
        }

        $cls = trim('mcd' . (!$co['photo'] ? ' mcd-nophoto' : '') . (($props['card_align'] ?? '') === 'left' ? ' mcd-left' : '') . ' ' . (string) ($props['class'] ?? ''));
        $idAttr = !empty($props['id']) ? ' id="' . $e((string) $props['id']) . '"' : '';

        $top = $filtersHtml . $chipsHtml;
        if (self::on($props, 'show_hero')) {
            $top = '<div class="mcd-hero"><div class="mcd-hero-in">'
                . (self::on($props, 'show_breadcrumb') ? self::crumbs() : '')
                . '<div class="mcd-hero-row"><div class="mcd-hero-text">'
                . '<h1 class="mcd-title">' . $e(self::title($props)) . '</h1>'
                . (($intro = self::intro($props)) !== '' ? '<p class="mcd-intro">' . $e($intro) . '</p>' : '')
                . '</div>' . $filtersHtml . '</div>'
                . $chipsHtml
                . '</div></div>';
        }

        return '<div class="' . $e($cls) . ($top !== $filtersHtml . $chipsHtml ? ' mcd-has-hero' : '') . '"' . $idAttr . self::vars($props) . ' data-mcd="' . $uid . '">'
            . $top . $barHtml
            . '<div class="mcd-grid">' . $body . '</div>'
            . '<p class="mcd-none" hidden>' . $e(Text::_('MIT_DIR_NONE')) . '</p>'
            . '</div>'
            . self::assets();
    }

    /** Page title: the menu item's Page Heading if set, otherwise its title. */
    private static function title(array $props): string
    {
        $t = trim((string) ($props['title'] ?? ''));
        if ($t !== '') {
            return $t;
        }
        $item = Factory::getApplication()->getMenu()->getActive();
        if ($item) {
            $h = trim((string) $item->getParams()->get('page_heading', ''));
            return $h !== '' ? $h : (string) $item->title;
        }
        return Text::_('MIT_DIR_TITLE');
    }

    /** Introduction: element option, otherwise the translatable MIT_DIR_INTRO text. */
    private static function intro(array $props): string
    {
        $t = trim((string) ($props['intro'] ?? ''));
        if ($t !== '') {
            return $t;
        }
        $lang = Factory::getApplication()->getLanguage();
        return $lang->hasKey('MIT_DIR_INTRO') ? Text::_('MIT_DIR_INTRO') : '';
    }

    /** Breadcrumb from Joomla's pathway, starting at this language's home page. */
    private static function crumbs(): string
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
            if ($name === '') {
                continue;
            }
            $link = (string) ($p->link ?? '');
            $parts[] = ($i < $n - 1 && $link !== '' && $link !== '#')
                ? '<a href="' . $e(Route::_($link)) . '">' . $e($name) . '</a>'
                : '<span' . ($i === $n - 1 ? ' aria-current="page"' : '') . '>' . $e($name) . '</span>';
        }
        return '<nav class="mcd-crumbs" aria-label="' . $e(Text::_('MIT_DIR_BREADCRUMB')) . '">'
            . implode('<span class="mcd-sep" aria-hidden="true">›</span>', $parts) . '</nav>';
    }

    /** CSS and JS, printed once per page. */
    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        $copied = htmlspecialchars(Text::_('MIT_DIR_COPIED'), ENT_QUOTES, 'UTF-8');
        $copy   = htmlspecialchars(Text::_('MIT_DIR_COPY'), ENT_QUOTES, 'UTF-8');
        return <<<HTML
<style>
/* Layout and behaviour only. Colors, fonts and borders are in the theme: Settings > Custom Code > CSS/LESS, section "Clergy directory". */
.mcd-controls{display:flex;gap:8px;flex-wrap:wrap}
.mcd-hero{position:relative;box-shadow:0 0 0 100vmax var(--mcd-hero-bg,#EFE3CB);clip-path:inset(0 -100vmax);background:var(--mcd-hero-bg,#EFE3CB)}
.mcd-hero-in{padding:22px 0 40px}
.mcd-crumbs{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.mcd-hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:32px;flex-wrap:wrap;margin-top:34px}
.mcd-hero-text{max-width:680px}
.mcd-hero .mcd-chips{margin-top:26px}
.mcd-has-hero .mcd-bar{margin-top:32px}
.mcd-search{position:relative;display:inline-flex}
.mcd-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%)}
.mcd-search input{padding:0 14px 0 42px;font-family:inherit;width:240px;box-sizing:border-box}
.mcd-seg{display:inline-flex;overflow:hidden;height:46px}
.mcd-seg button{font-family:inherit;border:0;padding:0 14px;cursor:pointer}
.mcd-sel{padding:0 14px;font-family:inherit}
.mcd-reset{height:46px;display:inline-flex;align-items:center;gap:6px;font-family:inherit;padding:0 12px;cursor:pointer}
.mcd-reset[hidden],.mcd-count[hidden],.mcd-chips[hidden],.mcd-chip[hidden]{display:none}
.mcd-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
.mcd-chip{font-family:inherit;padding:6px 16px;cursor:pointer}
.mcd-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-top:24px}
.mcd-az{display:flex;flex-wrap:wrap;gap:4px}
.mcd-az button{font-family:inherit;cursor:pointer;padding:0;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center}
.mcd-grid{display:grid;grid-template-columns:repeat(var(--mcd-cols,4),minmax(0,1fr));gap:var(--mcd-gap,18px);margin-top:8px}
.mcd-h{grid-column:1/-1;display:flex;align-items:center;gap:14px}
.mcd-h::after{content:"";flex:1;height:1px}
.mcd-card{display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;padding:0 18px 18px;box-sizing:border-box}
.mcd-left .mcd-card{align-items:stretch;text-align:left}
.mcd-nophoto .mcd-card{padding-top:18px}
.mcd-photo{position:relative;width:calc(100% + 36px);margin:0 -18px 4px;aspect-ratio:var(--mcd-ratio,5/4);overflow:hidden;display:flex;align-items:center;justify-content:center;box-sizing:border-box}
.mcd-photo img{width:100%;height:100%;object-fit:cover;object-position:50% 25%}
.mcd-badge{position:absolute;left:10px;bottom:10px;padding:3px 7px}
.mcd-nophoto .mcd-badge{position:static;display:inline-block;margin-bottom:6px}
.mcd-card h3{overflow-wrap:anywhere}
.mcd-par{width:100%;padding-top:10px}
.mcd-acts{width:100%;margin-top:auto;display:flex;gap:8px}
.mcd-ct{flex:1;position:relative;display:flex}
.mcd-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 12px;white-space:nowrap}
.mcd-btn,.mcd-btn:hover{text-decoration:none}
.mcd .mcd-pop{display:block;top:auto;right:auto;margin:0;position:absolute;left:50%;bottom:calc(100% + 8px);transform:translate(-50%,4px);width:max-content;min-width:100%;max-width:min(300px,90vw);padding:10px 12px;opacity:0;visibility:hidden;transition:opacity .15s,transform .15s;z-index:5;text-align:left;box-sizing:border-box}
.mcd .mcd-pop::after{content:"";position:absolute;left:50%;top:100%;margin-left:-6px;border-style:solid;border-width:6px 6px 0;border-left-color:transparent;border-right-color:transparent}
.mcd .mcd-ct:hover .mcd-pop,.mcd .mcd-ct:focus-within .mcd-pop,.mcd .mcd-ct.open .mcd-pop{opacity:1;visibility:visible;transform:translate(-50%,0)}
.mcd .mcd-pop a{position:static;display:block;background:none;padding:0;white-space:nowrap;text-decoration:none;margin-bottom:8px}
.mcd .mcd-pop a:hover{text-decoration:underline}
.mcd .mcd-pop button{display:inline-block;position:static;font-family:inherit;border:0;padding:5px 10px;cursor:pointer}
.mcd-none{text-align:center;padding:40px 0}
@media (max-width:1100px){.mcd-grid{grid-template-columns:repeat(var(--mcd-cols-l,3),minmax(0,1fr))}}
@media (max-width:860px){.mcd-grid{grid-template-columns:repeat(var(--mcd-cols-m,2),minmax(0,1fr))}}
@media (max-width:560px){.mcd-grid{grid-template-columns:repeat(var(--mcd-cols-s,1),minmax(0,1fr))}.mcd-search,.mcd-search input{width:100%}}
</style>
<script>
(function(){
function fold(s){return s.normalize('NFD').replace(/[̀-ͯ]/g,'').toUpperCase();}
document.querySelectorAll('[data-mcd]').forEach(function(root){
 var q=root.querySelector('[data-f=q]'),d=root.querySelector('[data-f=d]'),count=root.querySelector('.mcd-count'),none=root.querySelector('.mcd-none'),mode='name',grp='',lt='',rs=root.querySelector('.mcd-reset');
 function apply(){var t=q?fold(q.value.trim()):'',dv=d?d.value:'',n=0,shown={};
  var av={},cards=root.querySelectorAll('.mcd-card');
  cards.forEach(function(c){var base=(!grp||c.dataset.g===grp)&&(!dv||c.dataset.d===dv||c.dataset.d==='')&&(!t||(c.dataset[mode]||'').indexOf(t)>-1);if(base)av[c.dataset.l]=1;c._b=base;});
  if(lt&&!av[lt])lt='';
  cards.forEach(function(c){var ok=c._b&&(!lt||c.dataset.l===lt);c.style.display=ok?'':'none';if(ok){n++;shown[c.dataset.l]=1;shown[c.dataset.g]=1;}});
  root.querySelectorAll('.mcd-h[data-h]').forEach(function(h){h.style.display=shown[h.dataset.h]?'':'none';});
  root.querySelectorAll('[data-az]').forEach(function(a){a.style.display=av[a.dataset.az]?'':'none';var on=a.dataset.az===lt;a.classList.toggle('on',on);a.setAttribute('aria-pressed',on?'true':'false');});
  if(count){var r=n%100,tp=n===1?count.dataset.c1:((r>=20||(r===0&&n>0))?count.dataset.cm:count.dataset.cn);count.textContent=(tp||'%d').replace('%d',n);}
  var gs={};root.querySelectorAll('.mcd-card').forEach(function(c){if(!dv||c.dataset.d===dv||c.dataset.d==='')gs[c.dataset.g]=1;});
  var chips=root.querySelectorAll('.mcd-chip[data-g]:not([data-g=""])'),vis=0;chips.forEach(function(b){var on=!!gs[b.dataset.g];b.hidden=!on;if(on)vis++;});
  var row=root.querySelector('.mcd-chips');if(row)row.hidden=vis<2;
  if(grp&&!gs[grp]){grp='';root.querySelectorAll('.mcd-chip').forEach(function(x){x.classList.toggle('on',x.dataset.g==='');});return apply();}
  if(none)none.hidden=n>0;
  if(rs)rs.hidden=!(t||dv||grp||lt||mode!=='name');}
 root.querySelectorAll('[data-mode]').forEach(function(b){b.addEventListener('click',function(){mode=b.dataset.mode;root.querySelectorAll('[data-mode]').forEach(function(x){x.classList.toggle('on',x===b);});if(q)q.placeholder=mode==='name'?q.dataset.phName:q.dataset.phPar;apply();});});
 root.querySelectorAll('.mcd-chip').forEach(function(b){b.addEventListener('click',function(){grp=b.dataset.g;root.querySelectorAll('.mcd-chip').forEach(function(x){x.classList.toggle('on',x===b);});apply();});});
 root.querySelectorAll('[data-az]').forEach(function(a){a.addEventListener('click',function(){lt=(lt===a.dataset.az)?'':a.dataset.az;apply();});});
 if(rs)rs.addEventListener('click',function(){lt='';if(q)q.value='';if(d)d.value='';var nb=root.querySelector('[data-mode=name]');if(nb)nb.click();var ab=root.querySelector('.mcd-chip[data-g=""]');if(ab)ab.click();apply();if(q)q.focus();});
 if(q)q.addEventListener('input',apply);if(d)d.addEventListener('change',apply);
 root.querySelectorAll('[data-e]').forEach(function(el){var v;try{v=decodeURIComponent(escape(atob(el.dataset.e)));}catch(x){return;}if(el.tagName==='BUTTON'){el.dataset.copy=v;}else{el.href=el.dataset.u?decodeURIComponent(escape(atob(el.dataset.u))):'mailto:'+v;if(el.dataset.t)el.textContent=v;}});
 root.querySelectorAll('a[data-u]:not([data-e])').forEach(function(el){try{el.href=decodeURIComponent(escape(atob(el.dataset.u)));}catch(x){}});
 document.addEventListener('click',function(e){if(!e.target.closest||!e.target.closest('.mcd-ct'))root.querySelectorAll('.mcd-ct.open').forEach(function(x){x.classList.remove('open');});});
 root.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();if(navigator.clipboard)navigator.clipboard.writeText(b.dataset.copy);b.textContent='{$copied}';b.classList.add('done');setTimeout(function(){b.textContent='{$copy}';b.classList.remove('done');},1500);});});
 root.querySelectorAll('.mcd-ct>.mcd-btn').forEach(function(a){a.addEventListener('click',function(e){if(window.matchMedia('(hover:none)').matches){var ct=a.parentNode;if(!ct.classList.contains('open')){e.preventDefault();root.querySelectorAll('.mcd-ct.open').forEach(function(x){x.classList.remove('open');});ct.classList.add('open');}}});});
 apply();
});
})();
</script>
HTML;
    }
}
