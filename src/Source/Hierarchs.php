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
use Joomla\Database\ParameterType;

/**
 * Hierarch page ("Hierarch page" builder element), for articles in Hierarchs (RO/EN/ES) and Former Hierarchs.
 *
 * Top section: portrait (full text image, else intro image), the cross from images/site/cruce.svg, the form of
 * address split into an italic honorific and the name in capitals ("His Eminence" / "METROPOLITAN NICOLAE"),
 * the titles from the Full title field (one per line) and the intro text as one sentence.
 * Then: biography (full text, folded after a few paragraphs) with Key dates from the Timeline subform;
 * pastoral itinerary (Itinerary sub-category whose title holds the hierarch's name); pastoral letters and
 * Words and Messages (sub-categories whose alias equals this article's alias); news whose title names him.
 */
final class Hierarchs
{
    private const RANKS = ['Mitropolitul', 'Mitropolit', 'Arhiepiscopul', 'Arhiepiscop', 'Episcopul', 'Episcop',
        'Metropolitan', 'Archbishop', 'Bishop', 'Metropolita', 'Arzobispo', 'Obispo'];
    /** Words that, next to the name, mark a news title as being about a hierarch. */
    private const NEWS_MARKS = ['Mitropolit', 'Arhiepiscop', 'Episcop', 'Înaltpreasfinț', 'Preasfinț', 'Ierarh', 'Vlădic',
        'Metropolitan', 'Archbishop', 'Bishop', 'Eminence', 'His Grace', 'Metropolita', 'Arzobispo', 'Obispo'];
    private const BIO_FOLD = 4;

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

    private static function src(string $path): string
    {
        $path = trim(explode('#', $path, 2)[0]);
        if ($path === '') {
            return '';
        }
        return preg_match('#^https?://#i', $path) ? $path : Uri::root(true) . '/' . ltrim($path, '/');
    }

    private static function date(?string $sql, string $fmt): string
    {
        if (!$sql) {
            return '';
        }
        $d = HTMLHelper::_('date', $sql, $fmt);
        return in_array(self::lang(), ['ro', 'es'], true) ? mb_strtolower($d, 'UTF-8') : $d;
    }

    private static function url(object $a): string
    {
        return Route::_(RouteHelper::getArticleRoute($a->id . ':' . $a->alias, (int) $a->catid, $a->language));
    }

    private static function cat(int $id): ?object
    {
        static $c = [];
        if (!array_key_exists($id, $c)) {
            $db = self::db();
            $c[$id] = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias', 'parent_id', 'lft', 'rgt', 'language'])
                ->from('#__categories')->where('id = ' . $id)->where('published = 1'))->loadObject() ?: null;
        }
        return $c[$id];
    }

    private static function catByAlias(string $alias): ?object
    {
        $db = self::db();
        $id = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))->where('published = 1')
            ->where('alias = :a')->bind(':a', $alias))->loadResult();
        return $id ? self::cat($id) : null;
    }

    /** Published categories under a category (not the category itself). */
    private static function below(object $c): array
    {
        $db = self::db();
        return $db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias'])->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))->where('published = 1')
            ->where('lft > ' . (int) $c->lft)->where('rgt < ' . (int) $c->rgt)->order('lft'))->loadObjectList();
    }

    private static function tree(object $c): array
    {
        return array_merge([(int) $c->id], array_map(fn($r) => (int) $r->id, self::below($c)));
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

    private static function fields(array $ids, array $names): array
    {
        if (!$ids) {
            return [];
        }
        $db = self::db();
        $q = $db->getQuery(true)->select(['v.item_id', 'f.name', 'v.value'])
            ->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->whereIn('f.name', $names, ParameterType::STRING)
            ->whereIn('v.item_id', array_map('strval', $ids), ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->item_id][$r->name] = trim((string) $r->value);
        }
        return $out;
    }

    /** Field id by name (for reading subform rows, whose keys are field<ID>). */
    private static function fieldId(string $name): int
    {
        static $ids = [];
        if (!isset($ids[$name])) {
            $db = self::db();
            $ids[$name] = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__fields')
                ->where('name = :n')->where('context = ' . $db->quote('com_content.article'))->bind(':n', $name))->loadResult();
        }
        return $ids[$name];
    }

    /** Menu link of a category, or ''. */
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

    /** "Înaltpreasfințitul Părinte Mitropolit Nicolae" -> ["Înaltpreasfințitul Părinte", "Mitropolit Nicolae"]. */
    private static function splitName(string $title): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        $words = explode(' ', $title);
        foreach ($words as $i => $w) {
            if ($i > 0 && in_array($w, self::RANKS, true)) {
                $honor = implode(' ', array_slice($words, 0, $i));
                // Spanish "Su Eminencia el" -> "Su Eminencia"
                $honor = preg_replace('/\s+(el|la)$/u', '', $honor);
                return [$honor, implode(' ', array_slice($words, $i))];
            }
        }
        return ['', $title];
    }

    /* ------------------------------------------------------------------ fields for the page header built in YOOtheme */

    private static function headRow(object $a): object
    {
        if (!isset($a->introtext) && !empty($a->id)) {
            $db = self::db();
            $a = $db->setQuery($db->getQuery(true)->select(['a.id', 'a.title', 'a.introtext'])->from($db->quoteName('#__content', 'a'))->where('a.id = ' . (int) $a->id))->loadObject() ?: $a;
        }
        return $a;
    }

    /** "Înaltpreasfințitul Părinte" */
    public static function headHonor(object $a): string
    {
        return self::splitName((string) ($a->title ?? ''))[0];
    }

    /** "Mitropolitul Nicolae" */
    public static function headName(object $a): string
    {
        return self::splitName((string) ($a->title ?? ''))[1];
    }

    /** The full title lines as a list. */
    public static function headTitles(object $a): string
    {
        $f = self::fields([(int) ($a->id ?? 0)], ['hierarch-full-title'])[(int) ($a->id ?? 0)] ?? [];
        $titles = array_values(array_filter(array_map('trim', preg_split('/\s*(?:\R|·|\|)\s*/u', (string) ($f['hierarch-full-title'] ?? '')))));
        return $titles ? '<ul class="mhp-titles">' . implode('', array_map(fn($t) => '<li>' . self::e($t) . '</li>', $titles)) . '</ul>' : '';
    }

    /** The short text under the name (the text before Read More). */
    public static function headLead(object $a): string
    {
        $a = self::headRow($a);
        $lead = trim(strip_tags((string) ($a->introtext ?? ''), '<a><em><strong><b><i><br>'));
        return $lead !== '' ? '<p>' . $lead . '</p>' : '';
    }

    /* ------------------------------------------------------------------ element */

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
            $a = $db->setQuery($db->getQuery(true)
                ->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.introtext', 'a.fulltext', 'a.images'])
                ->from($db->quoteName('#__content', 'a'))->where('a.id = ' . $id)->where('a.state = 1'))->loadObject();
            if (!$a) {
                return '';
            }
            return self::html($a, $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Hierarch page: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function articleText(array $props): string
    {
        return '';
    }

    private static function html(object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $lang = self::lang();
        [$honor, $name] = self::splitName((string) $a->title);
        $key = (string) (array_slice(explode(' ', $name), -1)[0] ?? '');   // "Nicolae", "Casian"

        $f = self::fields([(int) $a->id], ['hierarch-full-title', 'hierarch-timeline'])[(int) $a->id] ?? [];
        $titles = array_values(array_filter(array_map('trim', preg_split('/\s*(?:\R|·|\|)\s*/u', (string) ($f['hierarch-full-title'] ?? '')))));
        $im = json_decode((string) $a->images, true) ?: [];
        $img = self::src((string) ($im['image_fulltext'] ?? '')) ?: self::src((string) ($im['image_intro'] ?? ''));
        $alt = (string) (($im['image_fulltext_alt'] ?? '') ?: ($im['image_intro_alt'] ?? '') ?: $a->title);
        $lead = trim(strip_tags((string) $a->introtext, '<a><em><strong><b><i><br>'));

        // timeline rows
        $rows = [];
        $tl = json_decode((string) ($f['hierarch-timeline'] ?? ''), true);
        if (is_array($tl)) {
            $fy = 'field' . self::fieldId('timeline-year');
            $fe = 'field' . self::fieldId('timeline-event');
            foreach ($tl as $r) {
                $y = trim((string) ($r[$fy] ?? ''));
                $ev = trim((string) ($r[$fe] ?? ''));
                if ($y !== '' || $ev !== '') {
                    $rows[] = [$y, $ev];
                }
            }
        }

        $stops = self::stops($key);
        $letters = self::writings('pastoral-letters-' . $lang, (string) $a->alias, $key);
        $words = self::writings('words-of-wisdom-' . $lang, (string) $a->alias, $key);
        $news = self::news($key);

        $cross = Uri::root(true) . '/images/site/cruce.svg';
        $h = '<div class="mnx mhp' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">';
        // ---- top section
        if (($props['part'] ?? '') !== 'body') $h .= '<section class="mhp-hero">'
            . '<div class="mhp-hero-in">'
            . ($img !== '' ? '<div class="mhp-ph"><img src="' . $e($img) . '" alt="' . $e($alt) . '"></div>' : '')
            . '<div class="mhp-tx">'
            . '<img class="mhp-cross" src="' . $e($cross) . '" alt="" aria-hidden="true">'
            . ($honor !== '' ? '<p class="mhp-honor">' . $e($honor) . '</p>' : '')
            . '<h1 class="mhp-name">' . $e($name) . '</h1>'
            . ($titles ? '<ul class="mhp-titles">' . implode('', array_map(fn($t) => '<li>' . $e($t) . '</li>', $titles)) . '</ul>' : '')
            . ($lead !== '' ? '<p class="mhp-lead">' . $lead . '</p>' : '')
            . '</div></div></section>';

        // ---- on-page menu
        $hasBio = trim(strip_tags((string) $a->fulltext)) !== '';
        $nav = [];
        if ($hasBio || $rows) {
            $nav[] = ['mhp-bio', Text::_('MIT_HP_BIO')];
        }
        if ($stops['up'] || $stops['past']) {
            $nav[] = ['mhp-itin', Text::_('MIT_HP_ITINERARY')];
        }
        if ($letters['items'] || $words['items']) {
            $nav[] = ['mhp-wr', Text::_('MIT_HP_WRITINGS')];
        }
        if ($news) {
            $nav[] = ['mhp-news', Text::_('MIT_HP_NEWS')];
        }
        if (count($nav) > 1) {
            $h .= '<nav class="mhp-sub" aria-label="' . $e(Text::_('MIT_HP_ON_PAGE')) . '"><div class="mhp-sub-in">';
            foreach ($nav as $i => [$id, $label]) {
                $h .= '<a href="#' . $id . '"' . ($i === 0 ? ' class="on"' : '') . '>' . $e($label) . '</a>';
            }
            $h .= '</div></nav>';
        }

        // ---- biography + key dates
        if ($hasBio || $rows) {
            $h .= '<section class="mhp-bio" id="mhp-bio"><div class="mhp-bio-in' . ($rows && $hasBio ? '' : ' one') . '">';
            if ($hasBio) {
                $h .= '<div><h2 class="mpx-h2">' . $e(Text::_('MIT_HP_BIO')) . '</h2>' . self::bio((string) $a->fulltext) . '</div>';
            }
            if ($rows) {
                $h .= '<aside class="mnx-box mhp-dates"><h4>' . $e(Text::_('MIT_HP_KEY_DATES')) . '</h4><ol class="mhp-tl">';
                foreach ($rows as [$y, $ev]) {
                    $h .= '<li>' . ($y !== '' ? '<b>' . $e($y) . '</b>' : '') . '<span>' . $e($ev) . '</span></li>';
                }
                $h .= '</ol></aside>';
            }
            $h .= '</div></section>';
        }

        // ---- itinerary + writings on navy
        $boxes = [];
        if ($stops['up'] || $stops['past']) {
            $first = $stops['up'] ? 'up' : 'past';
            $b = '<div class="mhp-card" id="mhp-itin"><div class="mhp-card-h"><h2 class="mpx-h2">' . $e(Text::_('MIT_HP_ITINERARY')) . '</h2>';
            if ($stops['up'] && $stops['past']) {
                $b .= '<div class="mhp-tabs" role="tablist"><button type="button" class="on" data-mhp-t="it-up">' . $e(Text::_('MIT_HP_UPCOMING')) . '</button><button type="button" data-mhp-t="it-past">' . $e(Text::_('MIT_HP_RECENT')) . '</button></div>';
            }
            $b .= '</div>';
            foreach (['up', 'past'] as $k) {
                if ($stops[$k]) {
                    $b .= '<div data-mhp-p="it-' . $k . '"' . ($k !== $first ? ' hidden' : '') . '>' . implode('', $stops[$k]) . '</div>';
                }
            }
            if ($stops['url'] !== '') {
                $b .= '<a class="mnx-all" href="' . $e($stops['url']) . '">' . $e(Text::_('MIT_HP_FULL_ITIN')) . ' →</a>';
            }
            $boxes[] = $b . '</div>';
        }
        if ($letters['items'] || $words['items']) {
            $both = $letters['items'] && $words['items'];
            $b = '<div class="mhp-card" id="mhp-wr"><div class="mhp-card-h"><h2 class="mpx-h2">' . $e(Text::_('MIT_HP_WRITINGS')) . '</h2>';
            if ($both) {
                $b .= '<div class="mhp-tabs" role="tablist"><button type="button" class="on" data-mhp-t="wr-l">' . $e(Text::_('MIT_PL_TITLE')) . '</button><button type="button" data-mhp-t="wr-w">' . $e(Text::_('MIT_WM_TITLE')) . '</button></div>';
            }
            $b .= '</div>';
            $firstW = $letters['items'] ? 'l' : 'w';
            foreach (['l' => [$letters, 'MIT_PL_ALL_LETTERS'], 'w' => [$words, 'MIT_WM_ALL']] as $k => [$set, $allKey]) {
                if (!$set['items']) {
                    continue;
                }
                $b .= '<div data-mhp-p="wr-' . $k . '"' . ($k !== $firstW ? ' hidden' : '') . '>' . implode('', $set['items'])
                    . ($set['url'] !== '' ? '<a class="mnx-all" href="' . $e($set['url']) . '">' . $e(Text::_($allKey)) . ' →</a>' : '') . '</div>';
            }
            $boxes[] = $b . '</div>';
        }
        if ($boxes) {
            $h .= '<section class="mhp-band"><div class="mhp-band-in' . (count($boxes) === 1 ? ' one' : '') . '">' . implode('', $boxes) . '</div></section>';
        }

        // ---- news
        if ($news) {
            $newsCat = self::catByAlias('news-' . $lang);
            $all = $newsCat ? self::menuUrl((int) $newsCat->id) : '';
            $h .= '<section class="mhp-news" id="mhp-news"><div class="mnx-sechead"><h2 class="mpx-h2">' . $e(Text::_('MIT_HP_NEWS')) . '</h2>'
                . ($all !== '' ? '<a class="mnx-all" href="' . $e($all . (strpos($all, '?') === false ? '?' : '&') . 'q=' . rawurlencode($key)) . '">' . $e(Text::_('MIT_HP_ALL_NEWS')) . ' →</a>' : '')
                . '</div><div class="mnx-grid">' . implode('', array_map(fn($n) => News::kindCard($n, ''), $news)) . '</div></section>' . News::sharedCss();
        }
        return $h . '</div>';
    }

    /* ------------------------------------------------------------------ list (template 8) */

    /** "Hierarchs list" element: the current hierarchs as cards, then the departed hierarchs as a table. */
    public static function listing(array $props): string
    {
        try {
            $root = self::listRoot($props);
            if (!$root) {
                return '';
            }
            return self::listHtml($root, $props) . self::listAssets();
        } catch (\Throwable $x) {
            Log::add('Hierarchs list: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /** The Hierarchs category of the page: the category being viewed, else hierarchs-<lang>. */
    private static function listRoot(array $props): ?object
    {
        $id = (int) ($props['category_id'] ?? 0);
        if (!$id) {
            $in = Factory::getApplication()->getInput();
            $id = $in->get('option') === 'com_content' && $in->get('view') === 'category' ? $in->getInt('id') : 0;
        }
        $c = $id ? self::cat($id) : null;
        return $c ?: self::catByAlias('hierarchs-' . self::lang());
    }

    private static function listHtml(object $root, array $props): string
    {
        $e = [self::class, 'e'];
        $db = self::db();
        $lang = self::lang();

        $cur = self::current($root);
        $subs = array_map(fn($r) => (int) $r->id, self::below($root));
        $old = $subs ? $db->setQuery(self::base($subs)->select(['a.id', 'a.title', 'a.introtext'])->order('a.ordering ASC'))->loadObjectList() : [];

        $f = self::fields(array_merge(array_map(fn($r) => (int) $r->id, $cur), array_map(fn($r) => (int) $r->id, $old)),
            ['hierarch-full-title', 'hierarch-see', 'hierarch-residence', 'hierarch-years', 'hierarch-repose']);

        $cross = Uri::root(true) . '/images/site/cruce.svg';

        $h = '<div class="mnx mhl' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">';
        if (($props['part'] ?? '') !== 'body') {
            $h .= '<section class="mhl-hero"><div class="mhl-head"><h1 class="mhl-title">' . $e(Text::_('MIT_HL_TITLE')) . '</h1></div></section>';
        }

        $h .= self::cardsHtml($cur, $f);

        if ($old) {
            usort($old, fn($x, $y) => strcmp((string) ($f[(int) $y->id]['hierarch-repose'] ?? ''), (string) ($f[(int) $x->id]['hierarch-repose'] ?? '')));
            $h .= '<section class="mhl-dep"><h2 class="mhl-dep-h">' . $e(Text::_('MIT_HL_DEPARTED')) . '</h2>'
                . '<table class="mhl-tbl"><thead><tr><th>' . $e(Text::_('MIT_HL_COL_HIERARCH')) . '</th><th>' . $e(Text::_('MIT_HL_COL_SERVED')) . '</th><th>' . $e(Text::_('MIT_HL_COL_REPOSED')) . '</th></tr></thead><tbody>';
            foreach ($old as $o) {
                $v = $f[(int) $o->id] ?? [];
                $note = trim(strip_tags((string) $o->introtext));
                $rep = (string) ($v['hierarch-repose'] ?? '');
                $h .= '<tr><td><span class="mhl-x" aria-hidden="true">†</span><b>' . $e((string) $o->title) . '</b>' . ($note !== '' ? '<span class="mhl-note">' . $e($note) . '</span>' : '') . '</td>'
                    . '<td data-l="' . $e(Text::_('MIT_HL_COL_SERVED')) . '">' . $e((string) ($v['hierarch-years'] ?? '')) . '</td>'
                    . '<td data-l="' . $e(Text::_('MIT_HL_COL_REPOSED')) . '">' . $e($rep !== '' ? self::date($rep, Text::_('MIT_NEWS_DATE_FORMAT')) : '') . '</td></tr>';
            }
            $h .= '</tbody></table></section>';
        }
        return $h . '</div>';
    }

    /** Current hierarchs of a Hierarchs category, Metropolitan first, then archbishops, then bishops. */
    private static function current(object $root): array
    {
        $db = self::db();
        $cur = $db->setQuery(self::base([(int) $root->id])
            ->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.images'])->order('a.ordering ASC, a.id ASC'))->loadObjectList();
        // Metropolitan first, then archbishops, then bishops; same rank keeps the article order
        $rank = function (object $a): int {
            [, $n] = self::splitName((string) $a->title);
            $w = explode(' ', $n)[0] ?? '';
            foreach ([['Mitropolit', 'Metropolit'], ['Arhiepiscop', 'Archbishop', 'Arzobispo'], ['Episcop', 'Bishop', 'Obispo']] as $i => $set) {
                foreach ($set as $p) {
                    if (stripos($w, $p) === 0) {
                        return $i;
                    }
                }
            }
            return 9;
        };
        $pos = array_flip(array_map(fn($r) => (int) $r->id, $cur));
        usort($cur, fn($x, $y) => [$rank($x), $pos[(int) $x->id]] <=> [$rank($y), $pos[(int) $y->id]]);
        return $cur;
    }

    /** The hierarch cards (photo, honorific, name, titles, links), as on the Hierarchs page. */
    private static function cardsHtml(array $cur, array $f): string
    {
        $e = [self::class, 'e'];
        $lang = self::lang();
        $cross = Uri::root(true) . '/images/site/cruce.svg';
        $h = '';
        if ($cur) {
            $h .= '<div class="mhl-cards' . (count($cur) === 1 ? ' one' : '') . '">';
            foreach ($cur as $a) {
                $v = $f[(int) $a->id] ?? [];
                [$honor, $name] = self::splitName((string) $a->title);
                $key = (string) (array_slice(explode(' ', $name), -1)[0] ?? '');
                $titles = array_values(array_filter(array_map('trim', preg_split('/\s*(?:\R|·|\|)\s*/u', (string) ($v['hierarch-full-title'] ?? '')))));
                $im = json_decode((string) $a->images, true) ?: [];
                $img = self::src((string) ($im['image_fulltext'] ?? '')) ?: self::src((string) ($im['image_intro'] ?? ''));
                $url = self::url($a);
                $links = [[Text::_('MIT_HP_BIO'), $url]];
                $links[] = [Text::_('MIT_HP_ITINERARY'), self::stops($key)['url']];
                $links[] = [Text::_('MIT_PL_TITLE'), self::writings('pastoral-letters-' . $lang, (string) $a->alias, $key)['url']];
                $links[] = [Text::_('MIT_WM_TITLE'), self::writings('words-of-wisdom-' . $lang, (string) $a->alias, $key)['url']];
                $h .= '<article class="mhl-card">'
                    . '<a class="mhl-ph" href="' . $e($url) . '" tabindex="-1" aria-hidden="true">' . ($img !== '' ? '<img src="' . $e($img) . '" alt="" loading="lazy">' : '') . '</a>'
                    . '<div class="mhl-bd"><img class="mhl-cross" src="' . $e($cross) . '" alt="" aria-hidden="true">'
                    . ($honor !== '' ? '<p class="mhl-honor">' . $e($honor) . '</p>' : '')
                    . '<h2 class="mhl-name"><a href="' . $e($url) . '">' . $e($name) . '</a></h2>'
                    . ($titles ? '<ul class="mhl-titles">' . implode('', array_map(fn($t) => '<li>' . $e($t) . '</li>', $titles)) . '</ul>' : '')
                    . '<nav class="mhl-links">';
                foreach ($links as [$label, $href]) {
                    if ($href !== '') {
                        $h .= '<a href="' . $e($href) . '">' . $e($label) . '</a>';
                    }
                }
                $h .= '</nav></div></article>';
            }
            $h .= '</div>';
        }

        return $h;
    }

    /* ------------------------------------------------------------------ homepage */

    /** Homepage: the hierarch cards of the Hierarchs page. */
    public static function homeCards(array $props = []): string
    {
        try {
            $root = self::catByAlias('hierarchs-' . self::lang());
            if (!$root) {
                return '';
            }
            $cur = self::current($root);
            $f = self::fields(array_map(fn($r) => (int) $r->id, $cur), ['hierarch-full-title']);
            return '<div class="mnx mhl mh-hcards">' . self::cardsHtml($cur, $f) . '</div>' . self::listAssets();
        } catch (\Throwable $x) {
            Log::add('Home hierarchs: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /** Homepage: the pastoral itinerary card of the hierarch page, with a switch between the hierarchs. */
    public static function homeItinerary(array $props = []): string
    {
        try {
            $root = self::catByAlias('hierarchs-' . self::lang());
            if (!$root) {
                return '';
            }
            $e = [self::class, 'e'];
            $sets = [];
            foreach (self::current($root) as $a) {
                [, $name] = self::splitName((string) $a->title);
                $key = (string) (array_slice(explode(' ', $name), -1)[0] ?? '');
                $st = self::stops($key);
                if ($st['up'] || $st['past']) {
                    $sets[] = [$name, $st];
                }
            }
            if (!$sets) {
                return '';
            }
            $title = trim((string) ($props['title'] ?? '')) ?: Text::_('MIT_HP_ITINERARY');
            $h = '<div class="mhp mh-hitin" data-mh-it><div class="mhp-card"><div class="mhp-card-h"><h2 class="mpx-h2">' . $e($title) . '</h2></div>';
            if (count($sets) > 1) {
                $h .= '<div class="mh-pills mh-pills-full" role="tablist">';
                foreach ($sets as $i => [$name]) {
                    $h .= '<button type="button" data-mh-h="' . $i . '" aria-selected="' . ($i ? 'false' : 'true') . '">' . $e($name) . '</button>';
                }
                $h .= '</div>';
            }
            foreach ($sets as $i => [, $st]) {
                $h .= '<div data-mh-pane="' . $i . '-up"' . ($i === 0 ? '' : ' hidden') . '>'
                    . ($st['up'] ? implode('', $st['up']) : '<p class="mh-it-none">' . $e(Text::_('MIT_HP_NO_UPCOMING')) . '</p>')
                    . ($st['url'] !== '' ? '<a class="mh-it-more" href="' . $e($st['url']) . '">' . $e(Text::_('MIT_HP_FULL_ITIN')) . ' →</a>' : '') . '</div>';
            }
            $h .= '</div></div>' . self::assets() . <<<'HTML'
<script>
document.querySelectorAll('[data-mh-it]').forEach(function(box){var h='0',m='up';
function show(){box.querySelectorAll('[data-mh-pane]').forEach(function(p){p.hidden=p.getAttribute('data-mh-pane')!==h+'-'+m;});
box.querySelectorAll('[data-mh-m]').forEach(function(b){b.classList.toggle('on',b.getAttribute('data-mh-m')===m);});
box.querySelectorAll('[data-mh-h]').forEach(function(b){b.setAttribute('aria-selected',String(b.getAttribute('data-mh-h')===h));});}
box.querySelectorAll('[data-mh-m]').forEach(function(b){b.addEventListener('click',function(){m=b.getAttribute('data-mh-m');show();});});
box.querySelectorAll('[data-mh-h]').forEach(function(b){b.addEventListener('click',function(){h=b.getAttribute('data-mh-h');show();});});});
</script>
HTML;
            return $h;
        } catch (\Throwable $x) {
            Log::add('Home itinerary: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    private static function listAssets(): string
    {
        return <<<'HTML'
<style>
/* Hierarchs list (Mitropolia plugin) */
.mhl{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-line:#E4D8BE;--mp-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:#1B2A4A}
.mhl a{text-decoration:none}
.mhl-hero{position:relative;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax);padding:72px 0 64px}
.mhl-head{position:relative;text-align:center;max-width:760px;margin:0 auto}
.mhl-title{font-family:'Baskervville',Georgia,serif;font-weight:400;font-size:64px;line-height:1.02;color:var(--mp-blue);margin:0}
.mhl-cards{position:relative;isolation:isolate;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:28px;max-width:860px;margin:0 auto;padding-bottom:88px}
.mhl-cards::before{content:"";position:absolute;z-index:-1;left:0;right:0;top:0;height:30%;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax)}
.mhl-cards.one{grid-template-columns:minmax(0,430px);justify-content:center}
.mhl-card{background:#fff;border:1px solid var(--mp-line);border-radius:14px;overflow:hidden;padding:0 22px 12px;display:flex;flex-direction:column;align-items:center;text-align:center;box-shadow:0 30px 60px -38px rgba(23,46,92,.4);transition:transform .25s,box-shadow .25s}
.mhl-card:hover{transform:translateY(-3px);box-shadow:0 36px 64px -36px rgba(23,46,92,.5)}
.mhl-ph{position:relative;display:block;width:calc(100% + 44px);margin:0 -22px;aspect-ratio:504/465;overflow:hidden;background:var(--mp-line)}
.mhl-ph img{width:100%;height:100%;object-fit:cover;object-position:50% 12%;display:block;transition:transform .6s}
.mhl-card:hover .mhl-ph img{transform:scale(1.03)}
.mhl-bd{display:flex;flex-direction:column;align-items:center;width:100%;flex:1}
.mhl-cross{display:block;width:auto;height:42px;margin:22px 0 12px;filter:invert(55%) sepia(62%) saturate(520%) hue-rotate(5deg) brightness(88%) contrast(90%)}
.mhl-honor{font-family:'Baskervville',Georgia,serif;font-style:italic;font-size:22px;line-height:1.1;color:var(--mp-blue);margin:0 0 4px}
.mhl-name{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:30px;line-height:1.1;color:var(--mp-blue);margin:0 0 12px}
.mhl-name a{color:inherit}
.mhl-titles{list-style:none;margin:0 0 20px;padding:0}
.mhl-titles li{font-size:12px;font-weight:600;letter-spacing:.11em;text-transform:uppercase;color:#9C7A3C;line-height:1.5;padding:2px 0;margin:0}
.mhl-titles li::before{display:none}
.mhl-links{margin-top:auto;width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;align-items:start;padding:4px 0 12px}
.mhl-links a{display:flex;align-items:center;justify-content:center;text-align:center;height:36px;box-sizing:border-box;padding:0 8px;white-space:nowrap;border:1px solid #DCC89C;border-radius:4px;background:#F7F0E1;font-weight:600;font-size:13.5px;line-height:1.2;color:var(--mp-navy);transition:background .2s,border-color .2s}
.mhl-links a:hover{background:#E8D5A6;border-color:#C9A85C;color:var(--mp-navy)}
.mhl-dep{border-top:1px solid var(--mp-line);padding:72px 0 96px}
.mhl-dep-h{font-family:'Baskervville',Georgia,serif;font-weight:400;font-size:34px;line-height:1.15;color:var(--mp-blue);text-align:center;margin:0 auto 32px;max-width:720px}
.mhl-tbl{width:100%;max-width:980px;margin:0 auto;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid var(--mp-line);border-radius:10px;overflow:hidden}
.mhl-tbl th{text-align:left;font-size:12px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;background:var(--mp-ivory);padding:14px 24px;border:0}
.mhl-tbl td{padding:22px 24px;border:0;border-top:1px solid #EFE6D4;vertical-align:top;color:#3C4A66;font-size:16px}
.mhl-tbl tbody tr:first-child td{border-top:0}
.mhl-tbl td:first-child{position:relative;padding-left:56px}
.mhl-x{position:absolute;left:24px;top:20px;font-family:'Baskervville',Georgia,serif;font-size:22px;color:#B08A2E}
.mhl-tbl b{display:block;font-family:'Baskervville',Georgia,serif;font-size:21px;font-weight:500;color:var(--mp-blue);margin-bottom:4px}
.mhl-note{display:block;font-size:14px;line-height:1.5;color:#5A6680;max-width:500px}
.mhl-tbl td:nth-child(2),.mhl-tbl td:nth-child(3){white-space:nowrap;font-variant-numeric:tabular-nums;padding-top:26px}
@media (max-width:900px){.mhl-cards{grid-template-columns:minmax(0,1fr);max-width:520px}.mhl-cards::before{height:18%}}
@media (max-width:640px){
.mhl-hero{padding:48px 0 40px}.mhl-title{font-size:44px}
.mhl-card{padding:0 16px 8px}.mhl-ph{width:calc(100% + 32px);margin:0 -16px}.mhl-name{font-size:28px}
.mhl-links a{white-space:normal;height:auto;min-height:38px;padding:6px 10px;font-size:13px}
.mhl-dep{padding:56px 0 72px}.mhl-dep-h{font-size:28px}
.mhl-tbl thead{display:none}.mhl-tbl,.mhl-tbl tbody,.mhl-tbl tr,.mhl-tbl td{display:block;width:100%}
.mhl-tbl tr{border-top:1px solid #EFE6D4;padding:16px 0}.mhl-tbl tbody tr:first-child{border-top:0}
.mhl-tbl td{box-sizing:border-box;white-space:normal!important;border:0!important;padding:4px 18px 4px 48px!important}
.mhl-x{left:18px;top:2px}
.mhl-tbl td[data-l]::before{content:attr(data-l) ": ";font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#6B5A34}
}
</style>
HTML;
    }

    /** Biography text: the first paragraphs, the rest folded behind "Read the full biography". */
    private static function bio(string $html): string
    {
        $html = trim($html);
        // top-level blocks
        preg_match_all('#<(p|ol|ul|h[2-6]|blockquote|figure|table|div)\b[^>]*>.*?</\1>#isu', $html, $m);
        $blocks = $m[0];
        if (count($blocks) <= self::BIO_FOLD + 1) {
            return '<div class="mhp-prose">' . $html . '</div>';
        }
        $head = implode('', array_slice($blocks, 0, self::BIO_FOLD));
        $rest = implode('', array_slice($blocks, self::BIO_FOLD));
        return '<div class="mhp-prose">' . $head . '<div class="mhp-more" id="mhp-more" hidden>' . $rest . '</div></div>'
            . '<button type="button" class="mhp-morebtn" aria-expanded="false" aria-controls="mhp-more" data-less="' . self::e(Text::_('MIT_HP_BIO_LESS')) . '">'
            . self::e(Text::_('MIT_HP_BIO_MORE')) . '</button>';
    }

    /** Itinerary stops of the hierarch: the Itinerary sub-category whose title holds his name. */
    private static function stops(string $key): array
    {
        $out = ['up' => [], 'past' => [], 'url' => ''];
        $root = self::catByAlias('itinerary');
        if (!$root || $key === '') {
            return $out;
        }
        $cat = null;
        foreach (self::below($root) as $c) {
            if (mb_stripos((string) $c->title, $key) !== false) {
                $cat = $c;
                break;
            }
        }
        if (!$cat) {
            return $out;
        }
        $out['url'] = self::menuUrl((int) $cat->id);
        $db = self::db();
        $lang = self::lang();
        $q = self::base([(int) $cat->id])->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.introtext', 'a.fulltext']);
        $list = $db->setQuery($q)->loadObjectList();
        if (!$list) {
            return $out;
        }
        $ids = array_map(fn($r) => (int) $r->id, $list);
        $f = self::fields($ids, ['itinerary-date', 'itinerary-title-' . $lang, 'itinerary-title-en', 'itinerary-place', 'itinerary-occasion-' . $lang, 'itinerary-occasion-en', 'itinerary-parish']);
        $parishIds = array_filter(array_map(fn($i) => (int) ($f[$i]['itinerary-parish'] ?? 0), $ids));
        $parishes = [];
        if ($parishIds) {
            foreach ($db->setQuery($db->getQuery(true)->select(['id', 'title'])->from('#__content')->whereIn('id', array_values($parishIds)))->loadObjectList() as $p) {
                $parishes[(int) $p->id] = (string) $p->title;
            }
        }
        $today = Factory::getDate()->format('Y-m-d');
        $up = $past = [];
        foreach ($list as $r) {
            $v = $f[(int) $r->id] ?? [];
            $d = (string) ($v['itinerary-date'] ?? '');
            if ($d === '') {
                continue;
            }
            $title = ($v['itinerary-title-' . $lang] ?? '') ?: (($v['itinerary-occasion-' . $lang] ?? '') ?: (($v['itinerary-title-en'] ?? '') ?: (string) $r->title));
            $place = ($v['itinerary-place'] ?? '') ?: ($parishes[(int) ($v['itinerary-parish'] ?? 0)] ?? '');
            $row = [$d, $title, $place, trim(strip_tags((string) $r->fulltext)) !== '' ? self::url($r) : ''];
            if (substr($d, 0, 10) >= $today) {
                $up[] = $row;
            } else {
                $past[] = $row;
            }
        }
        usort($up, fn($x, $y) => strcmp($x[0], $y[0]));
        usort($past, fn($x, $y) => strcmp($y[0], $x[0]));
        $render = function (array $r): string {
            [$d, $t, $p, $u] = $r;
            $tag = $u !== '' ? 'a href="' . self::e($u) . '"' : 'div';
            $end = $u !== '' ? 'a' : 'div';
            return '<' . $tag . ' class="mhp-stop"><span class="mhp-db"><span class="m">' . self::e(self::date($d, 'M')) . '</span><span class="d">' . self::e(self::date($d, 'j')) . '</span></span>'
                . '<span><b>' . self::e($t) . '</b>' . ($p !== '' ? '<span>' . self::e($p) . '</span>' : '') . '</span></' . $end . '>';
        };
        $out['up'] = array_map($render, array_slice($up, 0, 4));
        $out['past'] = array_map($render, array_slice($past, 0, 4));
        return $out;
    }

    /** Latest pieces in the sub-category of $rootAlias whose alias equals the hierarch article's alias. */
    private static function writings(string $rootAlias, string $alias, string $key = ''): array
    {
        $out = ['items' => [], 'url' => ''];
        $root = self::catByAlias($rootAlias);
        if (!$root) {
            return $out;
        }
        $cat = null;
        $subs = self::below($root);
        foreach ($subs as $c) {
            if ((string) $c->alias === $alias) {
                $cat = $c;
                break;
            }
        }
        if (!$cat && $key !== '') {
            foreach ($subs as $c) {
                if (mb_stripos((string) $c->title, $key) !== false) {
                    $cat = $c;
                    break;
                }
            }
        }
        if (!$cat) {
            return $out;
        }
        $out['url'] = self::menuUrl((int) $cat->id);
        $db = self::db();
        $list = $db->setQuery(self::base(self::tree($cat))->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.publish_up'])
            ->order('a.publish_up DESC'), 0, 3)->loadObjectList();
        $types = self::fields(array_map(fn($r) => (int) $r->id, $list), ['pastoral-type']);
        foreach ($list as $r) {
            $t = (string) ($types[(int) $r->id]['pastoral-type'] ?? '');
            $chip = $t !== '' ? '<span class="mhp-type mhp-t-' . self::e($t) . '">' . self::e(Text::_('MIT_O_PASTORAL_TYPE_' . strtoupper(str_replace('-', '_', $t)))) . '</span>' : '';
            $out['items'][] = '<a class="mhp-wrow" href="' . self::e(self::url($r)) . '"><span class="mhp-wmeta">' . $chip
                . '<time>' . self::e(self::date($r->publish_up, Text::_('MIT_NEWS_DATE_FORMAT'))) . '</time></span><b>' . self::e((string) $r->title) . '</b></a>';
        }
        return $out;
    }

    /** Latest news whose title names the hierarch (his name next to a rank or form of address). */
    private static function news(string $key): array
    {
        $lang = self::lang();
        $root = self::catByAlias('news-' . $lang);
        if (!$root || mb_strlen($key) < 4) {
            return [];
        }
        $db = self::db();
        $marks = array_map(fn($w) => 'a.title LIKE ' . $db->quote('%' . $db->escape($w, true) . '%', false), self::NEWS_MARKS);
        $q = self::base(self::tree($root))->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.publish_up', 'a.introtext', 'a.images'])
            ->where('a.title LIKE ' . $db->quote('%' . $db->escape($key, true) . '%', false))
            ->where('(' . implode(' OR ', $marks) . ' OR CAST(a.title AS BINARY) REGEXP ' . $db->quote('(^|[^A-Za-z])I?PS([^A-Za-z]|$)') . ')')
            ->where('a.title NOT REGEXP ' . $db->quote('(^|[^[:alpha:]])(Sf|St|Saint|San|Santo)[^ ]* +([^ ]+ +)?' . preg_quote($key, '/')))
            ->order('a.publish_up DESC');
        return $db->setQuery($q, 0, 3)->loadObjectList();
    }

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>
/* Hierarch page (Mitropolia plugin) */
.mhp{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-gold:#B99755;--mp-ink:#1B2A4A;--mp-muted:#6B6F7B;--mp-line:#E4D8BE;--mp-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:var(--mp-ink)}
.mhp a{text-decoration:none}
.mhp .mpx-h2{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:34px;line-height:1.2;color:var(--mp-navy);margin:0}
.mhp-hero,.mhp-sub,.mhp-bio,.mhp-band{position:relative}
.mhp-hero{background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax);border-bottom:1px solid var(--mp-line);min-height:calc(100vh - var(--mhp-top,154px));min-height:calc(100svh - var(--mhp-top,154px));box-sizing:border-box;padding:48px 0;display:flex;flex-direction:column;justify-content:center}
.mhp-hero-in{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:56px;align-items:center}
.mhp-ph{position:relative;justify-self:end;width:min(100%,500px,calc((100svh - var(--mhp-top,154px) - 96px) * .8));aspect-ratio:4/5;border-radius:6px;overflow:hidden;background:var(--mp-line);box-shadow:0 30px 60px -34px rgba(23,46,92,.55)}
.mhp-ph img{width:100%;height:100%;object-fit:cover;object-position:50% 15%;display:block}
.mhp-ph::after{content:"";position:absolute;inset:10px;border:1px solid rgba(212,175,55,.75);border-radius:3px;pointer-events:none}
.mhp-tx{display:flex;flex-direction:column;align-items:center;text-align:center}
.mhp-hero-in>.mhp-tx:only-child{grid-column:1/-1}
.mhp-cross{display:block;width:auto;height:74px;margin:0 0 22px;filter:invert(55%) sepia(62%) saturate(520%) hue-rotate(5deg) brightness(88%) contrast(90%)}
.mhp-honor{font-family:'Baskervville',Georgia,serif;font-style:italic;font-weight:400;font-size:52px;line-height:1.05;color:var(--mp-blue);margin:0}
.mhp-name{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:clamp(38px,3.6vw,54px);line-height:1.04;letter-spacing:.01em;text-transform:uppercase;color:var(--mp-blue);margin:4px 0 22px}
.mhp-titles{list-style:none;margin:0 0 22px;padding:0}
.mhp-titles li{font-size:12px;font-weight:600;letter-spacing:.11em;text-transform:uppercase;color:#9C7A3C;line-height:1.5;padding:2px 0;margin:0}
.mhp-titles li::before{display:none}
.mhp-lead{font-family:'Baskervville',Georgia,serif;font-size:21px;line-height:1.5;color:var(--mp-blue);max-width:470px;margin:0}
.mhp-sub{position:sticky;top:var(--mhp-sticky,76px);z-index:20;background:#fff;box-shadow:0 0 0 100vmax #fff;clip-path:inset(0 -100vmax);border-bottom:1px solid var(--mp-line)}
.mhp-sub-in{display:flex;gap:32px;overflow-x:auto;white-space:nowrap;scrollbar-width:none}
.mhp-sub a{display:inline-block;padding:16px 0;font-weight:600;color:var(--mp-blue);border-bottom:3px solid transparent}
.mhp-sub a.on,.mhp-sub a:hover{border-color:var(--mp-red);color:var(--mp-red)}
.mhp-bio{scroll-margin-top:140px;padding:72px 0 80px}
.mhp-bio-in{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:64px;align-items:start}
.mhp-bio-in.one{grid-template-columns:minmax(0,820px)}
.mhp-bio .mpx-h2{font-size:38px;margin-bottom:20px}
.mhp-prose{font-family:'Source Serif 4',Georgia,serif;font-size:19px;line-height:1.7;color:#24324F}
.mhp-prose p{margin:0 0 1.1em}.mhp-prose ol,.mhp-prose ul{margin:0 0 1.1em 1.3em;padding:0}.mhp-prose li{margin:.3em 0}
.mhp-prose strong{color:var(--mp-navy)}
.mhp-morebtn{border:0;background:none;padding:0;font:inherit;font-weight:600;color:var(--mp-red);cursor:pointer;font-size:16px}
.mhp-morebtn::after{content:" ↓"}.mhp-morebtn[aria-expanded=true]::after{content:" ↑"}
.mnx-box{display:block;background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:26px;color:inherit}
.mnx-box h4{margin:0 0 16px;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;font-family:'Source Sans 3',sans-serif}
.mhp-dates{position:sticky;top:150px}
.mhp-tl{list-style:none;margin:0 0 0 6px;padding:0;border-left:2px solid var(--mp-line)}
.mhp-tl li{position:relative;padding:0 0 20px 24px;margin:0}
.mhp-tl li:last-child{padding-bottom:0}
.mhp-tl li::before{content:"";position:absolute;left:-7px;top:6px;width:12px;height:12px;border-radius:50%;background:#fff;border:2px solid var(--mp-red)}
.mhp-tl b{display:block;font-size:14px;letter-spacing:.04em;color:var(--mp-red)}
.mhp-tl span{font-family:'Source Serif 4',Georgia,serif;font-size:16px;line-height:1.5;color:var(--mp-ink)}
.mhp-band{background:var(--mp-navy);box-shadow:0 0 0 100vmax var(--mp-navy);clip-path:inset(0 -100vmax);padding:64px 0 72px}
.mhp-band-in{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:32px}
.mhp-band-in.one{grid-template-columns:minmax(0,820px);justify-content:center}
.mhp-card{background:#fff;border-radius:8px;padding:28px;scroll-margin-top:140px;box-shadow:0 24px 50px -30px rgba(0,0,0,.6)}
.mhp-card-h{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px}
.mhp-card .mpx-h2{font-size:30px}
.mhp-tabs{display:inline-flex;border:1px solid #D9CBAA;border-radius:999px;padding:4px;background:#FBF7EE}
.mhp-tabs button{border:0;background:none;border-radius:999px;padding:7px 16px;font:inherit;font-weight:600;font-size:14px;color:var(--mp-blue);cursor:pointer}
.mhp-tabs button.on{background:var(--mp-navy);color:#fff}
.mhp-stop{display:grid;grid-template-columns:70px minmax(0,1fr);gap:18px;align-items:center;padding:14px 0;border-top:1px solid var(--mp-ivory);color:inherit}
.mhp-stop:first-child{border-top:0}
.mhp-db{border:1px solid var(--mp-line);border-radius:5px;text-align:center;overflow:hidden;background:#fff;display:block}
.mhp-db .m{display:block;background:var(--mp-red);color:#fff;font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;padding:4px 0}
.mhp-db .d{display:block;font-family:'Baskervville',Georgia,serif;font-size:28px;color:var(--mp-navy);padding:5px 0}
.mhp-stop b{display:block;font-size:18px;color:var(--mp-navy);font-weight:600;line-height:1.3}.mhp-stop span span{display:block;font-size:15px;color:#5A6378;margin-top:3px}
a.mhp-stop:hover b{color:var(--mp-red)}
.mhp-wrow{display:block;padding:14px 0;border-top:1px solid var(--mp-ivory);color:inherit}.mhp-wrow:first-child{border-top:0}
.mhp-wmeta{display:flex;gap:10px;align-items:center;font-size:13px;color:var(--mp-muted)}
.mhp-wrow b{display:block;font-weight:600;color:var(--mp-navy);line-height:1.35;margin-top:5px;font-size:17px}
.mhp-wrow:hover b{color:var(--mp-red)}
.mhp-type{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;border-radius:3px;padding:2px 7px;line-height:1.4}
.mhp-t-pastoral-letter{background:var(--mp-red);color:#fff}.mhp-t-meditation{background:var(--mp-blue);color:#fff}.mhp-t-message{background:var(--mp-ivory);color:#6B5A34}.mhp-t-homily{background:#E3EAF5;color:var(--mp-blue)}
.mhp .mnx-all{display:inline-block;margin-top:12px;font-weight:600;color:var(--mp-red)}
.mhp-news{padding:72px 0 96px;scroll-margin-top:140px}
.mhp-news .mnx-sechead{display:flex;justify-content:space-between;align-items:baseline;gap:16px;flex-wrap:wrap;margin-bottom:24px}
.mhp-news .mpx-h2{font-size:38px}.mhp-news .mnx-all{margin-top:0}
@media (max-width:1000px){
.mhp-hero{min-height:0;padding:60px 0 48px}
.mhp-hero-in{grid-template-columns:minmax(0,1fr);gap:28px}
.mhp-ph{justify-self:center;width:min(100%,340px)}
.mhp-bio-in,.mhp-band-in{grid-template-columns:minmax(0,1fr)}
.mhp-dates{position:static}
}
@media (max-width:640px){
.mhp-ph{width:min(100%,300px)}
.mhp-honor{font-size:36px}.mhp-name{font-size:38px}.mhp-lead{font-size:18px}.mhp-cross{height:56px;margin-bottom:16px}
.mhp-sub-in{gap:20px}.mhp .mpx-h2,.mhp-bio .mpx-h2,.mhp-news .mpx-h2{font-size:30px}.mhp-card{padding:22px}
.mhp-prose{font-size:18px}
}
</style>
<script>
(function(){
function setTop(){var h=document.querySelector('.mhp-hero,.mx-hier-hero');if(!h||window.scrollY>2)return;var t=Math.round(h.getBoundingClientRect().top);if(t>20&&t<400)document.documentElement.style.setProperty('--mhp-top',t+'px');}
setTop();window.addEventListener('load',setTop);window.addEventListener('resize',setTop);
document.querySelectorAll('.mhp [data-mhp-t]').forEach(function(b){b.addEventListener('click',function(){var box=b.closest('.mhp-card');box.querySelectorAll('[data-mhp-t]').forEach(function(x){x.classList.toggle('on',x===b);});box.querySelectorAll('[data-mhp-p]').forEach(function(p){p.hidden=p.getAttribute('data-mhp-p')!==b.getAttribute('data-mhp-t');});});});
document.querySelectorAll('.mhp-morebtn').forEach(function(b){var more=document.getElementById(b.getAttribute('aria-controls'));var t1=b.textContent,t2=b.getAttribute('data-less');b.addEventListener('click',function(){var open=more.hidden;more.hidden=!open;b.setAttribute('aria-expanded',open?'true':'false');b.textContent=open?t2:t1;if(!open)b.closest('.mhp-bio').scrollIntoView({behavior:'smooth'});});});
var links=[].slice.call(document.querySelectorAll('.mhp-sub a'));
if(links.length){window.addEventListener('scroll',function(){var cur=links[0];links.forEach(function(a){var s=document.querySelector(a.getAttribute('href'));if(s&&s.getBoundingClientRect().top<200)cur=a;});links.forEach(function(a){a.classList.toggle('on',a===cur);});},{passive:true});}
})();
</script>
HTML;
    }
}
