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
 * News list and News article, rendered for the "News list" and "News article" builder elements.
 * Each language has its own News category (alias news-ro, news-en, news-es); the page language picks it.
 * Photo galleries: the article's "Photo folder (gallery)" field, or a {gallery}folder{/gallery} tag left
 * in migrated text (looked up under images/photosx and images/galleries). A missing folder shows nothing.
 * Thumbnails are made once with GD into images/_thumbs.
 */
final class News
{
    private const PER_PAGE = 12;
    private const THUMB_W = 640;
    private const THUMB_H = 480;
    private const THUMBS_PER_REQUEST = 60;
    private const GALLERY_RX = '#(?:<p[^>]*>\s*)?\{gallery\}\s*([^{}<]+?)\s*\{/gallery\}(?:\s*</p>)?#i';

    private const I_SEARCH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>';
    private const I_FB = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8h3V4h-3c-2.8 0-4 1.7-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8.5c0-.3.2-.5.5-.5z"/></svg>';
    private const I_MAIL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>';
    private const I_LINK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>';
    private const I_PRINT = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>';

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

    /** News category of the page language (alias news-ro / news-en / news-es). */
    private static function categoryId(?string $lang = null): int
    {
        static $ids = [];
        $lang = $lang ?: self::lang();
        if (isset($ids[$lang])) {
            return $ids[$lang];
        }
        $db = self::db();
        $alias = 'news-' . $lang;
        $q = $db->getQuery(true)->select('id')->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))
            ->where('alias = :a')->where('published = 1')
            ->bind(':a', $alias);
        return $ids[$lang] = (int) $db->setQuery($q)->loadResult();
    }

    /** Pastoral letter categories of the page language: pastoral-letters-<lang> and its sub-categories (one per hierarch). */
    private static function pastoralCats(?string $lang = null): array
    {
        return self::sectionCats('pastoral-letters', $lang);
    }

    /** Words and Messages categories of the page language: words-of-wisdom-<lang> and its sub-categories. */
    private static function wordsCats(?string $lang = null): array
    {
        return self::sectionCats('words-of-wisdom', $lang);
    }

    /** A section's categories (root alias <prefix>-<lang> and everything below it). */
    private static function sectionCats(string $prefix, ?string $lang = null): array
    {
        static $ids = [];
        $lang = $lang ?: self::lang();
        if (isset($ids[$prefix][$lang])) {
            return $ids[$prefix][$lang];
        }
        $db = self::db();
        $alias = $prefix . '-' . $lang;
        $root = $db->setQuery($db->getQuery(true)->select(['lft', 'rgt'])->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))->where('alias = :a')->where('published = 1')
            ->bind(':a', $alias))->loadObject();
        if (!$root) {
            return $ids[$prefix][$lang] = [];
        }
        return $ids[$prefix][$lang] = array_map('intval', $db->setQuery($db->getQuery(true)->select('id')->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))->where('published = 1')
            ->where('lft >= ' . (int) $root->lft)->where('rgt <= ' . (int) $root->rgt))->loadColumn());
    }

    /** Field "Type" (pastoral-type) of the given articles: [id => value]. */
    private static function pastoralTypes(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $db = self::db();
        $q = $db->getQuery(true)->select(['v.item_id', 'v.value'])
            ->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->where('f.name = ' . $db->quote('pastoral-type'))
            ->whereIn('v.item_id', array_map('strval', $ids), ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->item_id] = (string) $r->value;
        }
        return $out;
    }

    /** Singular name of a pastoral type ("Pastorală", "Meditație"...). */
    private static function typeName(string $v): string
    {
        $k = 'MIT_O_PASTORAL_TYPE_' . strtoupper(str_replace('-', '_', $v ?: 'pastoral-letter'));
        $t = Text::_($k);
        return $t !== $k ? $t : '';
    }

    /** Base query: published, current, visible articles of one category (plus any extra categories). */
    private static function base(int $cat, array $extra = [])
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());
        $cats = array_values(array_unique(array_merge($cat ? [(int) $cat] : [], array_map('intval', $extra))));
        return $db->getQuery(true)
            ->from($db->quoteName('#__content', 'a'))
            ->where('a.catid IN (' . ($cats ? implode(',', $cats) : '0') . ')')
            ->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels ?: [1]);
    }

    private static function cols(bool $text = false): array
    {
        $c = ['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.images', 'a.publish_up', 'a.introtext'];
        if ($text) {
            $c[] = 'a.fulltext';
        }
        return $c;
    }

    private static function url(object $a): string
    {
        return Route::_(RouteHelper::getArticleRoute($a->id . ':' . $a->alias, (int) $a->catid, $a->language));
    }

    private static function date(?string $sql): string
    {
        if (!$sql) {
            return '';
        }
        $d = HTMLHelper::_('date', $sql, Text::_('MIT_NEWS_DATE_FORMAT'));
        // Romanian and Spanish write month names in lowercase (Joomla's month strings are capitalised)
        return in_array(self::lang(), ['ro', 'es'], true) ? mb_strtolower($d, 'UTF-8') : $d;
    }

    private static function isoDate(?string $sql): string
    {
        return $sql ? HTMLHelper::_('date', $sql, 'c') : '';
    }

    private static function image(object $a, bool $lead = false): array
    {
        $im = json_decode((string) $a->images, true) ?: [];
        $order = $lead ? ['fulltext', 'intro'] : ['intro', 'fulltext'];
        foreach ($order as $k) {
            $src = trim((string) ($im['image_' . $k] ?? ''));
            if ($src !== '') {
                $src = explode('#', $src, 2)[0];
                return [
                    'src' => preg_match('#^https?://#i', $src) ? $src : Uri::root(true) . '/' . ltrim($src, '/'),
                    'alt' => (string) ($im['image_' . $k . '_alt'] ?? ''),
                    'cap' => (string) ($im['image_' . $k . '_caption'] ?? ''),
                ];
            }
        }
        return [];
    }

    /** Count text: KEY_ONE for 1; in Romanian KEY_MANY ("20 de fotografii") from 20 on; else KEY. */
    private static function count(string $key, int $n): string
    {
        if ($n === 1) {
            return Text::sprintf($key . '_ONE', $n);
        }
        $r = $n % 100;
        if (self::lang() === 'ro' && ($r >= 20 || ($r === 0 && $n > 0))) {
            return Text::sprintf($key . '_MANY', $n);
        }
        return Text::sprintf($key, $n);
    }

    private static function plain(string $html): string
    {
        $html = preg_replace(self::GALLERY_RX, ' ', $html);
        $html = preg_replace('#\{[a-z]+[^}]*\}#i', ' ', $html);
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
        $words = count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY));
        return max(1, (int) round($words / 220));
    }

    /** The News list page of this language (menu item pointing at the category), for links and the breadcrumb. */
    private static function listUrl(int $cat): string
    {
        static $urls = [];
        if (isset($urls[$cat])) {
            return $urls[$cat];
        }
        $app = Factory::getApplication();
        foreach ($app->getMenu()->getItems(['component'], ['com_content']) as $item) {
            $q = $item->query ?? [];
            if (($q['view'] ?? '') === 'category' && (int) ($q['id'] ?? 0) === $cat) {
                return $urls[$cat] = Route::_('index.php?Itemid=' . (int) $item->id);
            }
        }
        return $urls[$cat] = Route::_(RouteHelper::getCategoryRoute($cat, Factory::getApplication()->getLanguage()->getTag()));
    }

    private static function crumbs(int $cat, bool $onList): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $parts = [];
        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        if ($home) {
            $parts[] = '<a href="' . $e(Route::_('index.php?Itemid=' . (int) $home->id)) . '">' . $e((string) $home->title) . '</a>';
        }
        $parts[] = $onList
            ? '<span aria-current="page">' . $e(Text::_('MIT_NEWS_TITLE')) . '</span>'
            : '<a href="' . $e(self::listUrl($cat)) . '">' . $e(Text::_('MIT_NEWS_TITLE')) . '</a>';
        return '<nav class="mpx-crumbs mnx-crumbs" aria-label="' . $e(Text::_('MIT_DIR_BREADCRUMB')) . '">'
            . implode('<span aria-hidden="true">›</span>', $parts) . '</nav>';
    }

    /** Tags of the given articles: [article id => [[id, title], ...]]. */
    private static function tagsOf(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $db = self::db();
        $q = $db->getQuery(true)->select(['m.content_item_id', 't.id', 't.title'])
            ->from($db->quoteName('#__contentitem_tag_map', 'm'))
            ->join('INNER', $db->quoteName('#__tags', 't') . ' ON t.id = m.tag_id')
            ->where('m.type_alias = ' . $db->quote('com_content.article'))
            ->where('t.published = 1')
            ->whereIn('m.content_item_id', array_map('intval', $ids))
            ->order('t.title');
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->content_item_id][] = [(int) $r->id, (string) $r->title];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ cards */

    /** A news-style card for any article row (id, title, alias, catid, language, images, publish_up, introtext); used by the Tag page. */
    public static function sharedCard(object $a): string
    {
        return self::card($a, ['textOnly' => true]);
    }

    /** The news styles and scripts, printed once per page; used by the Tag page. */
    public static function sharedCss(): string
    {
        return self::assets();
    }

    private static function card(object $a, array $opt = []): string
    {
        $e = [self::class, 'e'];
        $img = self::image($a);
        $plain = self::plain((string) $a->introtext);
        $ph = $img
            ? '<img src="' . $e($img['src']) . '" alt="" loading="lazy">'
            : '<span class="mnx-ph-empty" aria-hidden="true"></span>';
        // Text-only card (no photo box) when there is no image and the caller asks for it (Tag page)
        $phBox = !$img && !empty($opt['textOnly']) ? '' : '<span class="mnx-card-ph">' . $ph . '</span>';
        $kind = (string) ($opt['kind'] ?? '');
        $kindHtml = (string) ($opt['kindHtml'] ?? '');
        $who = (string) ($opt['who'] ?? '');
        return '<a class="mnx-card' . ($phBox === '' ? ' mnx-card-text' : '') . '" href="' . $e(self::url($a)) . '">' . $phBox . '<span class="mnx-card-b">'
            . ($kind !== '' ? '<span class="mnx-kind">' . $e($kind) . '</span>' : '')
            . ($kindHtml !== '' ? '<span class="mnx-meta mnx-kmeta">' . $kindHtml . '<time datetime="' . $e(self::isoDate($a->publish_up)) . '">' . $e(self::date($a->publish_up)) . '</time></span>' : '')
            . ($kindHtml === '' ? '<span class="mnx-meta"><time datetime="' . $e(self::isoDate($a->publish_up)) . '">' . $e(self::date($a->publish_up)) . '</time></span>' : '')
            . '<h3>' . $e((string) $a->title) . '</h3>'
            . ($plain !== '' ? '<span class="mnx-card-x">' . $e(self::clip($plain, 240)) . '</span>' : '')
            . ($who !== '' ? '<span class="mnx-who">' . $e($who) . '</span>' : '')
            . '</span></a>';
    }

    /** A news card with a raw type label (and optional author line); used by the Words and Messages pages. */
    public static function kindCard(object $a, string $kindHtml, string $who = ''): string
    {
        return self::card($a, ['kindHtml' => $kindHtml, 'who' => $who]);
    }

    /* ------------------------------------------------------------------ list */

    public static function listing(array $props): string
    {
        try {
            $cat = self::categoryId();
            if (!$cat) {
                return '';
            }
            return self::listHtml($cat, $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('News list: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function listingText(array $props): string
    {
        return '<p>' . self::e(Text::_('MIT_NEWS_TITLE')) . '</p>';
    }

    private static function listHtml(int $cat, array $props): string
    {
        $e = [self::class, 'e'];
        $db = self::db();
        $in = Factory::getApplication()->getInput();
        $search = trim(mb_substr($in->getString('q', ''), 0, 80));
        $year = $in->getInt('y', 0);
        $tag = $in->getInt('tag', 0);
        $page = max(1, $in->getInt('p', 1));
        // pastoral letters, meditations, messages and homilies of this language are listed with the news;
        // ?k=pastorale shows only them
        $pcats0 = self::pastoralCats();
        $wcats = self::wordsCats();
        $k = (string) $in->getCmd('k');
        $onlyP = $pcats0 && $k === 'pastorale';
        $onlyW = $wcats && $k === 'cuvinte';
        $onlyP = $onlyP || $onlyW;
        // $pcats = every category listed besides News (pastoral letters and words and messages), or the one section asked for
        $pcats = $onlyW ? $wcats : ($k === 'pastorale' ? $pcats0 : array_merge($pcats0, $wcats));
        $mainCat = $onlyP ? 0 : $cat;
        $allCats = array_merge([$cat], $pcats0, $wcats);

        // years with articles, newest first
        $yq = self::base($mainCat, $pcats)->select('DISTINCT YEAR(a.publish_up) AS y')->order('y DESC');
        $years = array_filter(array_map('intval', $db->setQuery($yq)->loadColumn()));

        // tags used on this language's news
        $tq = $db->getQuery(true)->select(['t.id', 't.title', 'COUNT(*) AS n'])
            ->from($db->quoteName('#__contentitem_tag_map', 'm'))
            ->join('INNER', $db->quoteName('#__tags', 't') . ' ON t.id = m.tag_id')
            ->join('INNER', $db->quoteName('#__content', 'a') . ' ON a.id = m.content_item_id')
            ->where('m.type_alias = ' . $db->quote('com_content.article'))
            ->where('t.published = 1')->where('a.catid IN (' . implode(',', array_map('intval', $allCats)) . ')')->where('a.state = 1')
            ->group(['t.id', 't.title'])->order('n DESC, t.title ASC');
        $tags = $db->setQuery($tq, 0, 14)->loadObjectList();

        $q = self::base($mainCat, $pcats);
        if ($search !== '') {
            $like = '%' . $db->escape($search, true) . '%';
            $q->where('(a.title LIKE ' . $db->quote($like, false) . ' OR a.introtext LIKE ' . $db->quote($like, false) . ' OR a.fulltext LIKE ' . $db->quote($like, false) . ')');
        }
        if ($year > 1900) {
            $q->where('YEAR(a.publish_up) = ' . (int) $year);
        }
        if ($tag > 0) {
            $q->join('INNER', $db->quoteName('#__contentitem_tag_map', 'tm') . ' ON tm.content_item_id = a.id AND tm.type_alias = ' . $db->quote('com_content.article') . ' AND tm.tag_id = ' . (int) $tag);
        }
        $cq = clone $q;
        $total = (int) $db->setQuery($cq->select('COUNT(*)'))->loadResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $db->setQuery($q->select(self::cols())->order('a.publish_up DESC, a.id DESC'), ($page - 1) * self::PER_PAGE, self::PER_PAGE)->loadObjectList();

        $base = self::listUrl($cat);
        $link = function (array $over) use ($base, $search, $year, $tag, $onlyP, $k) {
            $p = array_filter(array_merge(['q' => $search, 'y' => $year ?: '', 'tag' => $tag ?: '', 'k' => $onlyP ? $k : '', 'p' => ''], $over), fn ($v) => $v !== '' && $v !== 0 && $v !== null);
            return $base . ($p ? (strpos($base, '?') === false ? '?' : '&') . http_build_query($p) : '');
        };

        $title = trim((string) ($props['title'] ?? '')) ?: Text::_('MIT_NEWS_TITLE');
        $intro = trim((string) ($props['intro'] ?? '')) ?: Text::_('MIT_NEWS_INTRO');

        $yearOpts = '<option value="">' . $e(Text::_('MIT_NEWS_ALL_YEARS')) . '</option>';
        foreach ($years as $y) {
            $yearOpts .= '<option value="' . $y . '"' . ($y === $year ? ' selected' : '') . '>' . $y . '</option>';
        }
        $form = '<form class="mnx-controls" method="get" action="' . $e($base) . '" role="search">'
            . '<label class="mnx-search">' . self::I_SEARCH . '<input type="search" name="q" value="' . $e($search) . '" placeholder="' . $e(Text::_('MIT_NEWS_SEARCH')) . '" aria-label="' . $e(Text::_('MIT_NEWS_SEARCH')) . '"></label>'
            . '<select class="mpx-sel" name="y" aria-label="' . $e(Text::_('MIT_NEWS_YEAR')) . '" onchange="this.form.submit()">' . $yearOpts . '</select>'
            . ($tag ? '<input type="hidden" name="tag" value="' . (int) $tag . '">' : '')
            . ($onlyP ? '<input type="hidden" name="k" value="' . $e($k) . '">' : '')
            . '<button type="submit" class="mnx-go">' . $e(Text::_('MIT_NEWS_SEARCH_GO')) . '</button></form>';

        $chips = '';
        $hasP = $pcats0 && (int) $db->setQuery(self::base(0, $pcats0)->select('COUNT(*)'))->loadResult() > 0;
        $hasW = $wcats && (int) $db->setQuery(self::base(0, $wcats)->select('COUNT(*)'))->loadResult() > 0;
        if ($tags || $hasP || $hasW) {
            $chips = '<div class="mnx-chips"><a class="mpx-chip' . (!$tag && !$onlyP ? ' on' : '') . '" href="' . $e($link(['tag' => '', 'k' => '', 'p' => ''])) . '">' . $e(Text::_('MIT_NEWS_ALL')) . '</a>';
            if ($hasP) {
                $chips .= '<a class="mpx-chip' . ($k === 'pastorale' && !$tag ? ' on' : '') . '" href="' . $e($link(['tag' => '', 'k' => 'pastorale', 'p' => ''])) . '">' . $e(Text::_('MIT_PL_TITLE')) . '</a>';
            }
            if ($hasW) {
                $chips .= '<a class="mpx-chip' . ($k === 'cuvinte' && !$tag ? ' on' : '') . '" href="' . $e($link(['tag' => '', 'k' => 'cuvinte', 'p' => ''])) . '">' . $e(Text::_('MIT_WM_TITLE')) . '</a>';
            }
            foreach ($tags as $t) {
                $chips .= '<a class="mpx-chip' . ((int) $t->id === $tag ? ' on' : '') . '" href="' . $e($link(['tag' => (int) $t->id, 'k' => '', 'p' => ''])) . '">' . $e((string) $t->title) . '</a>';
            }
            $chips .= '</div>';
        }

        $types = self::pastoralTypes(array_map(fn ($r) => (int) $r->id, array_filter($rows, fn ($r) => in_array((int) $r->catid, $pcats, true))));
        $grid = '';
        foreach ($rows as $a) {
            $isP = in_array((int) $a->catid, $pcats, true);
            $grid .= self::card($a, $isP ? ['textOnly' => true, 'kind' => self::typeName($types[(int) $a->id] ?? 'pastoral-letter')] : []);
        }
        $filtered = $search !== '' || $year || $tag || $onlyP;
        $status = $filtered
            ? '<p class="mnx-status">' . $e(self::count('MIT_NEWS_FOUND', $total)) . ' <a href="' . $e($base) . '">' . $e(Text::_('MIT_NEWS_CLEAR')) . '</a></p>'
            : '';
        $body = $grid !== ''
            ? '<div class="mnx-grid">' . $grid . '</div>'
            : '<p class="mnx-empty">' . $e(Text::_($filtered ? 'MIT_NEWS_NO_MATCH' : 'MIT_NEWS_NONE')) . '</p>';

        // pagination: 1 … around current … last
        $pg = '';
        if ($pages > 1) {
            $nums = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn ($n) => $n >= 1 && $n <= $pages));
            sort($nums);
            $pg = '<nav class="mnx-pg" aria-label="' . $e(Text::_('MIT_NEWS_PAGES')) . '">';
            $pg .= $page > 1 ? '<a href="' . $e($link(['p' => $page - 1 > 1 ? $page - 1 : ''])) . '" aria-label="' . $e(Text::_('MIT_NEWS_PREV_PAGE')) . '">‹</a>' : '';
            $prev = 0;
            foreach ($nums as $n) {
                if ($prev && $n > $prev + 1) {
                    $pg .= '<span class="gap">…</span>';
                }
                $pg .= $n === $page ? '<span class="on" aria-current="page">' . $n . '</span>' : '<a href="' . $e($link(['p' => $n > 1 ? $n : ''])) . '">' . $n . '</a>';
                $prev = $n;
            }
            $pg .= $page < $pages ? '<a href="' . $e($link(['p' => $page + 1])) . '" aria-label="' . $e(Text::_('MIT_NEWS_NEXT_PAGE')) . '">›</a>' : '';
            $pg .= '</nav>';
        }

        $part = (string) ($props['part'] ?? '');
        $cls = !empty($props['class']) ? ' ' . $e((string) $props['class']) : '';
        if ($part === 'search') {
            return '<div class="mnx mnx-tools' . $cls . '">' . $form . '</div>';
        }
        if ($part === 'chips') {
            return $chips !== '' ? '<div class="mnx mnx-tools' . $cls . '">' . $chips . '</div>' : '';
        }
        if ($part === 'body') {
            return '<div class="mnx mnx-list' . $cls . '"><div class="mnx-listbody">' . $status . $body . $pg . '</div></div>';
        }
        return '<div class="mnx mnx-list' . $cls . '">'
            . '<div class="mnx-hero"><div class="mnx-hero-in">' . self::crumbs($cat, true)
            . '<div class="mnx-hero-row"><div class="mnx-hero-text"><h1 class="mpx-h1">' . $e($title) . '</h1><p class="mnx-intro">' . $e($intro) . '</p></div>' . $form . '</div>'
            . $chips . '</div></div>'
            . '<div class="mnx-listbody">' . $status . $body . $pg . '</div></div>';
    }

    /* ------------------------------------------------------------------ fields for page headers built in YOOtheme */

    /** Long date in the page language ("2 octombrie 2026"). */
    public static function headDate(object $a): string
    {
        return self::date($a->publish_up ?? null);
    }

    /** "5 min de citit" from the article text. */
    public static function headReading(object $a): string
    {
        $t = (string) ($a->introtext ?? '') . (string) ($a->fulltext ?? '');
        if ($t === '' && !empty($a->id)) {
            $db = self::db();
            $r = $db->setQuery($db->getQuery(true)->select(['a.introtext', 'a.' . $db->quoteName('fulltext')])->from($db->quoteName('#__content', 'a'))->where('a.id = ' . (int) $a->id))->loadObject();
            $t = $r ? (string) $r->introtext . (string) $r->fulltext : '';
        }
        $t = preg_replace('#<!--.*?-->#s', '', $t);
        return Text::sprintf('MIT_NEWS_MIN_READ', self::minutes(self::plain($t)));
    }

    /** Lead image of an article page (full text image, else the intro image): src, alt, cap. */
    public static function headLead(object $a): array
    {
        if (!isset($a->images) && !empty($a->id)) {
            $db = self::db();
            $a = $db->setQuery($db->getQuery(true)->select(['a.id', 'a.images'])->from($db->quoteName('#__content', 'a'))->where('a.id = ' . (int) $a->id))->loadObject() ?: $a;
        }
        return self::image($a, true);
    }

    /* ------------------------------------------------------------------ article */

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
            return self::articleHtml($a, $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('News article: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function articleText(array $props): string
    {
        return '';
    }

    private static function fieldValue(int $itemId, string $name): string
    {
        $db = self::db();
        $q = $db->getQuery(true)->select('v.value')
            ->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->where('f.name = :n')->where('v.item_id = :i');
        $iid = (string) $itemId;
        $q->bind(':n', $name)->bind(':i', $iid);
        return trim((string) $db->setQuery($q)->loadResult());
    }

    /** Photos of a gallery folder (relative paths under the site root), natural order. */
    private static function photos(array $folders): array
    {
        foreach ($folders as $f) {
            $f = trim(str_replace('\\', '/', $f), '/ ');
            if ($f === '' || strpos($f, '..') !== false || !preg_match('#^[A-Za-z0-9._\-/ ]+$#', $f) || strpos($f, 'images/') !== 0) {
                continue;
            }
            $dir = JPATH_ROOT . '/' . $f;
            if (!is_dir($dir)) {
                continue;
            }
            $files = [];
            foreach (scandir($dir) ?: [] as $n) {
                if ($n[0] !== '.' && $n[0] !== '_' && preg_match('/\.(jpe?g|png|webp)$/i', $n) && is_file($dir . '/' . $n)) {
                    $files[] = $n;
                }
            }
            if ($files) {
                natcasesort($files);
                return array_map(fn ($n) => $f . '/' . $n, array_values($files));
            }
        }
        return [];
    }

    /** 4:3 thumbnail in images/_thumbs (made once); falls back to the photo itself. */
    private static function thumb(string $rel): string
    {
        static $made = 0;
        $src = JPATH_ROOT . '/' . $rel;
        $out = 'images/_thumbs/' . preg_replace('#^images/#', '', $rel);
        $out = preg_replace('/\.(png|webp|jpe?g)$/i', '', $out) . '-' . self::THUMB_W . 'x' . self::THUMB_H . '.jpg';
        $abs = JPATH_ROOT . '/' . $out;
        if (is_file($abs) && filemtime($abs) >= filemtime($src)) {
            return $out;
        }
        if (!function_exists('imagecreatetruecolor') || $made >= self::THUMBS_PER_REQUEST) {
            return $rel;
        }
        $made++;
        try {
            $info = @getimagesize($src);
            if (!$info) {
                return $rel;
            }
            [$w, $h] = $info;
            switch ($info[2]) {
                case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($src); break;
                case IMAGETYPE_PNG: $im = @imagecreatefrompng($src); break;
                case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
                default: $im = false;
            }
            if (!$im) {
                return $rel;
            }
            // honour the camera's rotation flag
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $o = (int) (@exif_read_data($src)['Orientation'] ?? 1);
                $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($rot) {
                    $im = imagerotate($im, $rot, 0);
                    [$w, $h] = [imagesx($im), imagesy($im)];
                }
            }
            $tw = self::THUMB_W;
            $th = self::THUMB_H;
            $scale = max($tw / $w, $th / $h);
            $cw = (int) round($tw / $scale);
            $ch = (int) round($th / $scale);
            $sx = (int) max(0, ($w - $cw) / 2);
            $sy = (int) max(0, ($h - $ch) / 3); // keep a little more of the top (faces)
            $t = imagecreatetruecolor($tw, $th);
            imagefill($t, 0, 0, imagecolorallocate($t, 239, 227, 203));
            imagecopyresampled($t, $im, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
            if (!is_dir(dirname($abs))) {
                @mkdir(dirname($abs), 0755, true);
            }
            $ok = @imagejpeg($t, $abs, 82);
            imagedestroy($t);
            imagedestroy($im);
            return $ok ? $out : $rel;
        } catch (\Throwable $x) {
            return $rel;
        }
    }

    /** Photos of a folder (public wrapper, used by the gallery pages and the admin form). */
    public static function folderPhotos(string $folder): array
    {
        return self::photos([$folder]);
    }

    /** URL of the 4:3 thumbnail of a photo (made on first use). */
    public static function thumbUrl(string $rel): string
    {
        $th = self::thumb($rel);
        return Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $th)));
    }

    /** "20 de fotografii" etc. in the page language. */
    public static function photoCount(int $n): string
    {
        return self::count('MIT_NEWS_N_PHOTOS', $n);
    }

    /** Photo grid with lightbox (gallery section of a news article or a gallery page). */
    public static function galleryHtml(array $photos, bool $heading = true): string
    {
        if (!$photos) {
            return '';
        }
        $e = [self::class, 'e'];
        $g = '';
        foreach ($photos as $i => $p) {
            $full = Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $p)));
            $g .= '<a href="' . $e($full) . '" data-lb="' . $i . '"><img src="' . $e(self::thumbUrl($p)) . '" alt="' . $e(Text::sprintf('MIT_NEWS_PHOTO_N', $i + 1, count($photos))) . '" loading="lazy" width="' . self::THUMB_W . '" height="' . self::THUMB_H . '"></a>';
        }
        $out = '<section class="mnx-gal-wrap" id="mnx-gallery"' . ($heading ? ' aria-labelledby="mnx-galh"' : ' aria-label="' . $e(Text::_('MIT_NEWS_GALLERY')) . '"') . '>';
        if ($heading) {
            $out .= '<div class="mnx-sechead"><h2 id="mnx-galh" class="mpx-h2">' . $e(Text::_('MIT_NEWS_GALLERY')) . '</h2>'
                . '<span class="mnx-meta">' . $e(self::count('MIT_NEWS_N_PHOTOS', count($photos))) . ' · ' . $e(Text::_('MIT_NEWS_ENLARGE')) . '</span></div>';
        }
        return $out . '<div class="mnx-gal">' . $g . '</div></section>' . self::lightbox();
    }

    private static function lightbox(): string
    {
        $e = [self::class, 'e'];
        return '<div class="mnx-lb" hidden role="dialog" aria-modal="true" aria-label="' . $e(Text::_('MIT_NEWS_GALLERY')) . '">'
            . '<button type="button" class="mnx-lb-x" aria-label="' . $e(Text::_('MIT_NEWS_CLOSE')) . '">×</button>'
            . '<button type="button" class="mnx-lb-p" aria-label="' . $e(Text::_('MIT_NEWS_PREVIOUS')) . '">‹</button><img alt="">'
            . '<button type="button" class="mnx-lb-n" aria-label="' . $e(Text::_('MIT_NEWS_NEXT')) . '">›</button><div class="mnx-lb-c"></div></div>';
    }

    private static function articleHtml(object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $db = self::db();
        $cat = (int) $a->catid;
        $text = (string) $a->introtext . (trim((string) $a->fulltext) !== '' ? (string) $a->fulltext : '');

        // gallery: field first, then a {gallery} tag left in the text
        $folders = [];
        $field = self::fieldValue((int) $a->id, 'news-gallery-folder');
        if ($field !== '') {
            $folders[] = $field;
        }
        if (preg_match(self::GALLERY_RX, $text, $m)) {
            $f = trim(strip_tags($m[1]));
            $folders[] = 'images/photosx/' . $f;
            $folders[] = 'images/galleries/' . $f;
        }
        $text = preg_replace(self::GALLERY_RX, '', $text);
        // old Phoca Gallery codes (2017-2019 albums, not moved yet): hidden
        $text = preg_replace('#(?:<p[^>]*>\s*)?\{phocagallery[^}]*\}(?:\s*</p>)?#i', '', $text);
        // {youtube}ID or link{/youtube}: a privacy-friendly player
        $text = preg_replace_callback('#\{youtube\}\s*([^{}<]+?)\s*\{/youtube\}#i', function ($m) {
            $v = trim(strip_tags($m[1]));
            if (preg_match('#(?:v=|youtu\.be/|embed/|shorts/)([A-Za-z0-9_-]{11})#', $v, $x)) {
                $v = $x[1];
            }
            if (!preg_match('#^[A-Za-z0-9_-]{11}$#', $v)) {
                return '';
            }
            return '<span class="mnx-video"><iframe src="https://www.youtube-nocookie.com/embed/' . $v . '" title="YouTube" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></span>';
        }, $text);
        $photos = self::photos($folders);

        $plain = self::plain($text);
        try {
            $text = HTMLHelper::_('content.prepare', $text, null, 'com_content.article');
        } catch (\Throwable $x) {
        }

        $lead = self::image($a, true);
        $tags = self::tagsOf([(int) $a->id])[(int) $a->id] ?? [];
        $url = Uri::getInstance()->toString(['scheme', 'host', 'port']) . self::url($a);

        // previous (older) and next (newer) in this language
        $older = $db->setQuery(self::base($cat)->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language'])
            ->where('(a.publish_up < ' . $db->quote($a->publish_up) . ' OR (a.publish_up = ' . $db->quote($a->publish_up) . ' AND a.id < ' . (int) $a->id . '))')
            ->order('a.publish_up DESC, a.id DESC'), 0, 1)->loadObject();
        $newer = $db->setQuery(self::base($cat)->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language'])
            ->where('(a.publish_up > ' . $db->quote($a->publish_up) . ' OR (a.publish_up = ' . $db->quote($a->publish_up) . ' AND a.id > ' . (int) $a->id . '))')
            ->order('a.publish_up ASC, a.id ASC'), 0, 1)->loadObject();

        $latest = $db->setQuery(self::base($cat)->select(self::cols())->where('a.id <> ' . (int) $a->id)->order('a.publish_up DESC, a.id DESC'), 0, 4)->loadObjectList();

        // related: most shared tags, else the articles just before this one
        $related = [];
        if ($tags) {
            $rq = self::base($cat)->select(array_merge(self::cols(), ['COUNT(*) AS shared']))
                ->join('INNER', $db->quoteName('#__contentitem_tag_map', 'tm') . ' ON tm.content_item_id = a.id AND tm.type_alias = ' . $db->quote('com_content.article'))
                ->whereIn('tm.tag_id', array_map(fn ($t) => $t[0], $tags))
                ->where('a.id <> ' . (int) $a->id)
                ->group(self::cols())->order('shared DESC, a.publish_up DESC');
            $related = $db->setQuery($rq, 0, 3)->loadObjectList();
        }
        if (count($related) < 3) {
            $skip = array_merge([(int) $a->id], array_map(fn ($r) => (int) $r->id, $related));
            $fq = self::base($cat)->select(self::cols())->whereNotIn('a.id', $skip)
                ->where('a.publish_up <= ' . $db->quote($a->publish_up))->order('a.publish_up DESC, a.id DESC');
            $related = array_merge($related, $db->setQuery($fq, 1, 3 - count($related))->loadObjectList());
        }

        // ----- header and lead image
        $head = '<div class="mnx-band">' . self::crumbs($cat, false) . '</div>'
            . '<header class="mnx-head"><div class="mnx-meta"><time datetime="' . $e(self::isoDate($a->publish_up)) . '">' . $e(self::date($a->publish_up)) . '</time>'
            . '<span class="dot" aria-hidden="true"></span><span>' . $e(Text::sprintf('MIT_NEWS_MIN_READ', self::minutes($plain))) . '</span></div>'
            . '<h1 class="mnx-title">' . $e((string) $a->title) . '</h1></header>';
        if ($lead) {
            $head .= '<figure class="mnx-lead"><img src="' . $e($lead['src']) . '" alt="' . $e($lead['alt']) . '" fetchpriority="high">'
                . ($lead['cap'] !== '' ? '<figcaption>' . $e($lead['cap']) . '</figcaption>' : '') . '</figure>';
        }

        // ----- share
        $share = '<div class="mnx-share">'
            . '<a href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url) . '" target="_blank" rel="noopener" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '">' . self::I_FB . '</a>'
            . '<a href="mailto:?subject=' . rawurlencode((string) $a->title) . '&amp;body=' . rawurlencode($url) . '" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '">' . self::I_MAIL . '</a>'
            . '<button type="button" data-copy="' . $e($url) . '" data-done="' . $e(Text::_('MIT_NEWS_COPIED')) . '" aria-label="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '" title="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '">' . self::I_LINK . '</button>'
            . '<button type="button" data-print aria-label="' . $e(Text::_('MIT_NEWS_PRINT')) . '" title="' . $e(Text::_('MIT_NEWS_PRINT')) . '">' . self::I_PRINT . '</button></div>';

        // ----- main column
        $main = '<div class="mnx-prose">' . $text . '</div>';
        if ($photos) {
            $g = '';
            foreach ($photos as $i => $p) {
                $full = Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $p)));
                $th = self::thumb($p);
                $tu = Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $th)));
                $g .= '<a href="' . $e($full) . '" data-lb="' . $i . '"><img src="' . $e($tu) . '" alt="' . $e(Text::sprintf('MIT_NEWS_PHOTO_N', $i + 1, count($photos))) . '" loading="lazy" width="' . self::THUMB_W . '" height="' . self::THUMB_H . '"></a>';
            }
            $main .= '<section class="mnx-gal-wrap" id="mnx-gallery" aria-labelledby="mnx-galh"><div class="mnx-sechead"><h2 id="mnx-galh" class="mpx-h2">' . $e(Text::_('MIT_NEWS_GALLERY')) . '</h2>'
                . '<span class="mnx-meta">' . $e(self::count('MIT_NEWS_N_PHOTOS', count($photos))) . ' · ' . $e(Text::_('MIT_NEWS_ENLARGE')) . '</span></div><div class="mnx-gal">' . $g . '</div></section>';
        }
        $foot = '';
        if ($tags) {
            $foot .= '<div class="mnx-tags">';
            foreach ($tags as $t) {
                $foot .= '<a class="mpx-chip" href="' . $e(self::listUrl($cat) . (strpos(self::listUrl($cat), '?') === false ? '?' : '&') . 'tag=' . $t[0]) . '">' . $e($t[1]) . '</a>';
            }
            $foot .= '</div>';
        }
        $main .= '<div class="mnx-foot">' . $foot . $share . '</div>';
        if ($older || $newer) {
            $main .= '<nav class="mnx-pn" aria-label="' . $e(Text::_('MIT_NEWS_MORE')) . '">'
                . ($older ? '<a class="mnx-box" href="' . $e(self::url($older)) . '"><span class="mnx-meta">← ' . $e(Text::_('MIT_NEWS_PREVIOUS')) . '</span><b>' . $e((string) $older->title) . '</b></a>' : '<span></span>')
                . ($newer ? '<a class="mnx-box mnx-next" href="' . $e(self::url($newer)) . '"><span class="mnx-meta">' . $e(Text::_('MIT_NEWS_NEXT')) . ' →</span><b>' . $e((string) $newer->title) . '</b></a>' : '<span></span>')
                . '</nav>';
        }

        // ----- side column
        $side = '<div class="mnx-box"><h4>' . $e(Text::_('MIT_NEWS_SHARE')) . '</h4>' . $share . '</div>';
        if ($photos) {
            $side .= '<div class="mnx-box"><h4>' . $e(Text::_('MIT_NEWS_IN_ARTICLE')) . '</h4><a class="mnx-inart" href="#mnx-gallery">' . $e(Text::_('MIT_NEWS_GALLERY')) . ' · ' . $e(self::count('MIT_NEWS_N_PHOTOS', count($photos))) . '</a></div>';
        }
        if ($latest) {
            $side .= '<div class="mnx-box"><h4>' . $e(Text::_('MIT_NEWS_LATEST')) . '</h4>';
            foreach ($latest as $l) {
                $side .= '<a class="mnx-late" href="' . $e(self::url($l)) . '"><span class="mnx-meta">' . $e(self::date($l->publish_up)) . '</span><b>' . $e((string) $l->title) . '</b></a>';
            }
            $side .= '<a class="mnx-all" href="' . $e(self::listUrl($cat)) . '">' . $e(Text::_('MIT_NEWS_ALL_NEWS')) . ' →</a></div>';
        }

        $rel = '';
        if ($related) {
            $rel = '<section class="mnx-rel"><div class="mnx-sechead"><h2 class="mpx-h2">' . $e(Text::_('MIT_NEWS_RELATED')) . '</h2><a class="mnx-all" href="' . $e(self::listUrl($cat)) . '">' . $e(Text::_('MIT_NEWS_ALL_NEWS')) . ' →</a></div><div class="mnx-grid">';
            foreach ($related as $r) {
                $rel .= self::card($r);
            }
            $rel .= '</div></section>';
        }

        $lb = $photos ? '<div class="mnx-lb" hidden role="dialog" aria-modal="true" aria-label="' . $e(Text::_('MIT_NEWS_GALLERY')) . '">'
            . '<button type="button" class="mnx-lb-x" aria-label="' . $e(Text::_('MIT_NEWS_CLOSE')) . '">×</button>'
            . '<button type="button" class="mnx-lb-p" aria-label="' . $e(Text::_('MIT_NEWS_PREVIOUS')) . '">‹</button><img alt="">'
            . '<button type="button" class="mnx-lb-n" aria-label="' . $e(Text::_('MIT_NEWS_NEXT')) . '">›</button><div class="mnx-lb-c"></div></div>' : '';

        if (($props['part'] ?? '') === 'body') {
            $head = '';
        }
        return '<article class="mnx mnx-art' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">' . $head
            . '<div class="mnx-cols"><div class="mnx-main">' . $main . '</div><aside class="mnx-side">' . $side . '</aside></div>'
            . $rel . $lb . '</article>';
    }

    /* ------------------------------------------------------------------ CSS and JS (once per page) */

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>
/* News list and News article (Mitropolia plugin). Colors and fonts as in the mockups. */
.mnx{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-gold:#B99755;--mp-ink:#1B2A4A;--mp-muted:#6B6F7B;--mp-line:#E4D8BE;--mp-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:var(--mp-ink)}
.mnx a{text-decoration:none}
.mnx .mpx-h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:56px;line-height:1.05;margin:0 0 14px;color:var(--mp-navy)}
.mnx .mpx-h2{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:32px;line-height:1.2;color:var(--mp-navy);margin:0}
.mnx .mpx-crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:14px;color:#6B5A34}.mnx .mpx-crumbs a{color:var(--mp-blue)}
.mnx .mpx-chip{display:inline-flex;align-items:center;font:inherit;font-size:14px;font-weight:600;padding:7px 16px;border-radius:999px;border:1px solid #D9CBAA;background:#fff;color:var(--mp-blue)}
.mnx .mpx-chip.on,.mnx .mpx-chip:hover{background:var(--mp-blue);border-color:var(--mp-blue);color:#fff}
.mnx .mpx-sel{height:46px;border:1px solid #D9CBAA;border-radius:4px;padding:0 14px;font:inherit;font-size:16px;background:#fff;color:var(--mp-blue)}
.mnx-meta{font-size:14px;color:var(--mp-muted);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.mnx-meta .dot{width:3px;height:3px;border-radius:50%;background:var(--mp-gold)}
/* full-width ivory bands */
.mnx-hero,.mnx-band,.mnx-rel{position:relative;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax)}
.mnx-band{padding:22px 0;border-bottom:1px solid var(--mp-line)}
/* list */
.mnx-hero{border-bottom:1px solid var(--mp-line)}
.mnx-hero-in{padding:22px 0 48px}
.mnx-hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:32px;flex-wrap:wrap;margin-top:34px}
.mnx-hero-text{max-width:680px}
.mnx-intro{font-family:'Source Serif 4',Georgia,serif;font-size:20px;line-height:1.55;color:#3C4A66;margin:0}
.mnx-controls{display:flex;gap:8px;flex-wrap:wrap}
.mnx-search{position:relative;display:inline-flex;margin:0}
.mnx-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--mp-gold)}
.mnx-search input{height:46px;border:1px solid #D9CBAA;border-radius:4px;padding:0 14px 0 42px;font:inherit;font-size:16px;width:250px;background:#fff;box-sizing:border-box;color:var(--mp-ink)}
.mnx-go{height:46px;border:0;border-radius:4px;padding:0 18px;font:inherit;font-weight:600;font-size:15px;background:var(--mp-blue);color:#fff;cursor:pointer}
.mnx-go:hover{background:var(--mp-navy)}
.mnx-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:28px}
.mnx-listbody{padding:48px 0 96px}
.mnx-status{margin:0 0 20px;color:var(--mp-muted);font-size:15px}.mnx-status a{color:var(--mp-red);font-weight:600;margin-left:6px}
.mnx-empty{text-align:center;color:var(--mp-muted);font-size:18px;padding:40px 0}
.mnx-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px}
.mnx-card{background:#fff;border:1px solid var(--mp-line);border-radius:6px;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .2s,transform .2s;color:inherit}
.mnx-card:hover{box-shadow:0 14px 32px -18px rgba(23,46,92,.45);transform:translateY(-2px)}
.mnx-card-ph{display:block;aspect-ratio:16/9;background:linear-gradient(160deg,#F2E7D0,#E6D6B4);overflow:hidden}
.mnx-card-ph img{width:100%;height:100%;object-fit:cover;display:block}
.mnx-card-b{padding:20px 22px 24px;display:flex;flex-direction:column;gap:10px;flex:1}
.mnx-card-text{border-top:3px solid var(--mp-gold)}.mnx-kmeta{gap:10px}.mnx-who{margin-top:auto;padding-top:4px;font-size:14px;font-weight:600;color:#6B5A34}.mnx-kind{font-size:12px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-red);margin-bottom:-4px}.mnx-card-text .mnx-card-b{padding-top:24px}
.mnx-card h3{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:22px;line-height:1.25;color:var(--mp-navy);margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.mnx-card-x{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;font-family:'Source Serif 4',Georgia,serif;font-size:16px;line-height:1.55;color:#3C4A66}
.mnx-pg{display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:48px}
.mnx-pg a,.mnx-pg span{min-width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;border:1px solid var(--mp-line);background:#fff;font-weight:600;color:var(--mp-blue);padding:0 12px;box-sizing:border-box}
.mnx-pg .on{background:var(--mp-blue);color:#fff;border-color:var(--mp-blue)}.mnx-pg .gap{border:0;background:none}
.mnx-pg a:hover{border-color:var(--mp-blue)}
/* article */
.mnx-head{padding:56px 0 36px}
.mnx-head .mnx-meta{margin-bottom:18px}
.mnx-title{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:50px;line-height:1.12;color:var(--mp-navy);margin:0;max-width:1080px}
.mnx-lead{margin:0 0 56px}
.mnx-lead img{width:100%;aspect-ratio:21/9;object-fit:cover;border-radius:6px;display:block;background:var(--mp-ivory)}
.mnx-lead figcaption{font-size:14px;color:var(--mp-muted);margin-top:10px}
.mnx-cols{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:64px;align-items:start;padding-bottom:24px}
.mnx-main{max-width:820px}
.mnx-prose{font-family:'Source Serif 4',Georgia,serif;font-size:19px;line-height:1.75;color:var(--mp-ink)}
.mnx-prose p{margin:0 0 1.2em;text-align:left!important}
.mnx-prose a{color:var(--mp-red);text-decoration:underline;text-underline-offset:3px}
.mnx-prose img{max-width:100%;height:auto;border-radius:4px}
.mnx-video{display:block;position:relative;aspect-ratio:16/9;margin:1.4em 0;border-radius:6px;overflow:hidden;background:#000}.mnx-video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.mnx-prose h2,.mnx-prose h3{font-family:'Baskervville',Georgia,serif;font-weight:500;color:var(--mp-navy);line-height:1.25;margin:1.6em 0 .6em}
.mnx-prose blockquote{margin:1.8em 0;padding:24px 28px;background:var(--mp-ivory);border-left:3px solid var(--mp-gold);border-radius:4px;font-style:italic}
.mnx-prose ul,.mnx-prose ol{margin:0 0 1.2em;padding-left:1.4em}
.mnx-prose table{border-collapse:collapse;max-width:100%}.mnx-prose td,.mnx-prose th{border:1px solid var(--mp-line);padding:6px 10px}
.mnx-sechead{display:flex;justify-content:space-between;align-items:baseline;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.mnx-gal-wrap{margin-top:56px;scroll-margin-top:110px}
.mnx-gal{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.mnx-gal a{display:block;border-radius:4px;overflow:hidden;cursor:zoom-in;aspect-ratio:4/3;background:var(--mp-ivory)}
.mnx-gal img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s}
.mnx-gal a:hover img{transform:scale(1.03)}
.mnx-foot{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;margin-top:48px;padding-top:24px;border-top:1px solid var(--mp-line)}
.mnx-tags{display:flex;gap:8px;flex-wrap:wrap}
.mnx-share{display:flex;gap:8px}
.mnx-share a,.mnx-share button{width:40px;height:40px;border-radius:50%;border:1px solid #D9CBAA;display:inline-flex;align-items:center;justify-content:center;color:var(--mp-blue);background:#fff;cursor:pointer;padding:0;position:relative}
.mnx-share a:hover,.mnx-share button:hover{background:var(--mp-blue);color:#fff;border-color:var(--mp-blue)}
.mnx-share .done::after{content:attr(data-done);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--mp-navy);color:#fff;font-size:12px;padding:4px 8px;border-radius:4px;white-space:nowrap}
.mnx-pn{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:32px}
.mnx-box{display:block;background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:22px;color:inherit}
.mnx-box h4{margin:0 0 14px;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;font-family:'Source Sans 3',sans-serif}
.mnx-box b{display:block;margin-top:6px;font-weight:600;color:var(--mp-navy);line-height:1.35}
a.mnx-box:hover{border-color:var(--mp-gold)}
.mnx-next{text-align:right}.mnx-next .mnx-meta{justify-content:flex-end}
.mnx-side{position:sticky;top:100px;display:flex;flex-direction:column;gap:20px}
.mnx-inart{display:block;font-weight:600;color:var(--mp-blue)}
.mnx-late{display:block;padding:10px 0;border-top:1px solid var(--mp-ivory)}.mnx-late:first-of-type{border-top:0;padding-top:0}
.mnx-late .mnx-meta{font-size:13px}.mnx-late b{margin-top:2px;font-size:16px}
.mnx-late:hover b{color:var(--mp-red)}
.mnx-all{display:inline-block;margin-top:10px;font-weight:600;color:var(--mp-red)}
.mnx-rel{margin-top:72px;padding:72px 0 88px;border-top:1px solid var(--mp-line)}
.mnx-rel .mnx-sechead{margin-bottom:28px}.mnx-rel .mpx-h2{font-size:38px}.mnx-rel .mnx-all{margin-top:0}
.mnx-lb{position:fixed;inset:0;background:rgba(10,16,32,.94);z-index:2000;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px}
.mnx-lb[hidden]{display:none}
.mnx-lb img{max-width:92vw;max-height:82vh;border-radius:4px;object-fit:contain}
.mnx-lb button{background:none;border:0;color:#fff;font-size:40px;line-height:1;cursor:pointer;position:absolute;top:50%;transform:translateY(-50%);padding:20px}
.mnx-lb .mnx-lb-p{left:6px}.mnx-lb .mnx-lb-n{right:6px}
.mnx-lb .mnx-lb-x{top:14px;right:14px;transform:none;font-size:34px}
.mnx-lb-c{color:#EFE3CB;font-size:14px}
@media (max-width:1000px){.mnx-title{font-size:40px}.mnx-cols{grid-template-columns:minmax(0,1fr);gap:40px}.mnx-side{position:static}.mnx-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mnx-main{max-width:none}}
@media (max-width:640px){.mnx .mpx-h1{font-size:40px}.mnx-title{font-size:32px}.mnx-head{padding:36px 0 24px}.mnx-lead{margin-bottom:36px}.mnx-lead img{aspect-ratio:3/2}
.mnx-prose{font-size:18px}.mnx-grid{grid-template-columns:minmax(0,1fr)}.mnx-gal{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.mnx-pn{grid-template-columns:minmax(0,1fr)}.mnx-search,.mnx-search input{width:100%}.mnx-controls{width:100%}.mnx-controls .mnx-search{flex:1 1 100%}.mnx-rel .mpx-h2{font-size:30px}}
@media print{.mnx-band,.mnx-side,.mnx-foot,.mnx-pn,.mnx-rel,.mnx-gal-wrap{display:none!important}.mnx-cols{display:block}}
</style>
<script>(function(run){if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();})(function(){
(function(){
document.querySelectorAll('.mnx-share [data-copy]').forEach(function(b){b.addEventListener('click',function(){var u=b.getAttribute('data-copy');function ok(){b.classList.add('done');setTimeout(function(){b.classList.remove('done');},1600);}
if(navigator.clipboard){navigator.clipboard.writeText(u).then(ok,function(){prompt('',u);});}else{prompt('',u);}});});
document.querySelectorAll('.mnx-share [data-print]').forEach(function(b){b.addEventListener('click',function(){window.print();});});
var L=document.querySelector('.mnx-lb');if(!L)return;var I=L.querySelector('img'),C=L.querySelector('.mnx-lb-c'),G=[].slice.call(document.querySelectorAll('.mnx-gal [data-lb]')),k=0,last=null;
function show(n){k=(n+G.length)%G.length;I.src=G[k].href;C.textContent=(k+1)+' / '+G.length;if(L.hidden){last=document.activeElement;L.hidden=false;document.documentElement.style.overflow='hidden';L.querySelector('.mnx-lb-x').focus();}}
function hide(){L.hidden=true;I.removeAttribute('src');document.documentElement.style.overflow='';if(last)last.focus();}
G.forEach(function(a,n){a.addEventListener('click',function(ev){ev.preventDefault();show(n);});});
L.querySelector('.mnx-lb-x').onclick=hide;L.querySelector('.mnx-lb-p').onclick=function(){show(k-1);};L.querySelector('.mnx-lb-n').onclick=function(){show(k+1);};
L.addEventListener('click',function(ev){if(ev.target===L)hide();});
document.addEventListener('keydown',function(ev){if(L.hidden)return;if(ev.key==='Escape')hide();if(ev.key==='ArrowLeft')show(k-1);if(ev.key==='ArrowRight')show(k+1);});
var x0=null;L.addEventListener('touchstart',function(ev){x0=ev.touches[0].clientX;},{passive:true});L.addEventListener('touchend',function(ev){if(x0===null)return;var d=ev.changedTouches[0].clientX-x0;if(Math.abs(d)>50)show(k+(d<0?1:-1));x0=null;},{passive:true});
})();
});</script>
HTML;
    }

    /* ------------------------------------------------------------------ homepage */

    /**
     * Latest news of the page language for the homepage blocks.
     * Each row: title, url, date (long), short (day and month), kicker (first tag), text (plain, clipped), img.
     */
    public static function homeItems(int $n, int $offset = 0, int $clip = 220): array
    {
        $cat = self::categoryId();
        if (!$cat) {
            return [];
        }
        $db = self::db();
        $rows = $db->setQuery(self::base($cat)->select(self::cols())->order('a.publish_up DESC, a.id DESC'), max(0, $offset), max(1, $n))->loadObjectList();
        $tags = self::tagsOf(array_map(fn ($r) => (int) $r->id, $rows));
        $out = [];
        foreach ($rows as $a) {
            $img = self::image($a);
            $out[] = (object) [
                'title'  => (string) $a->title,
                'url'    => self::url($a),
                'date'   => self::date($a->publish_up),
                'short'  => self::shortDate($a->publish_up),
                'kicker' => $tags[(int) $a->id][0][1] ?? '',
                'text'   => self::clip(self::plain((string) $a->introtext), $clip),
                'img'    => $img['src'] ?? '',
                'alt'    => $img['alt'] ?? '',
            ];
        }
        return $out;
    }

    /** The News list page of the page language. */
    public static function homeListUrl(): string
    {
        return self::listUrl(self::categoryId());
    }

    /** "September 12" / "12 septembrie" / "12 de septiembre", without the year. */
    private static function shortDate(?string $sql): string
    {
        if (!$sql) {
            return '';
        }
        $l = self::lang();
        $fmt = $l === 'en' ? 'F j' : ($l === 'es' ? 'j \d\e F' : 'j F');
        $d = HTMLHelper::_('date', $sql, $fmt);
        return $l === 'en' ? $d : mb_strtolower($d, 'UTF-8');
    }
}
