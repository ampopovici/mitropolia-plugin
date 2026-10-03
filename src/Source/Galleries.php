<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Photo galleries ("Gallery list" and "Gallery page" builder elements).
 * A gallery is an "All languages" article in the galleries category; its titles (RO/EN/ES), date, photo
 * folder and related news item are fields. The list shows the title in the page language
 * (Spanish falls back to English, then Romanian). Uses the news styles (.mnx).
 */
final class Galleries
{
    private const PER_PAGE = 12;
    private const FIELDS = ['gallery-title-ro', 'gallery-title-en', 'gallery-title-es', 'gallery-date', 'gallery-folder', 'gallery-news'];
    private const UI = [
        'title'   => ['Galerii foto', 'Photo galleries', 'Galerías de fotos'],
        'intro'   => ['Fotografii de la slujbele, vizitele și evenimentele din viața Mitropoliei.', 'Photographs from the services, visits and events in the life of the Metropolia.', 'Fotografías de los oficios, visitas y eventos de la vida de la Metropolía.'],
        'none'    => ['Nu există încă galerii.', 'There are no galleries yet.', 'Todavía no hay galerías.'],
        'all'     => ['Toți anii', 'All years', 'Todos los años'],
        'year'    => ['Anul', 'Year', 'Año'],
        'pages'   => ['Pagini', 'Pages', 'Páginas'],
        'news'    => ['Citiți știrea', 'Read the news story', 'Leer la noticia'],
        'back'    => ['Toate galeriile', 'All galleries', 'Todas las galerías'],
        'enlarge' => ['apăsați pe o fotografie pentru a o mări', 'click a photo to enlarge it', 'pulse una foto para ampliarla'],
        'home'    => ['Acasă', 'Home', 'Inicio'],
    ];
    private const MON = [
        'ro' => ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    ];

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private static function lang(): string
    {
        $l = strtolower(substr(Factory::getApplication()->getLanguage()->getTag(), 0, 2));
        return in_array($l, ['ro', 'en', 'es'], true) ? $l : 'en';
    }

    private static function ui(string $k): string
    {
        return self::UI[$k][['ro' => 0, 'en' => 1, 'es' => 2][self::lang()]];
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function cat(): int
    {
        static $id = null;
        if ($id === null) {
            $db = self::db();
            $id = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__categories')->where('extension = ' . $db->quote('com_content'))
                ->where('alias = ' . $db->quote('galleries'))->where('published = 1'), 0, 1)->loadResult();
        }
        return $id;
    }

    private static function date(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
            return '';
        }
        $l = self::lang();
        $mon = self::MON[$l][(int) $m[2] - 1];
        if ($l === 'en') {
            return $mon . ' ' . (int) $m[3] . ', ' . $m[1];
        }
        return $l === 'es' ? (int) $m[3] . ' de ' . $mon . ' de ' . $m[1] : (int) $m[3] . ' ' . $mon . ' ' . $m[1];
    }

    private static function values(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $db = self::db();
        $q = $db->getQuery(true)->select(['v.item_id', 'f.name', 'v.value'])->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->whereIn('f.name', self::FIELDS, ParameterType::STRING)
            ->whereIn('v.item_id', array_map('strval', $ids), ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->item_id][$r->name] = trim((string) $r->value);
        }
        return $out;
    }

    /** Title in the page language: es -> en -> ro; en -> ro. */
    private static function title(array $v, string $fallback): string
    {
        $l = self::lang();
        foreach (array_unique([$l, $l === 'es' ? 'en' : 'ro', 'ro']) as $x) {
            if (($v['gallery-title-' . $x] ?? '') !== '') {
                return $v['gallery-title-' . $x];
            }
        }
        return $fallback;
    }

    /** Menu link of the galleries list in the page language. */
    private static function listUrl(): string
    {
        static $url = null;
        if ($url !== null) {
            return $url;
        }
        $app = Factory::getApplication();
        $tag = $app->getLanguage()->getTag();
        $best = null;
        foreach ($app->getMenu()->getItems(['component'], ['com_content']) as $item) {
            $q = $item->query ?? [];
            if (($q['view'] ?? '') === 'category' && (int) ($q['id'] ?? 0) === self::cat()) {
                if ($item->language === $tag) {
                    $best = $item;
                    break;
                }
                if ($item->language === '*' && !$best) {
                    $best = $item;
                }
            }
        }
        return $url = $best ? Route::_('index.php?Itemid=' . (int) $best->id) : Route::_(RouteHelper::getCategoryRoute(self::cat(), $tag));
    }

    private static function itemUrl(object $a): string
    {
        return Route::_(RouteHelper::getArticleRoute($a->id . ':' . $a->alias, (int) $a->catid, Factory::getApplication()->getLanguage()->getTag()));
    }

    private static function crumbs(string $here = ''): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        $parts = [];
        $parts[] = '<a href="' . $e($home ? Route::_('index.php?Itemid=' . (int) $home->id) : Uri::root()) . '">' . $e($home ? (string) $home->title : self::ui('home')) . '</a>';
        $parts[] = $here === '' ? '<span aria-current="page">' . $e(self::ui('title')) . '</span>' : '<a href="' . $e(self::listUrl()) . '">' . $e(self::ui('title')) . '</a>';
        if ($here !== '') {
            $parts[] = '<span aria-current="page">' . $e($here) . '</span>';
        }
        return '<nav class="mpx-crumbs mnx-crumbs" aria-label="Breadcrumb">' . implode('<span aria-hidden="true">›</span>', $parts) . '</nav>';
    }

    private static function base()
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());
        return $db->getQuery(true)->from($db->quoteName('#__content', 'a'))
            ->where('a.catid = ' . self::cat())->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels ?: [1]);
    }

    /* ------------------------------------------------------------------ list */

    public static function listing(array $props): string
    {
        try {
            return self::cat() ? self::listHtml($props) . News::sharedCss() : '';
        } catch (\Throwable $x) {
            Log::add('Gallery list: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function listingText(array $props): string
    {
        return '';
    }

    private static function listHtml(array $props): string
    {
        $e = [self::class, 'e'];
        $db = self::db();
        $in = Factory::getApplication()->getInput();
        $year = $in->getInt('y', 0);
        $page = max(1, $in->getInt('p', 1));
        $years = array_filter(array_map('intval', $db->setQuery(self::base()->select('DISTINCT YEAR(a.publish_up) AS y')->order('y DESC'))->loadColumn()));
        $q = self::base();
        if ($year > 1900) {
            $q->where('YEAR(a.publish_up) = ' . $year);
        }
        $total = (int) $db->setQuery((clone $q)->select('COUNT(*)'))->loadResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $db->setQuery($q->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.images', 'a.publish_up'])->order('a.publish_up DESC, a.id DESC'), ($page - 1) * self::PER_PAGE, self::PER_PAGE)->loadObjectList();
        $vals = self::values(array_map(fn ($r) => (int) $r->id, $rows));
        $base = self::listUrl();
        $link = function (array $p) use ($base, $year) {
            $p = array_filter(array_merge(['y' => $year ?: ''], $p), fn ($v) => $v !== '' && $v !== null);
            return $base . ($p ? (strpos($base, '?') === false ? '?' : '&') . http_build_query($p) : '');
        };
        $opts = '<option value="">' . $e(self::ui('all')) . '</option>';
        foreach ($years as $y) {
            $opts .= '<option value="' . $y . '"' . ($y === $year ? ' selected' : '') . '>' . $y . '</option>';
        }
        $form = $years ? '<form class="mnx-controls" method="get" action="' . $e($base) . '"><select class="mpx-sel" name="y" aria-label="' . $e(self::ui('year')) . '" onchange="this.form.submit()">' . $opts . '</select></form>' : '';
        $grid = '';
        foreach ($rows as $a) {
            $v = $vals[(int) $a->id] ?? [];
            $folder = (string) ($v['gallery-folder'] ?? '');
            $photos = $folder !== '' ? News::folderPhotos($folder) : [];
            $im = json_decode((string) $a->images, true) ?: [];
            $cover = trim(explode('#', (string) ($im['image_intro'] ?? ''), 2)[0]) ?: ($photos[0] ?? '');
            $ph = $cover !== '' && is_file(JPATH_ROOT . '/' . $cover) ? '<img src="' . $e(News::thumbUrl($cover)) . '" alt="" loading="lazy">' : '<span class="mnx-ph-empty" aria-hidden="true"></span>';
            $grid .= '<a class="mnx-card" href="' . $e(self::itemUrl($a)) . '"><span class="mnx-card-ph">' . $ph . '</span><span class="mnx-card-b">'
                . '<span class="mnx-meta"><time datetime="' . $e(substr((string) ($v['gallery-date'] ?? $a->publish_up), 0, 10)) . '">' . $e(self::date((string) ($v['gallery-date'] ?? $a->publish_up))) . '</time></span>'
                . '<h3>' . $e(self::title($v, (string) $a->title)) . '</h3>'
                . ($photos ? '<span class="mnx-card-x">' . $e(News::photoCount(count($photos))) . '</span>' : '') . '</span></a>';
        }
        $body = $grid !== '' ? '<div class="mnx-grid">' . $grid . '</div>' : '<p class="mnx-empty">' . $e(self::ui('none')) . '</p>';
        $pg = '';
        if ($pages > 1) {
            $pg = '<nav class="mnx-pg" aria-label="' . $e(self::ui('pages')) . '">' . ($page > 1 ? '<a href="' . $e($link(['p' => $page - 1 > 1 ? $page - 1 : ''])) . '">‹</a>' : '');
            for ($n = 1; $n <= $pages; $n++) {
                $pg .= $n === $page ? '<span class="on" aria-current="page">' . $n . '</span>' : '<a href="' . $e($link(['p' => $n > 1 ? $n : ''])) . '">' . $n . '</a>';
            }
            $pg .= ($page < $pages ? '<a href="' . $e($link(['p' => $page + 1])) . '">›</a>' : '') . '</nav>';
        }
        $title = trim((string) ($props['title'] ?? '')) ?: self::ui('title');
        $intro = trim((string) ($props['intro'] ?? '')) ?: self::ui('intro');
        $part = (string) ($props['part'] ?? '');
        if ($part === 'search') {
            return $form !== '' ? '<div class="mnx mnx-tools">' . $form . '</div>' : '';
        }
        if ($part === 'body') {
            return '<div class="mnx mnx-list' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '"><div class="mnx-listbody">' . $body . $pg . '</div></div>';
        }
        return '<div class="mnx mnx-list' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">'
            . '<div class="mnx-hero"><div class="mnx-hero-in">' . self::crumbs()
            . '<div class="mnx-hero-row"><div class="mnx-hero-text"><h1 class="mpx-h1">' . $e($title) . '</h1><p class="mnx-intro">' . $e($intro) . '</p></div>' . $form . '</div></div></div>'
            . '<div class="mnx-listbody">' . $body . $pg . '</div></div>';
    }

    /* ------------------------------------------------------------------ one gallery */

    public static function page(array $props): string
    {
        try {
            $in = Factory::getApplication()->getInput();
            $id = $in->get('option') === 'com_content' && $in->get('view') === 'article' ? $in->getInt('id') : 0;
            if (!$id || !self::cat()) {
                return '';
            }
            $db = self::db();
            $a = $db->setQuery(self::base()->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.publish_up'])->where('a.id = ' . $id))->loadObject();
            if (!$a) {
                return '';
            }
            return self::pageHtml($a, $props) . News::sharedCss();
        } catch (\Throwable $x) {
            Log::add('Gallery page: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function pageText(array $props): string
    {
        return '';
    }

    private static function pageHtml(object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $v = self::values([(int) $a->id])[(int) $a->id] ?? [];
        $title = self::title($v, (string) $a->title);
        $date = (string) ($v['gallery-date'] ?? $a->publish_up);
        $folder = (string) ($v['gallery-folder'] ?? '');
        $photos = $folder !== '' ? News::folderPhotos($folder) : [];
        // the related news story, in the page language when it has a translation
        $news = '';
        $nid = (int) ($v['gallery-news'] ?? 0);
        if ($nid) {
            $db = self::db();
            $tag = Factory::getApplication()->getLanguage()->getTag();
            $q = 'SELECT c.id, c.alias, c.catid, c.language, c.state FROM #__content c'
                . ' LEFT JOIN #__associations s ON s.id = ' . $nid . ' AND s.context = ' . $db->quote('com_content.item')
                . ' LEFT JOIN #__associations t ON t.' . $db->quoteName('key') . ' = s.' . $db->quoteName('key') . ' AND t.context = s.context'
                . ' WHERE (c.id = t.id OR c.id = ' . $nid . ') AND c.state = 1 ORDER BY (c.language = ' . $db->quote($tag) . ') DESC, (c.id = ' . $nid . ') DESC';
            $n = $db->setQuery($q, 0, 1)->loadObject();
            if ($n) {
                $news = '<a class="mpx-chip" href="' . $e(Route::_(RouteHelper::getArticleRoute($n->id . ':' . $n->alias, (int) $n->catid, $n->language))) . '">' . $e(self::ui('news')) . ' →</a>';
            }
        }
        return '<article class="mnx mnx-art' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">'
            . (($props['part'] ?? '') === 'body' ? '' : '<div class="mnx-band">' . self::crumbs($title) . '</div>')
            . '<header class="mnx-head"><div class="mnx-meta"><time datetime="' . $e(substr($date, 0, 10)) . '">' . $e(self::date($date)) . '</time>'
            . ($photos ? '<span class="dot" aria-hidden="true"></span><span>' . $e(News::photoCount(count($photos))) . ' · ' . $e(self::ui('enlarge')) . '</span>' : '') . '</div>'
            . '<h1 class="mnx-title">' . $e($title) . '</h1>'
            . ($news !== '' ? '<div class="mgx-rel">' . $news . '</div>' : '') . '</header>'
            . News::galleryHtml($photos, false)
            . '<div class="mgx-back"><a class="mnx-all" href="' . $e(self::listUrl()) . '">← ' . $e(self::ui('back')) . '</a></div>'
            . '<style>.mgx-rel{margin-top:14px}.mgx-back{margin:36px 0 10px}.mnx-art .mnx-gal-wrap{margin-top:8px}@media (min-width:960px){.mnx-art .mnx-gal{grid-template-columns:repeat(4,minmax(0,1fr))}}</style>'
            . '</article>';
    }
}
