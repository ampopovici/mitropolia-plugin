<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;

/**
 * Pastoral letters list and Pastoral letter page ("Pastoral letters" and "Pastoral letter" builder elements).
 *
 * Letters live in one sub-category per hierarch under Pastoral Letters (RO/EN/ES).
 * The hierarch shown on a letter (portrait, form of address, title line) comes from the hierarch's own article
 * in Hierarchs (or Former Hierarchs) of the same language, matched by alias: the article alias is the same as
 * the letter sub-category alias (e.g. both "mitropolitul-nicolae"). Without such an article the sub-category
 * title and image are used.
 * Fields on the letter: pastoral-type (blue/red chip), pastoral-feast (red label), pastoral-pdf (Download PDF).
 */
final class Pastorals
{
    private const PER_PAGE = 12;
    private const TYPES = ['pastoral-letter', 'meditation', 'message', 'homily'];

    private const I_FB = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8h3V4h-3c-2.8 0-4 1.7-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8.5c0-.3.2-.5.5-.5z"/></svg>';
    private const I_MAIL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>';
    private const I_LINK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>';
    private const I_PRINT = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>';
    private const I_DL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 20h16"/></svg>';

    /* ------------------------------------------------------------------ helpers */

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

    private static function lc(string $s): string
    {
        return in_array(self::lang(), ['ro', 'es'], true) ? mb_strtolower($s, 'UTF-8') : $s;
    }

    private static function date(?string $sql, string $fmt): string
    {
        return $sql ? self::lc(HTMLHelper::_('date', $sql, $fmt)) : '';
    }

    private static function plain(string $html): string
    {
        $t = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $t)));
    }

    private static function clip(string $t, int $max): string
    {
        if (mb_strlen($t) <= $max) {
            return $t;
        }
        $cut = mb_substr($t, 0, $max - 1);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:–-") . '…';
    }

    private static function minutes(string $plain): int
    {
        return max(1, (int) round(count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY)) / 220));
    }

    private static function url(object $a): string
    {
        return Route::_(RouteHelper::getArticleRoute($a->id . ':' . $a->alias, (int) $a->catid, $a->language));
    }

    /** Category row by id (cached). */
    private static function cat(int $id): ?object
    {
        static $c = [];
        if (!array_key_exists($id, $c)) {
            $db = self::db();
            $c[$id] = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias', 'parent_id', 'lft', 'rgt', 'params', 'description', 'language'])
                ->from('#__categories')->where('id = ' . $id)->where('published = 1'))->loadObject() ?: null;
        }
        return $c[$id];
    }

    /** The category and all published categories below it. */
    private static function tree(int $id): array
    {
        $c = self::cat($id);
        if (!$c) {
            return [];
        }
        $db = self::db();
        return array_map('intval', $db->setQuery($db->getQuery(true)->select('id')->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))->where('published = 1')
            ->where('lft >= ' . (int) $c->lft)->where('rgt <= ' . (int) $c->rgt))->loadColumn());
    }

    private static function children(int $id): array
    {
        $db = self::db();
        return $db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias'])->from('#__categories')
            ->where('parent_id = ' . $id)->where('published = 1')->where('extension = ' . $db->quote('com_content'))
            ->order('lft'))->loadObjectList();
    }

    private static function base(array $cats)
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());
        return $db->getQuery(true)->from($db->quoteName('#__content', 'a'))
            ->whereIn('a.catid', $cats ?: [0])
            ->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels ?: [1]);
    }

    private static function cols(bool $text = false): array
    {
        $c = ['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.publish_up', 'a.introtext', 'a.images'];
        if ($text) {
            $c[] = 'a.fulltext';
        }
        return $c;
    }

    /** Field values for articles: [item id => [field name => value]]. */
    private static function fields(array $ids, array $names): array
    {
        if (!$ids) {
            return [];
        }
        $db = self::db();
        $q = $db->getQuery(true)->select(['v.item_id', 'f.name', 'v.value'])
            ->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->whereIn('f.name', $names, \Joomla\Database\ParameterType::STRING)
            ->whereIn('v.item_id', array_map('strval', $ids), \Joomla\Database\ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->item_id][$r->name] = trim((string) $r->value);
        }
        return $out;
    }

    private static function typeLabel(string $v, bool $plural = false): string
    {
        $k = 'MIT_PL_TYPE' . ($plural ? 'S_' : '_') . strtoupper(str_replace('-', '_', $v));
        $t = Text::_($k);
        if ($t !== $k) {
            return $t;
        }
        $k = 'MIT_O_PASTORAL_TYPE_' . strtoupper(str_replace('-', '_', $v));
        $t = Text::_($k);
        return $t !== $k ? $t : $v;
    }

    private static function typeClass(string $v): string
    {
        return in_array($v, self::TYPES, true) ? 'mpl-t-' . $v : 'mpl-t-message';
    }

    private static function chip(string $type, string $feast): string
    {
        $h = '';
        if ($type !== '') {
            $h .= '<span class="mpl-type ' . self::typeClass($type) . '">' . self::e(self::typeLabel($type)) . '</span>';
        }
        if ($feast !== '') {
            $h .= '<span class="mpl-feast">' . self::e($feast) . '</span>';
        }
        return $h;
    }

    private static function src(string $path): string
    {
        $path = trim(explode('#', $path, 2)[0]);
        if ($path === '') {
            return '';
        }
        return preg_match('#^https?://#i', $path) ? $path : Uri::root(true) . '/' . ltrim($path, '/');
    }

    /** Hierarch shown for a letter sub-category: name, see/title lines, portrait, signing name. */
    private static function hierarch(int $catid): array
    {
        static $cache = [];
        if (isset($cache[$catid])) {
            return $cache[$catid];
        }
        $cat = self::cat($catid);
        $h = ['name' => $cat ? (string) $cat->title : '', 'see' => '', 'full' => '', 'img' => '', 'sign' => ''];
        if (!$cat) {
            return $cache[$catid] = $h;
        }
        // signing name: the category title without its first word (rank), in capitals: "Mitropolitul Nicolae" -> NICOLAE
        $words = preg_split('/\s+/u', trim((string) $cat->title));
        $h['sign'] = mb_strtoupper(count($words) > 1 ? implode(' ', array_slice($words, 1)) : (string) $cat->title, 'UTF-8');
        $p = json_decode((string) $cat->params, true) ?: [];
        if (!empty($p['image'])) {
            $h['img'] = self::src((string) $p['image']);
        }
        try {
            $db = self::db();
            $alias = (string) $cat->alias;
            $q = $db->getQuery(true)->select(['a.id', 'a.title', 'a.images'])
                ->from($db->quoteName('#__content', 'a'))
                ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
                ->where('a.alias = :al')->where('a.state = 1')
                ->where('(c.alias LIKE ' . $db->quote('hierarchs-%') . ' OR c.alias LIKE ' . $db->quote('former-hierarchs-%') . ')')
                ->bind(':al', $alias);
            $a = $db->setQuery($q, 0, 1)->loadObject();
            if (!$a && count($words) > 1) {
                // no alias match: the hierarch article of this language whose title holds the name ("Nicolae", "Casian")
                $key = (string) end($words);
                $lang = (string) $cat->language;
                $q2 = $db->getQuery(true)->select(['a.id', 'a.title', 'a.images'])
                    ->from($db->quoteName('#__content', 'a'))
                    ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
                    ->where('a.state = 1')->where('c.alias LIKE ' . $db->quote('hierarchs-%'))
                    ->where('a.title LIKE ' . $db->quote('%' . $db->escape($key, true) . '%', false));
                if ($lang !== '' && $lang !== '*') {
                    $q2->where('a.language = ' . $db->quote($lang));
                }
                $a = $db->setQuery($q2, 0, 1)->loadObject();
            }
            if ($a) {
                $h['name'] = (string) $a->title;
                $im = json_decode((string) $a->images, true) ?: [];
                $img = self::src((string) ($im['image_intro'] ?? '')) ?: self::src((string) ($im['image_fulltext'] ?? ''));
                if ($img !== '') {
                    $h['img'] = $img;
                }
                $f = self::fields([(int) $a->id], ['hierarch-full-title', 'hierarch-see'])[(int) $a->id] ?? [];
                $h['full'] = (string) ($f['hierarch-full-title'] ?? '');
                $h['see'] = (string) ($f['hierarch-see'] ?? '');
            }
        } catch (\Throwable $x) {
        }
        return $cache[$catid] = $h;
    }

    /**
     * The title a hierarch held on a given date. Metropolitan Nicolae was Archbishop until October 2016:
     * "America and Canada" until mid-2006, "the Americas" until his elevation. Name and title lines come from language keys.
     */
    private static function era(array $h, $date): array
    {
        if ($h['sign'] !== 'NICOLAE' || !$date) {
            return $h;
        }
        $d = substr((string) $date, 0, 10);
        if ($d >= '2016-10-01') {
            return $h;
        }
        $key = $d < '2006-07-01' ? 'MIT_PL_ERA_2006' : 'MIT_PL_ERA_2016';
        $full = Text::_($key);
        if ($full !== $key) {
            $h['full'] = str_replace('\\n', "\n", $full);
            $h['see'] = '';
        }
        $h['name'] = (string) preg_replace(['/\bMetropolitan\b/u', '/\bMitropolit(ul)?\b/u', '/\bMetropolitano\b/u'], ['Archbishop', 'Arhiepiscop$1', 'Arzobispo'], $h['name']);
        return $h;
    }

    /** "His Eminence Metropolitan Nicolae" -> "His Eminence<br>Metropolitan Nicolae" (break before the sub-category title). */
    private static function nameBr(string $name, int $catid): string
    {
        $t = (string) (self::cat($catid)->title ?? '');
        $pos = $t !== '' ? mb_stripos($name, $t) : false;
        if ($pos) {
            return self::e(rtrim(mb_substr($name, 0, $pos))) . '<br>' . self::e(mb_substr($name, $pos));
        }
        return self::e($name);
    }

    /** Top category of a category (the one right under the root). */
    private static function top(int $id): ?object
    {
        $c = self::cat($id);
        $guard = 0;
        while ($c && (int) $c->parent_id > 1 && $guard++ < 10) {
            $p = self::cat((int) $c->parent_id);
            if (!$p) {
                break;
            }
            $c = $p;
        }
        return $c;
    }

    /** True for the Words and Messages section (categories words-of-wisdom-ro/en/es and below). */
    private static function isWords(int $id): bool
    {
        $t = self::top($id);
        return $t && strpos((string) $t->alias, 'words-of-wisdom') === 0;
    }

    /** Menu link for a category (its own menu item, else its parent's, else the category route). */
    private static function catUrl(int $cat): string
    {
        static $urls = [];
        if (isset($urls[$cat])) {
            return $urls[$cat];
        }
        $items = Factory::getApplication()->getMenu()->getItems(['component'], ['com_content']);
        foreach ([$cat, (int) (self::cat($cat)->parent_id ?? 0)] as $want) {
            foreach ($items as $item) {
                $q = $item->query ?? [];
                if (($q['view'] ?? '') === 'category' && (int) ($q['id'] ?? 0) === $want) {
                    return $urls[$cat] = Route::_('index.php?Itemid=' . (int) $item->id);
                }
            }
        }
        return $urls[$cat] = Route::_(RouteHelper::getCategoryRoute($cat, Factory::getApplication()->getLanguage()->getTag()));
    }

    /** The menu link of a category, or '' when no menu item points at it. */
    private static function menuUrl(int $cat): string
    {
        foreach (Factory::getApplication()->getMenu()->getItems(['component'], ['com_content']) as $item) {
            $q = $item->query ?? [];
            if (($q['view'] ?? '') === 'category' && (int) ($q['id'] ?? 0) === $cat) {
                return Route::_('index.php?Itemid=' . (int) $item->id);
            }
        }
        return '';
    }

    /** Link for the whole section: its own menu item, else the hierarch's (no /component/ addresses). */
    private static function sectionUrl(int $cat): string
    {
        $c = self::cat($cat);
        $parent = $c ? (int) $c->parent_id : 0;
        if ($parent > 1) {
            $u = self::menuUrl($parent);
            if ($u !== '') {
                return $u;
            }
        }
        return self::catUrl($cat);
    }

    private static function homeCrumb(): string
    {
        $app = Factory::getApplication();
        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        return $home ? '<a href="' . self::e(Route::_('index.php?Itemid=' . (int) $home->id)) . '">' . self::e((string) $home->title) . '</a>' : '';
    }

    private static function crumbs(array $parts): string
    {
        return '<nav class="mpx-crumbs" aria-label="' . self::e(Text::_('MIT_DIR_BREADCRUMB')) . '">'
            . implode('<span aria-hidden="true">›</span>', array_filter($parts)) . '</nav>';
    }

    /* ------------------------------------------------------------------ list */

    public static function listing(array $props): string
    {
        try {
            $in = Factory::getApplication()->getInput();
            $cat = (int) ($props['category_id'] ?? 0);
            if (!$cat && $in->get('option') === 'com_content' && $in->get('view') === 'category') {
                $cat = $in->getInt('id');
            }
            if (!$cat || !self::cat($cat)) {
                return '';
            }
            return self::listHtml($cat, $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Pastoral letters list: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function listingText(array $props): string
    {
        return '';
    }

    private static function listHtml(int $cat, array $props): string
    {
        $e = [self::class, 'e'];
        $db = self::db();
        $in = Factory::getApplication()->getInput();
        $tree = self::tree($cat);
        $kids = self::children($cat);
        $hSel = (int) $in->getInt('h');
        $fSel = trim((string) $in->getString('f'));
        $ySel = (int) $in->getInt('y');
        $tSel = (string) $in->getCmd('t');
        $page = max(1, (int) $in->getInt('p', 1));

        $scope = $tree;
        if ($hSel && in_array($hSel, $tree, true)) {
            $scope = self::tree($hSel);
        }

        // feasts and years present in this list
        $all = $db->setQuery(self::base($tree)->select(['a.id', 'a.publish_up']))->loadObjectList();
        $ids = array_map(fn ($r) => (int) $r->id, $all);
        $fv = self::fields($ids, ['pastoral-type', 'pastoral-feast']);
        $feasts = [];
        $years = [];
        foreach ($all as $r) {
            $f = $fv[(int) $r->id]['pastoral-feast'] ?? '';
            if ($f !== '') {
                $feasts[$f] = true;
            }
            $years[(int) substr((string) $r->publish_up, 0, 4)] = true;
        }
        ksort($feasts, SORT_LOCALE_STRING);
        krsort($years);

        // filtered ids by field values (type, feast)
        $q = self::base($scope)->select(self::cols());
        if ($ySel) {
            $q->where('YEAR(a.publish_up) = ' . $ySel);
        }
        if ($tSel !== '' || $fSel !== '') {
            $keep = [];
            foreach ($ids as $id) {
                $okT = $tSel === '' || ($fv[$id]['pastoral-type'] ?? '') === $tSel;
                $okF = $fSel === '' || ($fv[$id]['pastoral-feast'] ?? '') === $fSel;
                if ($okT && $okF) {
                    $keep[] = $id;
                }
            }
            $q->whereIn('a.id', $keep ?: [0]);
        }
        $total = (int) $db->setQuery((clone $q)->clear('select')->select('COUNT(*)'))->loadResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $db->setQuery($q->order('a.publish_up DESC, a.id DESC'), ($page - 1) * self::PER_PAGE, self::PER_PAGE)->loadObjectList();

        $base = strtok(Uri::getInstance()->toString(['path']), '?');
        $link = function (array $over) use ($base, $hSel, $fSel, $ySel, $tSel) {
            $p = array_merge(['h' => $hSel ?: null, 'f' => $fSel !== '' ? $fSel : null, 'y' => $ySel ?: null, 't' => $tSel !== '' ? $tSel : null], $over);
            $p = array_filter($p, fn ($v) => $v !== null && $v !== '' && $v !== 0);
            return $base . ($p ? '?' . http_build_query($p) : '');
        };

        $catRow = self::cat($cat);
        $isHierarch = !$kids;
        $words = self::isWords($cat);
        $title = Text::_($words ? 'MIT_WM_TITLE' : 'MIT_PL_TITLE');
        // type pills: only the types this section holds
        $present = [];
        foreach ($fv as $v) {
            if (($v['pastoral-type'] ?? '') !== '') {
                $present[$v['pastoral-type']] = true;
            }
        }
        $types = array_values(array_filter(self::TYPES, fn ($t) => isset($present[$t])));
        $crumbs = $isHierarch && (int) $catRow->parent_id > 1
            ? self::crumbs([self::homeCrumb(), '<a href="' . $e(self::sectionUrl($cat)) . '">' . $e($title) . '</a>', '<span aria-current="page">' . $e((string) $catRow->title) . '</span>'])
            : self::crumbs([self::homeCrumb(), '<span aria-current="page">' . $e($title) . '</span>']);

        // filters (GET form: works without script; script submits on change)
        $sel = '';
        if ($kids) {
            $o = '<option value="">' . $e(Text::_('MIT_PL_ALL_HIERARCHS')) . '</option>';
            foreach ($kids as $k) {
                $o .= '<option value="' . (int) $k->id . '"' . ($hSel === (int) $k->id ? ' selected' : '') . '>' . $e((string) $k->title) . '</option>';
            }
            $sel .= '<select class="mpx-sel" name="h" aria-label="' . $e(Text::_('MIT_PL_HIERARCH')) . '">' . $o . '</select>';
        }
        if ($feasts) {
            $o = '<option value="">' . $e(Text::_('MIT_PL_ALL_FEASTS')) . '</option>';
            foreach (array_keys($feasts) as $f) {
                $o .= '<option value="' . $e((string) $f) . '"' . ($fSel === (string) $f ? ' selected' : '') . '>' . $e((string) $f) . '</option>';
            }
            $sel .= '<select class="mpx-sel" name="f" aria-label="' . $e(Text::_('MIT_PL_FEAST')) . '">' . $o . '</select>';
        }
        if (count($years) > 1) {
            $o = '<option value="">' . $e(Text::_('MIT_NEWS_ALL_YEARS')) . '</option>';
            foreach (array_keys($years) as $y) {
                $o .= '<option value="' . (int) $y . '"' . ($ySel === (int) $y ? ' selected' : '') . '>' . (int) $y . '</option>';
            }
            $sel .= '<select class="mpx-sel" name="y" aria-label="' . $e(Text::_('MIT_NEWS_YEAR')) . '">' . $o . '</select>';
        }
        $form = $sel !== '' ? '<form class="mpl-filters" method="get" action="' . $e($base) . '">' . ($tSel !== '' ? '<input type="hidden" name="t" value="' . $e($tSel) . '">' : '') . $sel
            . '<noscript><button class="mnx-go" type="submit">' . $e(Text::_('MIT_NEWS_SEARCH_GO')) . '</button></noscript></form>' : '';

        $chips = '';
        if (count($types) > 1) {
            $chips = '<a class="mpx-chip' . ($tSel === '' ? ' on' : '') . '" href="' . $e($link(['t' => null])) . '">' . $e(Text::_('MIT_NEWS_ALL')) . '</a>';
            foreach ($types as $t) {
                $chips .= '<a class="mpx-chip' . ($tSel === $t ? ' on' : '') . '" href="' . $e($link(['t' => $t])) . '">' . $e(self::typeLabel($t, true)) . '</a>';
            }
        }

        $introText = !empty($props['intro']) ? (string) $props['intro'] : ($isHierarch ? (string) self::hierarch($cat)['name'] : Text::_($words ? 'MIT_WM_INTRO' : 'MIT_PL_INTRO'));
        $head = '<div class="mnx-hero"><div class="mnx-hero-in">' . $crumbs
            . '<div class="mnx-hero-row"><div class="mnx-hero-text"><h1 class="mpx-h1">' . $e(!empty($props['title']) ? (string) $props['title'] : $title) . '</h1>'
            . '<p class="mnx-intro">' . $e($introText) . '</p></div>'
            . $form . '</div>' . ($chips !== '' ? '<div class="mnx-chips">' . $chips . '</div>' : '') . '</div></div>';
        // parts, for pages whose header is built with YOOtheme elements
        $part = (string) ($props['part'] ?? '');
        $cls = !empty($props['class']) ? ' ' . $e((string) $props['class']) : '';
        if ($part === 'intro') {
            return '<div class="mnx mpl mnx-tools' . $cls . '"><p class="mnx-intro">' . $e($introText) . '</p></div>';
        }
        if ($part === 'search') {
            return $form !== '' ? '<div class="mnx mpl mnx-tools' . $cls . '">' . $form . '</div>' : '';
        }
        if ($part === 'chips') {
            return $chips !== '' ? '<div class="mnx mpl mnx-tools' . $cls . '"><div class="mnx-chips">' . $chips . '</div></div>' : '';
        }
        if ($part === 'body') {
            $head = '';
        }

        $fr = self::fields(array_map(fn ($r) => (int) $r->id, $rows), ['pastoral-type', 'pastoral-feast']);
        $list = '';
        if ($words) {
            // Words and Messages: a card grid with photo, type, date and the hierarch
            foreach ($rows as $r) {
                $f = $fr[(int) $r->id] ?? [];
                $list .= News::kindCard($r, self::chip($f['pastoral-type'] ?? '', ''), (string) (self::cat((int) $r->catid)->title ?? ''));
            }
            $rows = [];
        }
        foreach ($rows as $r) {
            $f = $fr[(int) $r->id] ?? [];
            $h = self::era(self::hierarch((int) $r->catid), $r->publish_up);
            $txt = self::plain((string) $r->introtext);
            $list .= '<a class="mpl-row" href="' . $e(self::url($r)) . '">'
                . '<span class="mpl-date"><span class="d">' . $e(self::date($r->publish_up, 'j')) . '</span><span class="my">' . $e(self::date($r->publish_up, 'M Y')) . '</span></span>'
                . '<span class="mpl-body"><span class="mpl-chips">' . self::chip($f['pastoral-type'] ?? '', $f['pastoral-feast'] ?? '') . '</span>'
                . '<h3>' . $e((string) $r->title) . '</h3>'
                . ($txt !== '' ? '<span class="mpl-ex">' . $e(self::clip($txt, 220)) . '</span>' : '') . '</span>'
                . '<span class="mpl-side"><span class="mpl-who">' . self::nameBr($h['name'], (int) $r->catid) . '</span><span>' . $e(Text::sprintf('MIT_NEWS_MIN_READ', self::minutes($txt))) . '</span><span class="mpl-read">' . $e(Text::_('MIT_PL_READ')) . ' →</span></span></a>';
        }
        if ($list === '') {
            $list = '<p class="mnx-empty">' . $e(Text::_($tSel !== '' || $fSel !== '' || $ySel || $hSel ? 'MIT_PL_NO_MATCH' : ($words ? 'MIT_WM_NONE' : 'MIT_PL_NONE'))) . '</p>';
        }

        $pg = '';
        if ($pages > 1) {
            $pg = '<nav class="mnx-pg" aria-label="' . $e(Text::_('MIT_NEWS_PAGES')) . '">';
            if ($page > 1) {
                $pg .= '<a href="' . $e($link(['p' => $page - 1])) . '" aria-label="' . $e(Text::_('MIT_NEWS_PREV_PAGE')) . '">‹</a>';
            }
            $last = 0;
            for ($i = 1; $i <= $pages; $i++) {
                if ($i === 1 || $i === $pages || abs($i - $page) <= 1) {
                    if ($last && $i - $last > 1) {
                        $pg .= '<span class="gap">…</span>';
                    }
                    $pg .= $i === $page ? '<span class="on" aria-current="page">' . $i . '</span>' : '<a href="' . $e($link(['p' => $i])) . '">' . $i . '</a>';
                    $last = $i;
                }
            }
            if ($page < $pages) {
                $pg .= '<a href="' . $e($link(['p' => $page + 1])) . '" aria-label="' . $e(Text::_('MIT_NEWS_NEXT_PAGE')) . '">›</a>';
            }
            $pg .= '</nav>';
        }

        if ($words) {
            return '<div class="mnx mpl mpl-words' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">' . $head
                . '<div class="mpl-listbody">' . (strpos($list, 'mnx-empty') !== false ? $list : '<div class="mnx-grid">' . $list . '</div>') . $pg . '</div></div>' . News::sharedCss();
        }
        return '<div class="mnx mpl' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">' . $head
            . '<div class="mpl-listbody"><div class="mpl-rows">' . $list . '</div>' . $pg . '</div></div>';
    }

    /* ------------------------------------------------------------------ letter */

    public static function article(array $props): string
    {
        try {
            $id = (int) ($props['article_id'] ?? 0);
            if (!$id) {
                $in = Factory::getApplication()->getInput();
                $id = $in->get('option') === 'com_content' && $in->get('view') === 'article' ? $in->getInt('id') : 0;
            }
            if (!$id) {
                return '';
            }
            $db = self::db();
            $a = $db->setQuery($db->getQuery(true)->select(self::cols(true))->from($db->quoteName('#__content', 'a'))
                ->where('a.id = ' . (int) $id)->where('a.state = 1'))->loadObject();
            if (!$a) {
                return '';
            }
            return self::isWords((int) $a->catid) ? self::wordsHtml($a, $props) . News::sharedCss() . self::assets() : self::letterHtml($a, $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Pastoral letter: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function articleText(array $props): string
    {
        return '';
    }

    private static function letterHtml(object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $db = self::db();
        $cat = (int) $a->catid;
        $catRow = self::cat($cat);
        $parent = $catRow ? (int) $catRow->parent_id : 0;
        $h = self::era(self::hierarch($cat), $a->publish_up);
        $f = self::fields([(int) $a->id], ['pastoral-type', 'pastoral-feast', 'pastoral-pdf'])[(int) $a->id] ?? [];

        $text = (string) $a->introtext . (trim((string) $a->fulltext) !== '' ? (string) $a->fulltext : '');
        $plain = self::plain($text);
        try {
            $text = HTMLHelper::_('content.prepare', $text, null, 'com_content.article');
        } catch (\Throwable $x) {
        }

        $pdf = '';
        $pv = (string) ($f['pastoral-pdf'] ?? '');
        if ($pv !== '') {
            $j = json_decode($pv, true);
            $pdf = self::src(is_array($j) ? (string) ($j['file'] ?? $j['url'] ?? $j['imagefile'] ?? '') : $pv);
        }

        $url = Uri::getInstance()->toString(['scheme', 'host', 'port']) . self::url($a);
        $listUrl = self::catUrl($cat);

        $head = '<div class="mnx-band">' . self::crumbs([self::homeCrumb(), '<a href="' . $e(self::sectionUrl($cat)) . '">' . $e(Text::_('MIT_PL_TITLE')) . '</a>',
                $catRow ? '<a href="' . $e($listUrl) . '">' . $e((string) $catRow->title) . '</a>' : '']) . '</div>'
            . '<header class="mpl-head"><div class="mnx-meta">' . self::chip($f['pastoral-type'] ?? '', $f['pastoral-feast'] ?? '')
            . '<span class="dot" aria-hidden="true"></span><time datetime="' . $e(HTMLHelper::_('date', $a->publish_up, 'c')) . '">' . $e(self::date($a->publish_up, Text::_('MIT_NEWS_DATE_FORMAT'))) . '</time>'
            . '<span class="dot" aria-hidden="true"></span><span>' . $e(Text::sprintf('MIT_NEWS_MIN_READ', self::minutes($plain))) . '</span></div>'
            . '<h1 class="mpl-title">' . $e((string) $a->title) . '</h1>'
            . '<div class="mpl-by">' . ($h['img'] !== '' ? '<img src="' . $e($h['img']) . '" alt="" width="56" height="56">' : '')
            . '<span><b>' . $e($h['name']) . '</b>' . (($h['full'] ?: $h['see']) !== '' ? '<span>' . $e(preg_replace('/\s*\R\s*/u', ' · ', $h['full'] ?: $h['see'])) . '</span>' : '') . '</span></div></header>';

        $part = (string) ($props['part'] ?? '');
        if ($part === 'kicker') {
            return '<div class="mnx mpl mnx-tools"><div class="mnx-meta">' . self::chip($f['pastoral-type'] ?? '', $f['pastoral-feast'] ?? '') . '</div></div>';
        }
        if ($part === 'byline') {
            return '<div class="mnx mpl mnx-tools"><div class="mpl-by">' . ($h['img'] !== '' ? '<img src="' . $e($h['img']) . '" alt="" width="56" height="56">' : '')
                . '<span><b>' . $e($h['name']) . '</b>' . (($h['full'] ?: $h['see']) !== '' ? '<span>' . $e(preg_replace('/\s*\R\s*/u', ' · ', $h['full'] ?: $h['see'])) . '</span>' : '') . '</span></div></div>';
        }
        if ($part === 'body') {
            $head = '';
        }

        // The "† NAME / by the mercy of God / title" head belongs to pastoral letters only, not to meditations, homilies or messages
        $letterHead = ($f['pastoral-type'] ?? '') !== 'pastoral-letter' ? '' : '<div class="mpl-lhead"><span class="mpl-who"><span class="mpl-x" aria-hidden="true"></span>' . $e($h['sign']) . '</span>'
            . '<span class="mpl-mercy">' . $e(Text::_('MIT_PL_BY_MERCY')) . '</span>'
            . ($h['full'] !== '' ? '<span class="mpl-full">' . implode('<br>', array_map($e, preg_split('/\s*(?:\R|·|\|)\s*/u', trim($h['full'])))) . '</span>' : '') . '</div>';
        $letter = '<article class="mpl-letter">' . $letterHead . '<div class="mpl-prose">' . $text . '</div></article>';

        // side
        $share = '<div class="mnx-share">'
            . '<a href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url) . '" target="_blank" rel="noopener" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '">' . self::I_FB . '</a>'
            . '<a href="mailto:?subject=' . rawurlencode((string) $a->title) . '&amp;body=' . rawurlencode($url) . '" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '">' . self::I_MAIL . '</a>'
            . '<button type="button" data-copy="' . $e($url) . '" data-done="' . $e(Text::_('MIT_NEWS_COPIED')) . '" aria-label="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '" title="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '">' . self::I_LINK . '</button>'
            . '<button type="button" data-print aria-label="' . $e(Text::_('MIT_NEWS_PRINT')) . '" title="' . $e(Text::_('MIT_NEWS_PRINT')) . '">' . self::I_PRINT . '</button></div>';
        $side = '<div class="mnx-box"><h4>' . $e(Text::_('MIT_NEWS_SHARE')) . '</h4>' . $share . '</div>';
        $side .= '<div class="mnx-box"><h4>' . $e(Text::_('MIT_PL_THIS_LETTER')) . '</h4>'
            . ($pdf !== '' ? '<a class="mpl-act" href="' . $e($pdf) . '" target="_blank" rel="noopener">' . self::I_DL . $e(Text::_('MIT_PL_DOWNLOAD_PDF')) . '</a>' : '')
            . '<button type="button" class="mpl-act" data-print>' . self::I_PRINT . $e(Text::_('MIT_PL_PRINT_VERSION')) . '</button></div>';

        $others = $db->setQuery(self::base([$cat])->select(self::cols())->where('a.id <> ' . (int) $a->id)->order('a.publish_up DESC, a.id DESC'), 0, 3)->loadObjectList();
        if ($others) {
            $of = self::fields(array_map(fn ($r) => (int) $r->id, $others), ['pastoral-type']);
            $side .= '<div class="mnx-box"><h4>' . $e(Text::_('MIT_PL_OTHER')) . '</h4>';
            foreach ($others as $o) {
                $t = $of[(int) $o->id]['pastoral-type'] ?? '';
                $side .= '<a class="mpl-other" href="' . $e(self::url($o)) . '">' . ($t !== '' ? '<span class="mpl-type ' . self::typeClass($t) . '">' . $e(self::typeLabel($t)) . '</span>' : '')
                    . '<b>' . $e((string) $o->title) . '</b><span class="mnx-meta">' . $e(self::date($o->publish_up, Text::_('MIT_NEWS_DATE_FORMAT'))) . '</span></a>';
            }
            $side .= '<a class="mnx-all" href="' . $e($listUrl) . '">' . $e(Text::_('MIT_PL_ALL_LETTERS')) . ' →</a></div>';
        }

        return '<div class="mnx mpl mpl-page' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">' . $head
            . '<div class="mpl-cols"><div class="mpl-main">' . $letter . '</div><aside class="mpl-side-col">' . $side . '</aside></div></div>';
    }

    /* ------------------------------------------------------------------ words and messages */

    /** Meditation, homily or message: a news-style article with the hierarch's byline (no letter sheet). */
    private static function wordsHtml(object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $db = self::db();
        $cat = (int) $a->catid;
        $catRow = self::cat($cat);
        $parent = $catRow ? (int) $catRow->parent_id : 0;
        $tree = self::tree(($parent > 1 && self::cat($parent)) ? $parent : $cat);
        $h = self::era(self::hierarch($cat), $a->publish_up);
        $f = self::fields([(int) $a->id], ['pastoral-type', 'pastoral-feast'])[(int) $a->id] ?? [];
        $type = (string) ($f['pastoral-type'] ?? '');
        $feast = (string) ($f['pastoral-feast'] ?? '');

        $text = (string) $a->introtext . (trim((string) $a->fulltext) !== '' ? (string) $a->fulltext : '');
        $plain = self::plain($text);
        try {
            $text = HTMLHelper::_('content.prepare', $text, null, 'com_content.article');
        } catch (\Throwable $x) {
        }
        $im = json_decode((string) $a->images, true) ?: [];
        $lead = self::src((string) ($im['image_fulltext'] ?? '')) ?: self::src((string) ($im['image_intro'] ?? ''));
        $alt = (string) (($im['image_fulltext'] ?? '') !== '' ? ($im['image_fulltext_alt'] ?? '') : ($im['image_intro_alt'] ?? ''));

        $url = Uri::getInstance()->toString(['scheme', 'host', 'port']) . self::url($a);
        $listUrl = self::catUrl($cat);
        $title = Text::_('MIT_WM_TITLE');

        $head = '<div class="mnx-band">' . self::crumbs([self::homeCrumb(), '<a href="' . $e(self::sectionUrl($cat)) . '">' . $e($title) . '</a>',
                $catRow && $parent > 1 ? '<a href="' . $e($listUrl) . '">' . $e((string) $catRow->title) . '</a>' : '']) . '</div>'
            . '<header class="mnx-head"><div class="mnx-meta">' . self::chip($type, $feast)
            . '<span class="dot" aria-hidden="true"></span><time datetime="' . $e(HTMLHelper::_('date', $a->publish_up, 'c')) . '">' . $e(self::date($a->publish_up, Text::_('MIT_NEWS_DATE_FORMAT'))) . '</time>'
            . '<span class="dot" aria-hidden="true"></span><span>' . $e(Text::sprintf('MIT_NEWS_MIN_READ', self::minutes($plain))) . '</span></div>'
            . '<h1 class="mnx-title">' . $e((string) $a->title) . '</h1>'
            . '<div class="mpl-by mwm-by">' . ($h['img'] !== '' ? '<img src="' . $e($h['img']) . '" alt="" width="56" height="56">' : '')
            . '<span><b>' . $e($h['name']) . '</b>' . (($h['full'] ?: $h['see']) !== '' ? '<span>' . $e(preg_replace('/\s*\R\s*/u', ' · ', $h['full'] ?: $h['see'])) . '</span>' : '') . '</span></div></header>';
        if ($lead !== '') {
            $head .= '<figure class="mnx-lead"><img src="' . $e($lead) . '" alt="' . $e($alt) . '" fetchpriority="high"></figure>';
        }

        $part = (string) ($props['part'] ?? '');
        if ($part === 'kicker') {
            return '<div class="mnx mpl mnx-tools"><div class="mnx-meta">' . self::chip($type, $feast) . '</div></div>';
        }
        if ($part === 'byline') {
            return '<div class="mnx mpl mnx-tools"><div class="mpl-by mwm-by">' . ($h['img'] !== '' ? '<img src="' . $e($h['img']) . '" alt="" width="56" height="56">' : '')
                . '<span><b>' . $e($h['name']) . '</b>' . (($h['full'] ?: $h['see']) !== '' ? '<span>' . $e(preg_replace('/\s*\R\s*/u', ' · ', $h['full'] ?: $h['see'])) . '</span>' : '') . '</span></div></div>';
        }
        if ($part === 'body') {
            $head = '';
        }

        $share = '<div class="mnx-share">'
            . '<a href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url) . '" target="_blank" rel="noopener" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '">' . self::I_FB . '</a>'
            . '<a href="mailto:?subject=' . rawurlencode((string) $a->title) . '&amp;body=' . rawurlencode($url) . '" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '">' . self::I_MAIL . '</a>'
            . '<button type="button" data-copy="' . $e($url) . '" data-done="' . $e(Text::_('MIT_NEWS_COPIED')) . '" aria-label="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '" title="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '">' . self::I_LINK . '</button>'
            . '<button type="button" data-print aria-label="' . $e(Text::_('MIT_NEWS_PRINT')) . '" title="' . $e(Text::_('MIT_NEWS_PRINT')) . '">' . self::I_PRINT . '</button></div>';

        // previous (older) and next (newer) in the section
        $older = $db->setQuery(self::base($tree)->select(self::cols())
            ->where('(a.publish_up < ' . $db->quote($a->publish_up) . ' OR (a.publish_up = ' . $db->quote($a->publish_up) . ' AND a.id < ' . (int) $a->id . '))')
            ->order('a.publish_up DESC, a.id DESC'), 0, 1)->loadObject();
        $newer = $db->setQuery(self::base($tree)->select(self::cols())
            ->where('(a.publish_up > ' . $db->quote($a->publish_up) . ' OR (a.publish_up = ' . $db->quote($a->publish_up) . ' AND a.id > ' . (int) $a->id . '))')
            ->order('a.publish_up ASC, a.id ASC'), 0, 1)->loadObject();

        $main = '<div class="mnx-prose mwm-prose">' . $text . '</div><div class="mnx-foot">' . $share . '</div>';
        if ($older || $newer) {
            $main .= '<nav class="mnx-pn" aria-label="' . $e(Text::_('MIT_NEWS_MORE')) . '">'
                . ($older ? '<a class="mnx-box" href="' . $e(self::url($older)) . '"><span class="mnx-meta">← ' . $e(Text::_('MIT_NEWS_PREVIOUS')) . '</span><b>' . $e((string) $older->title) . '</b></a>' : '<span></span>')
                . ($newer ? '<a class="mnx-box mnx-next" href="' . $e(self::url($newer)) . '"><span class="mnx-meta">' . $e(Text::_('MIT_NEWS_NEXT')) . ' →</span><b>' . $e((string) $newer->title) . '</b></a>' : '<span></span>')
                . '</nav>';
        }

        $side = '<div class="mnx-box"><h4>' . $e(Text::_('MIT_NEWS_SHARE')) . '</h4>' . $share . '</div>';
        $others = $db->setQuery(self::base([$cat])->select(self::cols())->where('a.id <> ' . (int) $a->id)->order('a.publish_up DESC, a.id DESC'), 0, 4)->loadObjectList();
        if ($others) {
            $of = self::fields(array_map(fn ($r) => (int) $r->id, $others), ['pastoral-type']);
            $side .= '<div class="mnx-box"><h4>' . $e(Text::sprintf('MIT_WM_MORE_FROM', $catRow ? (string) $catRow->title : '')) . '</h4>';
            foreach ($others as $o) {
                $t = $of[(int) $o->id]['pastoral-type'] ?? '';
                $side .= '<a class="mpl-other" href="' . $e(self::url($o)) . '">' . ($t !== '' ? '<span class="mpl-type ' . self::typeClass($t) . '">' . $e(self::typeLabel($t)) . '</span>' : '')
                    . '<b>' . $e((string) $o->title) . '</b><span class="mnx-meta">' . $e(self::date($o->publish_up, Text::_('MIT_NEWS_DATE_FORMAT'))) . '</span></a>';
            }
            $side .= '<a class="mnx-all" href="' . $e(self::sectionUrl($cat)) . '">' . $e(Text::_('MIT_WM_ALL')) . ' →</a></div>';
        }

        // related: same feast in the section, else the same type
        $related = [];
        $allIds = array_map('intval', $db->setQuery(self::base($tree)->select('a.id')->where('a.id <> ' . (int) $a->id))->loadColumn());
        $fv = self::fields($allIds, ['pastoral-type', 'pastoral-feast']);
        $heading = 'MIT_WM_RELATED';
        $pick = [];
        if ($feast !== '') {
            $pick = array_keys(array_filter($fv, fn ($v) => ($v['pastoral-feast'] ?? '') === $feast));
            if ($pick) {
                $heading = 'MIT_WM_SAME_FEAST';
            }
        }
        if (!$pick && $type !== '') {
            $pick = array_keys(array_filter($fv, fn ($v) => ($v['pastoral-type'] ?? '') === $type));
        }
        if ($pick) {
            $related = $db->setQuery(self::base($tree)->select(self::cols(true))->whereIn('a.id', array_map('intval', $pick))->order('a.publish_up DESC, a.id DESC'), 0, 3)->loadObjectList();
        }
        $rel = '';
        if ($related) {
            $rel = '<section class="mnx-rel"><div class="mnx-sechead"><h2 class="mpx-h2">' . $e(Text::_($heading)) . '</h2><a class="mnx-all" href="' . $e(self::sectionUrl($cat)) . '">' . $e(Text::_('MIT_WM_ALL')) . ' →</a></div><div class="mnx-grid">';
            foreach ($related as $r) {
                $rel .= News::kindCard($r, self::chip($fv[(int) $r->id]['pastoral-type'] ?? '', ''), (string) (self::cat((int) $r->catid)->title ?? ''));
            }
            $rel .= '</div></section>';
        }

        return '<article class="mnx mnx-art mpl mwm' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">' . $head
            . '<div class="mnx-cols"><div class="mnx-main">' . $main . '</div><aside class="mnx-side">' . $side . '</aside></div>' . $rel . '</article>';
    }

    /* ------------------------------------------------------------------ CSS and JS */

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        $cross = Uri::root(true) . '/images/site/cruce.svg';
        return '<style>.mpl{--mpl-cross:url("' . htmlspecialchars($cross, ENT_QUOTES, 'UTF-8') . '")}</style>' . <<<'HTML'
<style>
/* Pastoral letters (Mitropolia plugin) */
.mnx{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-gold:#B99755;--mp-ink:#1B2A4A;--mp-muted:#6B6F7B;--mp-line:#E4D8BE;--mp-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:var(--mp-ink)}
.mnx a{text-decoration:none}
.mnx .mpx-h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:56px;line-height:1.05;margin:0 0 14px;color:var(--mp-navy)}
.mnx .mpx-crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:14px;color:#6B5A34}.mnx .mpx-crumbs a{color:var(--mp-blue)}
.mnx .mpx-chip{display:inline-flex;align-items:center;font:inherit;font-size:14px;font-weight:600;padding:7px 16px;border-radius:999px;border:1px solid #D9CBAA;background:#fff;color:var(--mp-blue)}
.mnx .mpx-chip.on,.mnx .mpx-chip:hover{background:var(--mp-blue);border-color:var(--mp-blue);color:#fff}
.mnx .mpx-sel{height:46px;border:1px solid #D9CBAA;border-radius:4px;padding:0 14px;font:inherit;font-size:16px;background:#fff;color:var(--mp-blue)}
.mnx-meta{font-size:14px;color:var(--mp-muted);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.mnx-meta .dot{width:3px;height:3px;border-radius:50%;background:var(--mp-gold)}
.mnx-hero,.mnx-band{position:relative;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax)}
.mnx-band{padding:22px 0;border-bottom:1px solid var(--mp-line)}
.mnx-hero{border-bottom:1px solid var(--mp-line)}.mnx-hero-in{padding:22px 0 48px}
.mnx-hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:32px;flex-wrap:wrap;margin-top:34px}
.mnx-hero-text{max-width:700px}
.mnx-intro{font-family:'Source Serif 4',Georgia,serif;font-size:20px;line-height:1.55;color:#3C4A66;margin:0}
.mnx-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:28px}
.mnx-go{height:46px;border:0;border-radius:4px;padding:0 18px;font:inherit;font-weight:600;font-size:15px;background:var(--mp-blue);color:#fff;cursor:pointer}
.mnx-empty{text-align:center;color:var(--mp-muted);font-size:18px;padding:40px 0}
.mnx-pg{display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:48px}
.mnx-pg a,.mnx-pg span{min-width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;border:1px solid var(--mp-line);background:#fff;font-weight:600;color:var(--mp-blue);padding:0 12px;box-sizing:border-box}
.mnx-pg .on{background:var(--mp-blue);color:#fff;border-color:var(--mp-blue)}.mnx-pg .gap{border:0;background:none}
.mnx-box{display:block;background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:22px;color:inherit}
.mnx-box h4{margin:0 0 14px;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;font-family:'Source Sans 3',sans-serif}
.mnx-all{display:inline-block;margin-top:10px;font-weight:600;color:var(--mp-red)}
.mnx-share{display:flex;gap:8px}
.mnx-share a,.mnx-share button{width:40px;height:40px;border-radius:50%;border:1px solid #D9CBAA;display:inline-flex;align-items:center;justify-content:center;color:var(--mp-blue);background:#fff;cursor:pointer;padding:0;position:relative}
.mnx-share a:hover,.mnx-share button:hover{background:var(--mp-blue);color:#fff;border-color:var(--mp-blue)}
.mnx-share .done::after{content:attr(data-done);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--mp-navy);color:#fff;font-size:12px;padding:4px 8px;border-radius:4px;white-space:nowrap}
/* chips */
.mpl-type{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;border-radius:3px;padding:3px 8px;line-height:1.4}
.mpl-t-pastoral-letter{background:var(--mp-red);color:#fff}.mpl-t-meditation{background:var(--mp-blue);color:#fff}.mpl-t-message{background:var(--mp-ivory);color:#6B5A34}.mpl-t-homily{background:#E3EAF5;color:var(--mp-blue)}
.mpl-feast{font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-red)}
.mpl-chips{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
/* list */
.mpl-filters{display:flex;gap:8px;flex-wrap:wrap;margin:0}
.mpl-listbody{max-width:1080px;margin:0 auto;padding:48px 0 96px}
.mpl-rows{display:flex;flex-direction:column;gap:14px}
.mpl-row{display:grid;grid-template-columns:120px minmax(0,1fr) 190px;gap:28px;align-items:center;background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:22px 26px;min-height:176px;box-sizing:border-box;color:inherit;transition:box-shadow .2s}
.mpl-row:hover{box-shadow:0 14px 32px -18px rgba(23,46,92,.45)}
.mpl-date{font-family:'Baskervville',Georgia,serif;color:var(--mp-navy);line-height:1.1}
.mpl-date .d{font-size:34px;display:block}.mpl-date .my{display:block;font-family:'Source Sans 3',sans-serif;font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#6B5A34}
.mpl-row h3{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:23px;line-height:1.28;color:var(--mp-navy);margin:6px 0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.mpl-ex{display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;font-family:'Source Serif 4',Georgia,serif;font-size:16px;line-height:1.5;color:#3C4A66}
.mpl-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px;font-size:14px;color:var(--mp-muted);text-align:right}
.mpl-who{line-height:1.35}.mpl-read{font-weight:600;color:var(--mp-red)}
/* letter page */
.mpl-head{padding:52px 0 36px}
.mpl-head .mnx-meta{margin-bottom:16px}
.mpl-title{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:48px;line-height:1.12;color:var(--mp-navy);margin:0 0 24px;max-width:1000px}
.mpl-by{display:flex;align-items:center;gap:14px}
.mwm-by{margin-top:22px}.mwm .mnx-head .mnx-meta{flex-wrap:wrap}.mpl-words .mnx-grid{margin-top:8px}.mnx-kmeta .mpl-type{font-size:11px}
.mpl-by img{width:56px;height:56px;border-radius:50%;object-fit:cover;object-position:50% 20%;border:2px solid #fff;box-shadow:0 0 0 1px var(--mp-line);flex:none}
.mpl-by b{display:block;color:var(--mp-navy);font-size:17px}.mpl-by span span{font-size:14px;color:var(--mp-muted)}
.mpl-cols{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:56px;align-items:start;padding-bottom:72px}
.mpl-side-col{position:sticky;top:100px;display:flex;flex-direction:column;gap:20px}
.mpl-act{display:flex;gap:10px;align-items:center;font:inherit;font-weight:600;color:var(--mp-blue);background:none;border:0;padding:0;cursor:pointer;text-align:left}
.mpl-act+.mpl-act{margin-top:12px}.mpl-act:hover{color:var(--mp-red)}
.mpl-other{display:block;padding:10px 0;border-top:1px solid var(--mp-ivory)}.mpl-other:first-of-type{border-top:0;padding-top:0}
.mpl-other .mpl-type{font-size:10px}.mpl-other b{display:block;font-weight:600;color:var(--mp-navy);line-height:1.35;margin-top:6px}.mpl-other .mnx-meta{font-size:13px}
.mpl-other:hover b{color:var(--mp-red)}
.mpl-letter{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:64px 72px;box-shadow:0 30px 60px -40px rgba(23,46,92,.35);position:relative}
.mpl-letter::before{content:"";position:absolute;inset:10px;border:1px solid var(--mp-ivory);border-radius:3px;pointer-events:none}
.mpl-lhead{text-align:center;font-family:'Source Serif 4',Georgia,serif;color:var(--mp-navy);line-height:1.5;font-size:16px;margin-bottom:34px}
.mpl-lhead>span{display:block}
.mpl-lhead .mpl-who{font-family:'Baskervville',Georgia,serif;font-size:42px;line-height:1.15;letter-spacing:.06em;margin-bottom:4px}
.mpl-x{display:inline-block;width:.44em;height:.98em;background:currentColor;-webkit-mask:var(--mpl-cross) center/contain no-repeat;mask:var(--mpl-cross) center/contain no-repeat;vertical-align:-.1em;margin-right:.32em}
.mpl-mercy{font-style:italic;margin-bottom:6px}
.mpl-prose{font-family:'Source Serif 4',Georgia,serif;font-size:19px;line-height:1.75;color:var(--mp-ink)}
.mpl-prose p{margin:0 0 1.2em;text-indent:1.6em}
.mpl-prose p[style*="center"],.mpl-prose p[style*="right"]{text-indent:0}
.mpl-prose p[style*="justify"]{text-align:left!important}
.mpl-prose h3{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:24px;line-height:1.35;color:var(--mp-navy);margin:1.4em 0 .6em}
.mpl-prose h4{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:28px;line-height:1.3;color:var(--mp-red);margin:.4em 0 .8em}
.mpl-prose blockquote{font-style:italic;font-size:18px;line-height:1.65;color:#4A5570;text-align:center;max-width:600px;margin:0 auto 36px;padding:0;border:0;background:none}
.mpl-prose blockquote p{text-indent:0}
.mpl-prose hr{width:120px;height:1px;background:var(--mp-gold);border:0;margin:0 auto 30px}
.mpl-prose h3,.mpl-prose h4{text-transform:none;letter-spacing:normal}
.mpl-prose>ol:last-child{margin:0;padding:20px 0 0 22px;font-size:14px;line-height:1.55;color:#5A6378}
.mpl-prose>ol:last-child li{margin:0 0 6px}.mpl-prose>ol:last-child li:target{background:#FBF1D6}
.mpl-prose a{color:var(--mp-red)}
.mpl-prose sup a{font-family:'Source Sans 3',sans-serif;font-size:12px;font-weight:700;padding:0 1px}
.mpl-prose ol,.mpl-prose ul{padding-left:1.4em}
.mpl-prose img{max-width:100%;height:auto}
@media (max-width:1000px){.mpl-cols{grid-template-columns:minmax(0,1fr);gap:40px}.mpl-side-col{position:static}.mpl-title{font-size:40px}}
@media (max-width:760px){.mnx .mpx-h1{font-size:40px}.mpl-row{grid-template-columns:minmax(0,1fr);gap:10px}.mpl-side{flex-direction:row;align-items:center;justify-content:space-between;text-align:left}
 .mpl-letter{padding:36px 22px}.mpl-letter::before{inset:6px}.mpl-lhead .mpl-who{font-size:34px}.mpl-title{font-size:32px}.mpl-prose{font-size:18px}.mpl-filters,.mpl-filters .mpx-sel{width:100%}}
@media print{.mnx-band,.mpl-side-col,.mpl-by img{display:none!important}.mpl-cols{display:block}.mpl-letter{box-shadow:none;border:0;padding:0}.mpl-letter::before{display:none}}
</style>
<script>
(function(){
document.querySelectorAll('.mpl .mnx-share [data-copy]').forEach(function(b){b.addEventListener('click',function(){var u=b.getAttribute('data-copy');function ok(){b.classList.add('done');setTimeout(function(){b.classList.remove('done');},1600);}
if(navigator.clipboard){navigator.clipboard.writeText(u).then(ok,function(){prompt('',u);});}else{prompt('',u);}});});
document.querySelectorAll('.mpl [data-print]').forEach(function(b){b.addEventListener('click',function(){window.print();});});
document.querySelectorAll('.mpl-filters select').forEach(function(s){s.addEventListener('change',function(){s.form.submit();});});
})();
</script>
HTML;
    }
}
