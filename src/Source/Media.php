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
 * Video and audio ("Media list" and "Media item" builder elements).
 * An item is an "All languages" article in the Media category (alias media). Titles RO/EN/ES are fields
 * (video-title-*); a YouTube link (video-youtube) makes it a video, an audio file (audio-file) makes it audio.
 * video-length and video-news (related news story) serve both. Videos are embedded from YouTube only after the
 * visitor presses play (youtube-nocookie.com); until then the page shows a copy of the YouTube thumbnail kept on
 * this site, so the list page sends nothing to YouTube. Uses the news styles (.mnx).
 */
final class Media
{
    private const PER_PAGE = 12;
    private const FIELDS = ['video-title-ro', 'video-title-en', 'video-title-es', 'video-youtube', 'video-length', 'video-news', 'audio-file'];
    private const UI = [
        'title'   => ['Media', 'Media', 'Multimedia'],
        'intro'   => ['Înregistrări video și audio: slujbe, cuvinte de învățătură, interviuri și evenimente din viața Mitropoliei.', 'Video and audio recordings: services, homilies, interviews and events in the life of the Metropolia.', 'Grabaciones de vídeo y audio: oficios, homilías, entrevistas y eventos de la vida de la Metropolía.'],
        'none'    => ['Nu există încă înregistrări.', 'There are no recordings yet.', 'Todavía no hay grabaciones.'],
        'nonet'   => ['Nu există încă înregistrări de acest tip.', 'There are no recordings of this kind yet.', 'Todavía no hay grabaciones de este tipo.'],
        'all'     => ['Toate', 'All', 'Todo'],
        'video'   => ['Video', 'Video', 'Vídeo'],
        'audio'   => ['Audio', 'Audio', 'Audio'],
        'years'   => ['Toți anii', 'All years', 'Todos los años'],
        'year'    => ['Anul', 'Year', 'Año'],
        'pages'   => ['Pagini', 'Pages', 'Páginas'],
        'news'    => ['Citiți știrea', 'Read the news story', 'Leer la noticia'],
        'back'    => ['Toate înregistrările', 'All recordings', 'Todas las grabaciones'],
        'more'    => ['Alte înregistrări', 'More recordings', 'Más grabaciones'],
        'play'    => ['Redă videoclipul', 'Play the video', 'Reproducir el vídeo'],
        'consent' => ['Videoclipul se încarcă de pe YouTube când apăsați pe el. YouTube poate folosi cookie-uri.', 'The video loads from YouTube when you press play. YouTube may use cookies.', 'El vídeo se carga desde YouTube al pulsar reproducir. YouTube puede usar cookies.'],
        'onyt'    => ['Deschideți pe YouTube', 'Open on YouTube', 'Abrir en YouTube'],
        'dlaudio' => ['Descarcă fișierul audio', 'Download the audio file', 'Descargar el archivo de audio'],
        'noaudio' => ['Browserul nu poate reda acest fișier.', 'Your browser cannot play this file.', 'El navegador no puede reproducir este archivo.'],
        'home'    => ['Acasă', 'Home', 'Inicio'],
    ];
    private const MON = [
        'ro' => ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    ];
    private const I_PLAY = '<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>';
    private const I_WAVE = '<svg width="56" height="56" viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M8 24v8M16 18v20M24 12v32M32 20v16M40 14v28M48 24v8"/></svg>';

    /* ------------------------------------------------------------------ helpers */

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
                ->where('alias = ' . $db->quote('media'))->where('published = 1'), 0, 1)->loadResult();
        }
        return $id;
    }

    private static function date(string $sql): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $sql)) {
            return '';
        }
        try {
            $d = Factory::getDate($sql, 'UTC');
            $d->setTimezone(new \DateTimeZone(Factory::getApplication()->get('offset', 'UTC')));
        } catch (\Throwable $x) {
            return '';
        }
        $l = self::lang();
        $mon = self::MON[$l][(int) $d->format('n', true) - 1];
        $day = (int) $d->format('j', true);
        $y = $d->format('Y', true);
        return $l === 'en' ? $mon . ' ' . $day . ', ' . $y : ($l === 'es' ? $day . ' de ' . $mon . ' de ' . $y : $day . ' ' . $mon . ' ' . $y);
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

    /** All published items, newest first, with their fields and kind. */
    private static function items(): array
    {
        $db = self::db();
        $rows = $db->setQuery(self::base()->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.images', 'a.publish_up'])->order('a.publish_up DESC, a.id DESC'))->loadObjectList();
        $vals = self::values(array_map(fn ($r) => (int) $r->id, $rows));
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::shape($r, $vals[(int) $r->id] ?? []);
        }
        return array_values(array_filter($out, fn ($m) => $m->kind !== ''));
    }

    private static function shape(object $r, array $v): object
    {
        $yt = self::youtubeId((string) ($v['video-youtube'] ?? ''));
        $audio = self::filePath((string) ($v['audio-file'] ?? ''));
        $im = json_decode((string) ($r->images ?? ''), true) ?: [];
        return (object) [
            'id'     => (int) $r->id,
            'alias'  => (string) $r->alias,
            'catid'  => (int) $r->catid,
            'title'  => self::title($v, (string) $r->title),
            'date'   => (string) $r->publish_up,
            'yt'     => $yt,
            'audio'  => $audio,
            'kind'   => $yt !== '' ? 'video' : ($audio !== '' ? 'audio' : ''),
            'length' => trim((string) ($v['video-length'] ?? '')),
            'news'   => (int) ($v['video-news'] ?? 0),
            'image'  => trim(explode('#', (string) ($im['image_intro'] ?? ''), 2)[0]),
        ];
    }

    /** Title in the page language: es -> en -> ro; en -> ro. */
    private static function title(array $v, string $fallback): string
    {
        $l = self::lang();
        foreach (array_unique([$l, $l === 'es' ? 'en' : 'ro', 'ro']) as $x) {
            if (($v['video-title-' . $x] ?? '') !== '') {
                return $v['video-title-' . $x];
            }
        }
        return $fallback;
    }

    public static function youtubeId(string $url): string
    {
        $url = trim($url);
        if (preg_match('#^[A-Za-z0-9_-]{11}$#', $url)) {
            return $url;
        }
        if (preg_match('#(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})#', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function filePath(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        $j = json_decode($v, true);
        if (is_array($j)) {
            $v = (string) ($j['file'] ?? $j['url'] ?? $j['imagefile'] ?? '');
        }
        return trim(explode('#', $v, 2)[0]);
    }

    private static function src(string $path): string
    {
        if ($path === '') {
            return '';
        }
        return preg_match('#^https?://#i', $path) ? $path : Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    /**
     * A copy of the YouTube thumbnail kept on this site (images/media/youtube/<id>.jpg), fetched once.
     * At most $budget downloads per page view, so a long list never stalls.
     */
    private static function ytThumb(string $id, int &$budget): string
    {
        $rel = 'images/media/youtube/' . $id . '.jpg';
        $abs = JPATH_ROOT . '/' . $rel;
        if (is_file($abs)) {
            return $rel;
        }
        if ($budget <= 0) {
            return '';
        }
        $budget--;
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return '';
        }
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'Mitropolia']]);
        foreach (['hq720', 'sddefault', 'mqdefault'] as $name) {
            $data = @file_get_contents('https://i.ytimg.com/vi/' . $id . '/' . $name . '.jpg', false, $ctx);
            if ($data !== false && strlen($data) > 2000 && @getimagesizefromstring($data)) {
                @file_put_contents($abs, $data);
                @chmod($abs, 0644);
                return is_file($abs) ? $rel : '';
            }
        }
        return '';
    }

    private static function picture(object $m, int &$budget): string
    {
        $img = $m->image !== '' && is_file(JPATH_ROOT . '/' . $m->image) ? $m->image : ($m->kind === 'video' ? self::ytThumb($m->yt, $budget) : '');
        return $img;
    }

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

    private static function itemUrl(object $m): string
    {
        return Route::_(RouteHelper::getArticleRoute($m->id . ':' . $m->alias, $m->catid, Factory::getApplication()->getLanguage()->getTag()));
    }

    private static function crumbs(string $here = ''): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        $parts = ['<a href="' . $e($home ? Route::_('index.php?Itemid=' . (int) $home->id) : Uri::root()) . '">' . $e($home ? (string) $home->title : self::ui('home')) . '</a>'];
        $parts[] = $here === '' ? '<span aria-current="page">' . $e(self::ui('title')) . '</span>' : '<a href="' . $e(self::listUrl()) . '">' . $e(self::ui('title')) . '</a>';
        if ($here !== '') {
            $parts[] = '<span aria-current="page">' . $e($here) . '</span>';
        }
        return '<nav class="mpx-crumbs mnx-crumbs" aria-label="Breadcrumb">' . implode('<span aria-hidden="true">›</span>', $parts) . '</nav>';
    }

    private static function card(object $m, int &$budget): string
    {
        $e = [self::class, 'e'];
        $img = self::picture($m, $budget);
        $ph = $img !== ''
            ? '<img src="' . $e(self::src($img)) . '" alt="" loading="lazy">'
            : '<span class="mmd-art" aria-hidden="true">' . self::I_WAVE . '</span>';
        return '<a class="mnx-card mmd-card" href="' . $e(self::itemUrl($m)) . '"><span class="mnx-card-ph mmd-ph">' . $ph
            . '<span class="mmd-badge" aria-hidden="true">' . self::I_PLAY . '</span>'
            . ($m->length !== '' ? '<span class="mmd-len">' . $e($m->length) . '</span>' : '') . '</span>'
            . '<span class="mnx-card-b"><span class="mnx-meta"><span class="mmd-kind">' . $e(self::ui($m->kind)) . '</span><span class="dot" aria-hidden="true"></span><time datetime="' . $e(substr($m->date, 0, 10)) . '">' . $e(self::date($m->date)) . '</time></span>'
            . '<h3>' . $e($m->title) . '</h3></span></a>';
    }

    /* ------------------------------------------------------------------ list */

    public static function listing(array $props): string
    {
        try {
            return self::cat() ? self::listHtml($props) . News::sharedCss() . self::css() : '';
        } catch (\Throwable $x) {
            Log::add('Media list: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
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
        $in = Factory::getApplication()->getInput();
        $kind = in_array($in->getCmd('t'), ['video', 'audio'], true) ? $in->getCmd('t') : '';
        $year = $in->getInt('y', 0);
        $page = max(1, $in->getInt('p', 1));
        $all = self::items();
        $byYear = $year > 1900 ? array_values(array_filter($all, fn ($m) => (int) substr($m->date, 0, 4) === $year)) : $all;
        $list = $kind ? array_values(array_filter($byYear, fn ($m) => $m->kind === $kind)) : $byYear;
        $total = count($list);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $years = array_values(array_unique(array_map(fn ($m) => (int) substr($m->date, 0, 4), $all)));
        rsort($years);

        $base = self::listUrl();
        $link = function (array $p) use ($base, $year, $kind) {
            $p = array_filter(array_merge(['t' => $kind, 'y' => $year ?: ''], $p), fn ($v) => $v !== '' && $v !== null);
            return $base . ($p ? (strpos($base, '?') === false ? '?' : '&') . http_build_query($p) : '');
        };

        // type chips, only when both kinds exist
        $chips = '';
        $kinds = array_count_values(array_map(fn ($m) => $m->kind, $byYear));
        if (count(array_unique(array_map(fn ($m) => $m->kind, $all))) > 1) {
            $chips = '<div class="mnx-chips">';
            foreach (['' => 'all', 'video' => 'video', 'audio' => 'audio'] as $k => $label) {
                $n = $k === '' ? count($byYear) : ($kinds[$k] ?? 0);
                $chips .= '<a class="mpx-chip' . ($kind === $k ? ' on' : '') . '" href="' . $e($link(['t' => $k, 'p' => ''])) . '"' . ($kind === $k ? ' aria-current="true"' : '') . '>' . $e(self::ui($label)) . ' <span class="mmd-n">' . $n . '</span></a>';
            }
            $chips .= '</div>';
        }
        $opts = '<option value="">' . $e(self::ui('years')) . '</option>';
        foreach ($years as $y) {
            $opts .= '<option value="' . $y . '"' . ($y === $year ? ' selected' : '') . '>' . $y . '</option>';
        }
        $form = count($years) > 1 ? '<form class="mnx-controls" method="get" action="' . $e($base) . '">' . ($kind ? '<input type="hidden" name="t" value="' . $e($kind) . '">' : '')
            . '<select class="mpx-sel" name="y" aria-label="' . $e(self::ui('year')) . '" onchange="this.form.submit()">' . $opts . '</select></form>' : '';

        $budget = 4;
        $grid = '';
        foreach ($rows as $m) {
            $grid .= self::card($m, $budget);
        }
        $body = $grid !== '' ? '<div class="mnx-grid">' . $grid . '</div>' : '<p class="mnx-empty">' . $e(self::ui($all ? 'nonet' : 'none')) . '</p>';
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
        return '<div class="mnx mnx-list mmd' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">'
            . '<div class="mnx-hero"><div class="mnx-hero-in">' . self::crumbs()
            . '<div class="mnx-hero-row"><div class="mnx-hero-text"><h1 class="mpx-h1">' . $e($title) . '</h1><p class="mnx-intro">' . $e($intro) . '</p></div>' . $form . '</div>'
            . $chips . '</div></div>'
            . '<div class="mnx-listbody">' . $body . $pg . '</div></div>';
    }

    /* ------------------------------------------------------------------ one item */

    public static function page(array $props): string
    {
        try {
            $in = Factory::getApplication()->getInput();
            $id = $in->get('option') === 'com_content' && $in->get('view') === 'article' ? $in->getInt('id') : 0;
            if (!$id || !self::cat()) {
                return '';
            }
            $db = self::db();
            $a = $db->setQuery(self::base()->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.images', 'a.publish_up', 'a.introtext', $db->quoteName('a.fulltext')])->where('a.id = ' . $id))->loadObject();
            if (!$a) {
                return '';
            }
            $m = self::shape($a, self::values([$id])[$id] ?? []);
            return self::pageHtml($m, $a, $props) . News::sharedCss() . self::css();
        } catch (\Throwable $x) {
            Log::add('Media page: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function pageText(array $props): string
    {
        return '';
    }

    private static function pageHtml(object $m, object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $budget = 2;
        $img = self::picture($m, $budget);

        // related news story, in the page language when it has a translation
        $news = '';
        if ($m->news) {
            $db = self::db();
            $tag = Factory::getApplication()->getLanguage()->getTag();
            $nid = $m->news;
            $q = 'SELECT c.id, c.alias, c.catid, c.language FROM #__content c'
                . ' LEFT JOIN #__associations s ON s.id = ' . $nid . ' AND s.context = ' . $db->quote('com_content.item')
                . ' LEFT JOIN #__associations t ON t.' . $db->quoteName('key') . ' = s.' . $db->quoteName('key') . ' AND t.context = s.context'
                . ' WHERE (c.id = t.id OR c.id = ' . $nid . ') AND c.state = 1 ORDER BY (c.language = ' . $db->quote($tag) . ') DESC, (c.id = ' . $nid . ') DESC';
            $n = $db->setQuery($q, 0, 1)->loadObject();
            if ($n) {
                $news = '<a class="mpx-chip" href="' . $e(Route::_(RouteHelper::getArticleRoute($n->id . ':' . $n->alias, (int) $n->catid, $n->language))) . '">' . $e(self::ui('news')) . ' →</a>';
            }
        }

        if ($m->kind === 'video') {
            $poster = $img !== '' ? '<img src="' . $e(self::src($img)) . '" alt="">' : '<span class="mmd-art" aria-hidden="true"></span>';
            $player = '<div class="mmd-player"><button type="button" class="mmd-yt" data-yt="' . $e($m->yt) . '" data-title="' . $e($m->title) . '" aria-label="' . $e(self::ui('play') . ': ' . $m->title) . '">'
                . $poster . '<span class="mmd-big" aria-hidden="true">' . self::I_PLAY . '</span></button></div>'
                . '<p class="mmd-note">' . $e(self::ui('consent')) . ' <a href="' . $e('https://www.youtube.com/watch?v=' . $m->yt) . '" target="_blank" rel="noopener">' . $e(self::ui('onyt')) . ' ↗</a></p>';
        } else {
            $src = self::src($m->audio);
            $art = $img !== '' ? '<img src="' . $e(self::src($img)) . '" alt="">' : '<span class="mmd-art" aria-hidden="true">' . self::I_WAVE . '</span>';
            $player = '<div class="mmd-audio"><div class="mmd-cover">' . $art . '</div><div class="mmd-ctrl">'
                . '<audio controls preload="metadata" data-src="' . $e($src) . '">' . $e(self::ui('noaudio')) . '</audio>'
                . '<a class="mnx-all" href="' . $e($src) . '" download>' . $e(self::ui('dlaudio')) . '</a></div></div>';
        }

        $body = trim((string) $a->introtext . (string) $a->fulltext);
        $desc = '';
        if (trim(strip_tags($body)) !== '') {
            try {
                $body = HTMLHelper::_('content.prepare', $body, null, 'com_content.article');
            } catch (\Throwable $x) {
            }
            $desc = '<div class="mnx-prose mmd-desc">' . $body . '</div>';
        }

        // more recordings
        $more = '';
        $others = array_slice(array_values(array_filter(self::items(), fn ($o) => $o->id !== $m->id)), 0, 3);
        if ($others) {
            $b = 2;
            foreach ($others as $o) {
                $more .= self::card($o, $b);
            }
            $more = '<section class="mnx-rel"><div class="mnx-sechead"><h2 class="mpx-h2">' . $e(self::ui('more')) . '</h2><a class="mnx-all" href="' . $e(self::listUrl()) . '">' . $e(self::ui('back')) . ' →</a></div><div class="mnx-grid">' . $more . '</div></section>';
        }

        return '<article class="mnx mnx-art mmd' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">'
            . '<div class="mnx-band">' . self::crumbs($m->title) . '</div>'
            . '<header class="mnx-head"><div class="mnx-meta"><span class="mmd-kind">' . $e(self::ui($m->kind)) . '</span><span class="dot" aria-hidden="true"></span><time datetime="' . $e(substr($m->date, 0, 10)) . '">' . $e(self::date($m->date)) . '</time>'
            . ($m->length !== '' ? '<span class="dot" aria-hidden="true"></span><span>' . $e($m->length) . '</span>' : '') . '</div>'
            . '<h1 class="mnx-title">' . $e($m->title) . '</h1>'
            . ($news !== '' ? '<div class="mmd-rel">' . $news . '</div>' : '') . '</header>'
            . '<div class="mmd-main">' . $player . $desc . '</div>'
            . $more
            . '</article>';
    }

    /* ------------------------------------------------------------------ assets */

    private static function css(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>
/* Media list and Media item (Mitropolia plugin) on top of the news styles */
.mmd .mmd-n{font-weight:400;opacity:.7;margin-left:6px;font-variant-numeric:tabular-nums}
.mmd .mnx-chips{margin-top:28px}
.mmd-ph{position:relative}
.mmd-art{display:flex;width:100%;height:100%;align-items:center;justify-content:center;background:linear-gradient(150deg,#172E5C,#203D78);color:#B99755}
.mmd-badge{position:absolute;left:14px;bottom:14px;width:44px;height:44px;border-radius:50%;background:rgba(23,46,92,.88);color:#fff;display:flex;align-items:center;justify-content:center;transition:background .2s}
.mmd-badge svg{width:20px;height:20px;margin-left:2px}
.mmd-card:hover .mmd-badge{background:#A32D36}
.mmd-len{position:absolute;right:12px;bottom:12px;background:rgba(10,16,32,.82);color:#fff;font-size:13px;font-weight:600;padding:2px 7px;border-radius:3px;font-variant-numeric:tabular-nums}
.mmd-kind{font-size:12px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#A32D36}
.mmd-rel{margin-top:16px}
.mmd-main{max-width:960px;padding-bottom:24px}
.mmd-player{position:relative;aspect-ratio:16/9;border-radius:6px;overflow:hidden;background:#0B1326}
.mmd-player iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.mmd .mmd-yt{position:absolute;inset:0;width:100%;height:100%;padding:0;border:0;background:#0B1326;cursor:pointer;display:block}
.mmd-yt img{width:100%;height:100%;object-fit:cover;display:block;opacity:.92;transition:opacity .2s}
.mmd-yt:hover img{opacity:1}
.mmd-big{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:84px;height:84px;border-radius:50%;background:rgba(163,45,54,.94);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 30px rgba(0,0,0,.35);transition:transform .2s}
.mmd-big svg{width:36px;height:36px;margin-left:4px}
.mmd-yt:hover .mmd-big,.mmd-yt:focus-visible .mmd-big{transform:translate(-50%,-50%) scale(1.06)}
.mmd-yt:focus-visible{outline:3px solid #B99755;outline-offset:-3px}
.mmd-note{font-size:14px;color:#6B6F7B;margin:12px 0 0}.mmd-note a{color:#203D78;font-weight:600}
.mmd-audio{display:grid;grid-template-columns:220px minmax(0,1fr);gap:28px;align-items:center;background:#EFE3CB;border:1px solid #E4D8BE;border-radius:6px;padding:24px}
.mmd-cover{aspect-ratio:1;border-radius:4px;overflow:hidden}
.mmd-cover img{width:100%;height:100%;object-fit:cover;display:block}
.mmd-ctrl{display:flex;flex-direction:column;gap:14px;min-width:0}
.mmd-ctrl audio{width:100%}
.mmd-desc{margin-top:36px;max-width:820px}
@media (max-width:640px){.mmd-audio{grid-template-columns:minmax(0,1fr);padding:16px}.mmd-cover{max-width:220px}.mmd-big{width:64px;height:64px}.mmd-big svg{width:28px;height:28px}}
@media (prefers-reduced-motion:reduce){.mmd-big,.mmd-badge,.mmd-yt img{transition:none}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
document.querySelectorAll('.mmd [data-yt]').forEach(function(b){b.addEventListener('click',function(){
 var f=document.createElement('iframe');
 f.setAttribute('s'+'rc','https://www.youtube-nocookie.com/embed/'+b.dataset.yt+'?autoplay=1&rel=0');
 f.setAttribute('title',b.dataset.title||'YouTube');
 f.setAttribute('allow','accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
 f.setAttribute('allowfullscreen','');
 b.parentNode.replaceChild(f,b);});});
document.querySelectorAll('.mmd audio[data-src]').forEach(function(a){a.setAttribute('s'+'rc',a.dataset.src);});
});
</script>
HTML;
    }
}
