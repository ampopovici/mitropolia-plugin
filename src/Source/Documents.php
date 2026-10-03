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
 * Documents ("Documents list" and "Document" builder elements), as in the approved mockup of Oct 2.
 * All documents sit in one category per language (documents-ro / documents-en / documents-es), one article per
 * language, linked as associations. The file is the doc-file field. Ordinary Joomla tags (one set per language)
 * do the filtering. Spanish falls back to the English documents while the Spanish category is empty.
 * The magazine and the almanac have their own templates.
 */
final class Documents
{
    private const UI = [
        'title'   => ['Documente', 'Documents', 'Documentos'],
        'intro'   => ['Statutele, îndrumările administrative și spirituale și formularele pentru Sfintele Taine.', 'Statutes, administrative and spiritual guidelines, and forms for the Holy Mysteries.', 'Estatutos, orientaciones administrativas y espirituales, y formularios para los Santos Misterios.'],
        'search'  => ['Căutați un document', 'Search documents', 'Buscar un documento'],
        'all'     => ['Toate', 'All', 'Todos'],
        'az'      => ['Alfabetic', 'A to Z', 'Alfabético'],
        'new'     => ['Cele mai noi', 'Newest', 'Más recientes'],
        'order'   => ['Ordine', 'Order', 'Orden'],
        'open'    => ['Deschide', 'Open', 'Abrir'],
        'dl'      => ['Descarcă', 'Download', 'Descargar'],
        'dlpdf'   => ['Descarcă PDF', 'Download PDF', 'Descargar PDF'],
        'newtab'  => ['Deschide într-o filă nouă', 'Open in a new tab', 'Abrir en una pestaña nueva'],
        'none'    => ['Nu există încă documente.', 'There are no documents yet.', 'Todavía no hay documentos.'],
        'nomatch' => ['Niciun document nu se potrivește căutării.', 'No document matches your search.', 'Ningún documento coincide con la búsqueda.'],
        'showall' => ['Arată toate', 'Show all', 'Mostrar todos'],
        'also'    => ['Disponibil și în', 'Also available in', 'También disponible en'],
        'about'   => ['Despre document', 'About this document', 'Sobre el documento'],
        'lang'    => ['Limba', 'Language', 'Idioma'],
        'pages'   => ['Pagini', 'Pages', 'Páginas'],
        'size'    => ['Mărime', 'Size', 'Tamaño'],
        'updated' => ['Actualizat', 'Updated', 'Actualizado'],
        'same'    => ['Cu aceleași etichete', 'With the same tags', 'Con las mismas etiquetas'],
        'more'    => ['Alte documente', 'More documents', 'Más documentos'],
        'alldocs' => ['Toate documentele', 'All documents', 'Todos los documentos'],
        'phone'   => ['Pe telefon, documentul se deschide în aplicația de PDF.', 'On a phone, the document opens in your PDF app.', 'En el teléfono, el documento se abre en la aplicación de PDF.'],
        'nofile'  => ['Fișierul nu este încă disponibil.', 'The file is not available yet.', 'El archivo todavía no está disponible.'],
        'home'    => ['Acasă', 'Home', 'Inicio'],
        'doc'     => ['Document', 'Document', 'Documento'],
        'n1'      => ['document', 'document', 'documento'],
        'nn'      => ['documente', 'documents', 'documentos'],
        'nde'     => ['de documente', 'documents', 'documentos'],
    ];
    private const LANG_NAME = [
        'ro' => ['Română', 'Romanian', 'Rumano'],
        'en' => ['English', 'English', 'Inglés'],
        'es' => ['Español', 'Spanish', 'Español'],
    ];
    private const MONTH = [
        'ro' => ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    ];
    private const I_DL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>';
    private const I_EXT = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>';
    private const I_SEARCH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';

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

    private static function li(): int
    {
        return ['ro' => 0, 'en' => 1, 'es' => 2][self::lang()];
    }

    private static function ui(string $k): string
    {
        return self::UI[$k][self::li()];
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function catId(string $l): int
    {
        static $ids = [];
        if (!isset($ids[$l])) {
            $db = self::db();
            $ids[$l] = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__categories')->where('extension = ' . $db->quote('com_content'))
                ->where('alias = ' . $db->quote('documents-' . $l))->where('published = 1'), 0, 1)->loadResult();
        }
        return $ids[$l];
    }

    private static function base(array $cats)
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());
        return $db->getQuery(true)->from($db->quoteName('#__content', 'a'))
            ->whereIn('a.catid', array_map('intval', $cats ?: [0]))->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels ?: [1]);
    }

    private const COLS = ['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.introtext', 'a.publish_up', 'a.modified'];

    /** Published documents of the page language (Spanish: English while there is no Spanish document). */
    private static function all(): array
    {
        $l = self::lang();
        $db = self::db();
        $rows = self::catId($l) ? $db->setQuery(self::base([self::catId($l)])->select(self::COLS))->loadObjectList() : [];
        if (!$rows && $l === 'es' && self::catId('en')) {
            $rows = $db->setQuery(self::base([self::catId('en')])->select(self::COLS))->loadObjectList();
        }
        return self::shape($rows);
    }

    /** Documents with file, tags and the languages they exist in. */
    private static function shape(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $db = self::db();
        $files = [];
        $q = $db->getQuery(true)->select(['v.item_id', 'v.value'])->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->where('f.name = ' . $db->quote('doc-file'))
            ->whereIn('v.item_id', array_map('strval', $ids), ParameterType::STRING);
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $files[(int) $r->item_id] = self::filePath((string) $r->value);
        }
        $tags = self::tags($ids);
        $langs = self::languages($ids);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $file = $files[$id] ?? '';
            $out[] = (object) [
                'id'    => $id,
                'title' => (string) $r->title,
                'catid' => (int) $r->catid,
                'lang'  => (string) $r->language,
                'intro' => (string) $r->introtext,
                'date'  => (string) ($r->publish_up ?: $r->modified),
                'mod'   => (string) $r->modified,
                'file'  => $file,
                'ext'   => strtoupper(pathinfo(parse_url($file, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'PDF',
                'tags'  => $tags[$id] ?? [],
                'langs' => $langs[$id] ?? [],
                'url'   => Route::_(RouteHelper::getArticleRoute($id, (int) $r->catid, (string) $r->language)),
            ];
        }
        return $out;
    }

    /** The document field may hold a plain path or JSON; keep only the path. */
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
        return preg_match('#^https?://#i', $path) ? $path : Uri::root(true) . '/' . ltrim($path, '/');
    }

    /** Local file on disk, or '' for a remote link. */
    private static function disk(string $path): string
    {
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return '';
        }
        $p = realpath(JPATH_ROOT . '/' . ltrim(rawurldecode($path), '/'));
        return $p && str_starts_with($p, realpath(JPATH_ROOT)) && is_file($p) ? $p : '';
    }

    /** Published tags per article, in the order set in Components > Tags. */
    private static function tags(array $ids): array
    {
        $db = self::db();
        $q = $db->getQuery(true)->select(['m.content_item_id', 't.id', 't.title', 't.alias', 't.lft'])
            ->from($db->quoteName('#__contentitem_tag_map', 'm'))
            ->join('INNER', $db->quoteName('#__tags', 't') . ' ON t.id = m.tag_id')
            ->where('m.type_alias = ' . $db->quote('com_content.article'))->where('t.published = 1')
            ->whereIn('m.content_item_id', $ids)->order('t.lft');
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->content_item_id][] = ['id' => (int) $r->id, 'title' => (string) $r->title, 'alias' => (string) $r->alias, 'lft' => (int) $r->lft];
        }
        return $out;
    }

    /** Language codes each article exists in: its own plus its published associations. */
    private static function languages(array $ids): array
    {
        $db = self::db();
        $q = $db->getQuery(true)->select(['s.id AS src', 'c.id', 'c.language', 'c.catid'])
            ->from($db->quoteName('#__associations', 's'))
            ->join('INNER', $db->quoteName('#__associations', 't') . ' ON t.' . $db->quoteName('key') . ' = s.' . $db->quoteName('key') . ' AND t.context = s.context')
            ->join('INNER', $db->quoteName('#__content', 'c') . ' ON c.id = t.id AND c.state = 1')
            ->where('s.context = ' . $db->quote('com_content.item'))->whereIn('s.id', $ids);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->src][strtolower(substr((string) $r->language, 0, 2))] = ['id' => (int) $r->id, 'catid' => (int) $r->catid, 'language' => (string) $r->language];
        }
        return $out;
    }

    private static function langCodes(object $d): array
    {
        $codes = array_keys($d->langs);
        $own = strtolower(substr($d->lang, 0, 2));
        if (!in_array($own, $codes, true)) {
            $codes[] = $own;
        }
        $order = ['ro', 'en', 'es'];
        $codes = array_values(array_filter($order, fn ($c) => in_array($c, $codes, true)));
        return $codes;
    }

    private static function longDate(string $sql): string
    {
        if ($sql === '' || str_starts_with($sql, '0000')) {
            return '';
        }
        try {
            $d = Factory::getDate($sql, 'UTC');
            $d->setTimezone(new \DateTimeZone(Factory::getApplication()->get('offset', 'UTC')));
        } catch (\Throwable $x) {
            return '';
        }
        $l = self::lang();
        $m = self::MONTH[$l][(int) $d->format('n', true) - 1];
        $day = (int) $d->format('j', true);
        $y = $d->format('Y', true);
        return $l === 'en' ? $m . ' ' . $day . ', ' . $y : ($l === 'es' ? $day . ' de ' . $m . ' de ' . $y : $day . ' ' . $m . ' ' . $y);
    }

    private static function count(int $n): string
    {
        if ($n === 1) {
            return '1 ' . self::ui('n1');
        }
        if (self::lang() === 'ro' && ($n % 100 === 0 || $n % 100 >= 20)) {
            return $n . ' ' . self::ui('nde');
        }
        return $n . ' ' . self::ui('nn');
    }

    private static function size(int $b): string
    {
        $dec = self::lang() === 'en' ? '.' : ',';
        if ($b >= 1048576) {
            return number_format($b / 1048576, 1, $dec, '') . ' MB';
        }
        return max(1, (int) round($b / 1024)) . ' KB';
    }

    /** Page count of a PDF, read from the file (cached by path and modification time). */
    private static function pdfPages(string $disk): int
    {
        if ($disk === '' || strtolower(pathinfo($disk, PATHINFO_EXTENSION)) !== 'pdf' || filesize($disk) > 60 * 1048576) {
            return 0;
        }
        $key = 'mitdoc_' . md5($disk . '|' . filemtime($disk));
        $cache = JPATH_CACHE . '/mitropolia';
        $f = $cache . '/' . $key . '.txt';
        if (is_file($f)) {
            return (int) file_get_contents($f);
        }
        $s = (string) file_get_contents($disk);
        $n = 0;
        if (preg_match_all('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)#s', $s, $m)) {
            $n = max(array_map('intval', $m[1]));
        }
        if (!$n) {
            $n = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $s);
        }
        if (!is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }
        @file_put_contents($f, (string) $n);
        return $n;
    }

    private static function listUrl(string $l = ''): string
    {
        $app = Factory::getApplication();
        $l = $l ?: self::lang();
        $tag = $app->getLanguage()->getTag();
        $cats = array_filter([self::catId($l), $l === 'es' ? self::catId('en') : 0]);
        foreach ($app->getMenu()->getItems(['component', 'language'], ['com_content', $tag]) as $item) {
            $q = $item->query ?? [];
            if (($q['view'] ?? '') === 'category' && in_array((int) ($q['id'] ?? 0), $cats, true)) {
                return Route::_('index.php?Itemid=' . (int) $item->id);
            }
        }
        return Route::_(RouteHelper::getCategoryRoute(self::catId($l) ?: self::catId('en'), $tag));
    }

    private static function crumbs(array $parts): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        $out = ['<a href="' . $e($home ? Route::_('index.php?Itemid=' . (int) $home->id) : Uri::root()) . '">' . $e($home ? (string) $home->title : self::ui('home')) . '</a>'];
        foreach ($parts as $i => [$label, $href]) {
            $out[] = $href !== '' && $i < count($parts) - 1 ? '<a href="' . $e($href) . '">' . $e($label) . '</a>' : '<span aria-current="page">' . $e($label) . '</span>';
        }
        return '<nav class="mdx-crumbs" aria-label="Breadcrumb">' . implode('<span aria-hidden="true">›</span>', $out) . '</nav>';
    }

    private static function norm(string $s): string
    {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $s));
    }

    private static function icon(string $ext): string
    {
        return '<span class="mdx-ico" aria-hidden="true"><b>' . self::e(substr($ext, 0, 4)) . '</b></span>';
    }

    /* ------------------------------------------------------------------ list */

    public static function listing(array $props): string
    {
        try {
            return self::listHtml($props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Documents list: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
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
        $docs = self::all();
        usort($docs, fn ($a, $b) => strcoll(self::norm($a->title), self::norm($b->title)));
        $title = trim((string) ($props['title'] ?? '')) ?: self::ui('title');
        $intro = trim((string) ($props['intro'] ?? '')) ?: self::ui('intro');

        // tags used by these documents, in the Tags order
        $used = [];
        foreach ($docs as $d) {
            foreach ($d->tags as $t) {
                $used[$t['alias']] ??= $t + ['n' => 0];
                $used[$t['alias']]['n']++;
            }
        }
        uasort($used, fn ($a, $b) => $a['lft'] <=> $b['lft']);

        $chips = '';
        if ($used) {
            $chips = '<div class="mdx-chips" data-chips><button type="button" class="mdx-chip on" data-tag="">' . $e(self::ui('all')) . ' <span class="n" data-n>' . count($docs) . '</span></button>';
            foreach ($used as $a => $t) {
                $chips .= '<button type="button" class="mdx-chip" data-tag="' . $e($a) . '">' . $e($t['title']) . ' <span class="n" data-n>' . $t['n'] . '</span></button>';
            }
            $chips .= '</div>';
        }

        $rows = '';
        foreach ($docs as $i => $d) {
            $meta = '<span>' . $e($d->ext) . '</span>';
            foreach (self::langCodes($d) as $c) {
                $meta .= '<span class="mdx-lang">' . strtoupper($c) . '</span>';
            }
            $tags = '';
            foreach ($d->tags as $t) {
                $tags .= '<button type="button" class="mdx-tag" data-tag="' . $e($t['alias']) . '">' . $e($t['title']) . '</button>';
            }
            $href = self::src($d->file);
            $rows .= '<li class="mdx-row" data-t="' . $e(self::norm($d->title . ' ' . implode(' ', array_column($d->tags, 'title')))) . '" data-tags="' . $e(implode(' ', array_column($d->tags, 'alias'))) . '" data-az="' . $i . '" data-d="' . $e(substr($d->date, 0, 10)) . '">'
                . self::icon($d->ext)
                . '<div class="mdx-row-t"><h3><a href="' . $e($d->url) . '">' . $e($d->title) . '</a></h3><div class="mdx-meta">' . $meta . '</div>'
                . ($tags !== '' ? '<div class="mdx-tags">' . $tags . '</div>' : '') . '</div>'
                . '<div class="mdx-acts"><a class="mdx-btn line" href="' . $e($d->url) . '">' . $e(self::ui('open')) . '</a>'
                . ($href !== '' ? '<a class="mdx-btn blue sq" href="' . $e($href) . '" download aria-label="' . $e(self::ui('dl') . ': ' . $d->title) . '" title="' . $e(self::ui('dl')) . '">' . self::I_DL . '</a>' : '')
                . '</div></li>';
        }

        $n1 = self::ui('n1');
        $nn = self::ui('nn');
        $nde = self::ui('nde');
        $body = $docs
            ? '<div class="mdx-tools"><p class="mdx-status" data-status data-one="' . $e($n1) . '" data-many="' . $e($nn) . '" data-de="' . $e(self::lang() === 'ro' ? $nde : $nn) . '" aria-live="polite">' . $e(self::count(count($docs))) . '</p>'
                . '<select class="mdx-sel" data-sort aria-label="' . $e(self::ui('order')) . '"><option value="az">' . $e(self::ui('az')) . '</option><option value="new">' . $e(self::ui('new')) . '</option></select></div>'
                . '<ul class="mdx-rows" data-rows>' . $rows . '</ul>'
                . '<p class="mdx-empty" data-empty hidden>' . $e(self::ui('nomatch')) . ' <button type="button" data-reset>' . $e(self::ui('showall')) . '</button></p>'
            : '<p class="mdx-empty">' . $e(self::ui('none')) . '</p>';

        $search = $docs ? '<form class="mdx-search-f" role="search" onsubmit="return false"><label class="mdx-search">' . self::I_SEARCH . '<input type="search" data-q placeholder="' . $e(self::ui('search')) . '" aria-label="' . $e(self::ui('search')) . '"></label></form>' : '';

        return '<div class="mdx mdx-list' . (($c = trim((string) ($props['class'] ?? ''))) !== '' ? ' ' . $e($c) : '') . '" data-mdx>'
            . '<div class="mdx-hero"><div class="mdx-in">' . self::crumbs([[$title, '']])
            . '<div class="mdx-hero-row"><div class="mdx-hero-text"><h1 class="mdx-h1">' . $e($title) . '</h1><p class="mdx-lead">' . $e($intro) . '</p></div>' . $search . '</div>'
            . $chips . '</div></div>'
            . '<div class="mdx-in mdx-body">' . $body . '</div></div>';
    }

    /* ------------------------------------------------------------------ one document */

    public static function page(array $props): string
    {
        try {
            $in = Factory::getApplication()->getInput();
            $id = $in->get('option') === 'com_content' && $in->get('view') === 'article' ? $in->getInt('id') : 0;
            if (!$id) {
                return '';
            }
            $db = self::db();
            $cats = array_filter([self::catId('ro'), self::catId('en'), self::catId('es')]);
            $rows = $db->setQuery(self::base($cats)->select(array_merge(self::COLS, [$db->quoteName('a.fulltext')]))->where('a.id = ' . $id))->loadObjectList();
            if (!$rows) {
                return '';
            }
            $d = self::shape($rows)[0];
            return self::pageHtml($d, $rows[0], $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Document page: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function pageText(array $props): string
    {
        return '';
    }

    private static function pageHtml(object $d, object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $l = self::lang();
        $li = self::li();
        $own = strtolower(substr($d->lang, 0, 2));
        $listUrl = self::listUrl();
        $href = self::src($d->file);
        $disk = self::disk($d->file);
        $isPdf = $d->ext === 'PDF';

        // tags link back to the filtered list
        $tags = '';
        foreach ($d->tags as $t) {
            $tags .= '<a class="mdx-tag" href="' . $e($listUrl . (str_contains($listUrl, '?') ? '&' : '?') . 'tag=' . rawurlencode($t['alias'])) . '">' . $e($t['title']) . '</a>';
        }
        // other languages
        $also = [];
        foreach (['ro', 'en', 'es'] as $c) {
            if ($c !== $own && isset($d->langs[$c])) {
                $o = $d->langs[$c];
                $also[] = '<a href="' . $e(Route::_(RouteHelper::getArticleRoute($o['id'], $o['catid'], $o['language']))) . '" hreflang="' . $c . '">' . $e(self::LANG_NAME[$c][$li]) . '</a>';
            }
        }

        $btns = $href !== ''
            ? '<a class="mdx-btn red" href="' . $e($href) . '" download>' . self::I_DL . $e($isPdf ? self::ui('dlpdf') : self::ui('dl')) . '</a>'
                . '<a class="mdx-btn line" href="' . $e($href) . '" target="_blank" rel="noopener">' . self::I_EXT . $e(self::ui('newtab')) . '</a>'
            : '';

        // viewer: the PDF in the page on a computer, a plain link on a phone
        $viewer = '';
        if ($href === '') {
            $viewer = '<p class="mdx-note">' . $e(self::ui('nofile')) . '</p>';
        } elseif ($isPdf) {
            $viewer = '<div class="mdx-viewer"><iframe data-pdf="' . $e($href) . '#view=FitH" title="' . $e($d->title) . '" loading="lazy"></iframe></div>'
                . '<a class="mdx-phone" href="' . $e($href) . '" target="_blank" rel="noopener">' . self::icon('PDF') . '<span>' . $e(self::ui('phone')) . '</span></a>';
        } else {
            $viewer = '<a class="mdx-phone mdx-phone-all" href="' . $e($href) . '" download>' . self::icon($d->ext) . '<span>' . $e(basename(rawurldecode(parse_url($href, PHP_URL_PATH) ?: ''))) . '</span></a>';
        }

        // optional description from the article text
        $body = trim((string) $a->introtext . (string) $a->fulltext);
        if (trim(strip_tags($body)) !== '') {
            try {
                $body = HTMLHelper::_('content.prepare', $body, null, 'com_content.article');
            } catch (\Throwable $x) {
            }
            $viewer .= '<div class="mdx-desc">' . $body . '</div>';
        }

        // facts
        $facts = '<dt>' . $e(self::ui('lang')) . '</dt><dd>' . $e(self::LANG_NAME[$own][$li] ?? strtoupper($own)) . '</dd>';
        if ($disk !== '') {
            $pages = $isPdf ? self::pdfPages($disk) : 0;
            if ($pages) {
                $facts .= '<dt>' . $e(self::ui('pages')) . '</dt><dd>' . $pages . '</dd>';
            }
            $facts .= '<dt>' . $e(self::ui('size')) . '</dt><dd>' . $e(self::size((int) filesize($disk))) . '</dd>';
        }
        $upd = self::longDate($d->date);
        if ($upd !== '') {
            $facts .= '<dt>' . $e(self::ui('updated')) . '</dt><dd>' . $e($upd) . '</dd>';
        }

        // related: documents sharing a tag, else the newest others
        $others = array_values(array_filter(self::shapeList($d->catid), fn ($o) => $o->id !== $d->id));
        $mine = array_column($d->tags, 'alias');
        $rel = array_values(array_filter($others, fn ($o) => array_intersect($mine, array_column($o->tags, 'alias'))));
        $head = $rel ? self::ui('same') : self::ui('more');
        $rel = array_slice($rel ?: $others, 0, 5);
        $relHtml = '';
        foreach ($rel as $o) {
            $relHtml .= '<a class="mdx-same" href="' . $e($o->url) . '">' . $e($o->title) . '<small>' . $e($o->ext . ' · ' . implode(' · ', array_map('strtoupper', self::langCodes($o)))) . '</small></a>';
        }

        $cls = trim((string) ($props['class'] ?? ''));
        return '<article class="mdx mdx-page' . ($cls !== '' ? ' ' . $e($cls) : '') . '">'
            . '<div class="mdx-band"><div class="mdx-in">' . self::crumbs([[self::ui('title'), $listUrl], [$d->title, '']]) . '</div></div>'
            . '<div class="mdx-in">'
            . '<header class="mdx-head"><div><p class="mdx-kind">' . $e(self::ui('doc') . ' · ' . $d->ext) . '</p><h1 class="mdx-title">' . $e($d->title) . '</h1>'
            . ($tags !== '' ? '<div class="mdx-tags">' . $tags . '</div>' : '')
            . ($also ? '<p class="mdx-also">' . $e(self::ui('also')) . ' ' . implode(' · ', $also) . '</p>' : '') . '</div>'
            . ($btns !== '' ? '<div class="mdx-dacts">' . $btns . '</div>' : '') . '</header>'
            . '<div class="mdx-cols"><div class="mdx-main">' . $viewer . '</div>'
            . '<aside class="mdx-side"><div class="mdx-box"><h4>' . $e(self::ui('about')) . '</h4><dl class="mdx-facts">' . $facts . '</dl></div>'
            . ($relHtml !== '' ? '<div class="mdx-box"><h4>' . $e($head) . '</h4>' . $relHtml . '<a class="mdx-allx" href="' . $e($listUrl) . '">' . $e(self::ui('alldocs')) . ' →</a></div>' : '')
            . '</aside></div></div></article>';
    }

    /** All documents of one category, newest first. */
    private static function shapeList(int $catid): array
    {
        $db = self::db();
        $docs = self::shape($db->setQuery(self::base([$catid])->select(self::COLS)->order('a.publish_up DESC'), 0, 200)->loadObjectList());
        return $docs;
    }

    /* ------------------------------------------------------------------ assets */

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return News::sharedCss() . <<<'HTML'
<style>
/* Documents list and Document page (Mitropolia plugin), as in the approved mockup. */
.mdx{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-gold:#B99755;--mp-ink:#1B2A4A;--mp-muted:#6B6F7B;--mp-line:#E4D8BE;--mp-ivory:#EFE3CB;--mp-sand:#D9CBAA;font-family:'Source Sans 3',sans-serif;color:var(--mp-ink)}
.mdx a{text-decoration:none;color:var(--mp-blue)}
.mdx a:hover{color:var(--mp-red)}
.mdx [hidden]{display:none!important}
.mdx-in{max-width:1184px;margin:0 auto;box-sizing:border-box}
.mdx-hero,.mdx-band{position:relative;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax);border-bottom:1px solid var(--mp-line)}
.mdx-hero{padding:22px 0 40px}
.mdx-band{padding:18px 0}
.mdx-crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:14px;color:#6B5A34}
.mdx-hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:32px;flex-wrap:wrap;margin-top:30px}
.mdx-hero-text{max-width:640px}
.mdx-h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:56px;line-height:1.05;color:var(--mp-navy);margin:0 0 14px}
.mdx-lead{font-family:'Source Serif 4',Georgia,serif;font-size:20px;line-height:1.55;color:#3C4A66;margin:0}
.mdx-search-f{margin:0}
.mdx-search{position:relative;display:inline-flex}
.mdx-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--mp-gold)}
.mdx .mdx-search input{height:46px;border:1px solid var(--mp-sand);border-radius:4px;padding:0 14px 0 42px;font:inherit;font-size:16px;width:300px;max-width:100%;background:#fff;box-sizing:border-box;color:var(--mp-ink);margin:0}
.mdx .mdx-search input:focus{outline:2px solid var(--mp-blue);outline-offset:1px}
.mdx-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:28px}
.mdx .mdx-chip{display:inline-flex;align-items:center;gap:8px;font:inherit;font-size:14px;font-weight:600;padding:7px 16px;border-radius:999px;border:1px solid var(--mp-sand);background:#fff;color:var(--mp-blue);cursor:pointer;line-height:1.4}
.mdx .mdx-chip .n{font-weight:400;opacity:.7;font-variant-numeric:tabular-nums}
.mdx .mdx-chip.on,.mdx .mdx-chip:hover{background:var(--mp-blue);border-color:var(--mp-blue);color:#fff}
.mdx-body{padding:40px 0 88px}
.mdx-tools{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid var(--mp-line)}
.mdx-status{margin:0;color:var(--mp-muted);font-size:15px}
.mdx .mdx-sel{height:40px;border:1px solid var(--mp-sand);border-radius:4px;padding:0 12px;font:inherit;font-size:15px;background:#fff;color:var(--mp-blue);width:auto;margin:0}
.mdx-empty{text-align:center;color:var(--mp-muted);font-size:18px;padding:48px 0;margin:0}
.mdx-empty button{font:inherit;font-weight:600;color:var(--mp-red);background:none;border:0;cursor:pointer}
.mdx-rows{list-style:none;margin:0;padding:0}
.mdx-row{display:flex;align-items:center;gap:18px;padding:18px 4px;border-bottom:1px solid var(--mp-line);margin:0}
.mdx-row:hover{background:#FBF7EE}
.mdx-ico{flex:none;width:44px;height:54px;border-radius:3px;background:#fff;border:1px solid var(--mp-sand);position:relative;display:flex;align-items:flex-end;justify-content:center;padding-bottom:7px;box-sizing:border-box}
.mdx-ico::before{content:"";position:absolute;top:-1px;right:-1px;width:12px;height:12px;background:linear-gradient(225deg,#fff 50%,var(--mp-sand) 50%)}
.mdx-ico b{font-size:10px;font-weight:700;letter-spacing:.06em;color:#fff;background:var(--mp-red);border-radius:2px;padding:1px 4px;line-height:1.4}
.mdx-row-t{flex:1;min-width:0}
.mdx-row-t h3{font-family:'Source Serif 4',Georgia,serif;font-weight:500;font-size:19px;line-height:1.35;margin:0}
.mdx-row-t h3 a{color:var(--mp-navy)}
.mdx-meta{font-size:14px;color:var(--mp-muted);display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px}
.mdx-lang{font-size:12px;font-weight:700;letter-spacing:.06em;color:var(--mp-blue);border:1px solid var(--mp-sand);border-radius:3px;padding:0 5px}
.mdx-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.mdx .mdx-tag{font:inherit;font-size:13px;font-weight:600;color:#6B5A34;background:#F6EEDC;border-radius:3px;padding:2px 8px;border:0;cursor:pointer;line-height:1.5}
.mdx .mdx-tag:hover{background:var(--mp-blue);color:#fff}
.mdx-acts{display:flex;gap:8px;flex:none}
.mdx .mdx-btn{display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 16px;border-radius:4px;font:inherit;font-weight:600;font-size:14px;cursor:pointer;box-sizing:border-box;white-space:nowrap}
.mdx .mdx-btn.line{border:1px solid var(--mp-sand);background:#fff;color:var(--mp-blue)}.mdx .mdx-btn.line:hover{border-color:var(--mp-blue)}
.mdx .mdx-btn.red{border:0;background:var(--mp-red);color:#fff}.mdx .mdx-btn.red:hover{background:#8a242c;color:#fff}
.mdx .mdx-btn.blue{border:0;background:var(--mp-blue);color:#fff}.mdx .mdx-btn.blue:hover{background:var(--mp-navy);color:#fff}
.mdx .mdx-btn.sq{width:40px;padding:0;justify-content:center}
.mdx-head{padding:48px 0 32px;display:flex;justify-content:space-between;align-items:flex-end;gap:32px;flex-wrap:wrap}
.mdx-kind{font-size:12px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-red);margin:0 0 12px}
.mdx-title{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:46px;line-height:1.12;color:var(--mp-navy);margin:0;max-width:820px;text-wrap:balance}
.mdx-head .mdx-tags{margin-top:14px}
.mdx-also{margin:14px 0 0;font-size:15px;color:var(--mp-muted)}.mdx-also a{font-weight:600}
.mdx-dacts{display:flex;gap:10px;flex-wrap:wrap}
.mdx-dacts .mdx-btn{height:46px;padding:0 20px;font-size:15px}
.mdx-cols{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:48px;align-items:start;padding-bottom:72px}
.mdx-main{min-width:0}
.mdx-viewer{border:1px solid var(--mp-line);border-radius:6px;overflow:hidden;background:#525659}
.mdx-viewer iframe{display:block;width:100%;height:80vh;min-height:560px;max-height:1000px;border:0}
.mdx .mdx-phone{display:none;gap:14px;align-items:center;padding:18px;border:1px solid var(--mp-line);border-radius:6px;background:#fff;color:var(--mp-muted);font-size:15px}
.mdx .mdx-phone-all{display:flex;color:var(--mp-navy);font-weight:600}
.mdx-note{color:var(--mp-muted);font-size:17px;margin:0;padding:24px;border:1px dashed var(--mp-sand);border-radius:6px}
.mdx-desc{margin-top:24px;padding:20px 24px;border:1px solid var(--mp-line);border-left:3px solid var(--mp-gold);border-radius:4px;font-family:'Source Serif 4',Georgia,serif;font-size:17px;line-height:1.6;color:#3C4A66}
.mdx-desc>:first-child{margin-top:0}.mdx-desc>:last-child{margin-bottom:0}
.mdx-side{position:sticky;top:100px;display:flex;flex-direction:column;gap:20px}
.mdx-box{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:22px}
.mdx-box h4{margin:0 0 14px;font-family:'Source Sans 3',sans-serif;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34}
.mdx-facts{margin:0;display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:15px}
.mdx-facts dt{color:var(--mp-muted);margin:0}.mdx-facts dd{margin:0;font-weight:600;color:var(--mp-navy);font-variant-numeric:tabular-nums}
.mdx .mdx-same{display:block;padding:10px 0;border-top:1px solid var(--mp-ivory);font-weight:600;color:var(--mp-navy);line-height:1.35}
.mdx .mdx-same:first-of-type{border-top:0;padding-top:0}.mdx .mdx-same:hover{color:var(--mp-red)}
.mdx-same small{display:block;font-weight:400;font-size:13px;color:var(--mp-muted);margin-top:2px}
.mdx .mdx-allx{display:inline-block;margin-top:10px;font-weight:600;color:var(--mp-red)}
@media (max-width:1000px){.mdx-cols{grid-template-columns:minmax(0,1fr)}.mdx-side{position:static}.mdx-title{font-size:38px}}
@media (max-width:640px){.mdx-h1{font-size:40px}.mdx-search-f,.mdx-search,.mdx .mdx-search input{width:100%}
.mdx-row{flex-wrap:wrap;gap:14px;padding:16px 0}.mdx-row-t{flex:1 1 calc(100% - 64px)}.mdx-acts{width:100%;padding-left:62px;box-sizing:border-box}.mdx-acts .mdx-btn.line{flex:1;justify-content:center}
.mdx-title{font-size:31px}.mdx-head{padding:32px 0 24px}.mdx-dacts{width:100%}.mdx-dacts .mdx-btn{flex:1;justify-content:center}
.mdx-viewer{display:none}.mdx .mdx-phone{display:flex}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
/* PDF in the page only on wider screens, so phones don't load it */
document.querySelectorAll('.mdx [data-pdf]').forEach(function(f){if(window.matchMedia('(min-width:641px)').matches){f.setAttribute('s'+'rc',f.dataset.pdf);}});
document.querySelectorAll('[data-mdx]').forEach(function(root){
 var list=root.querySelector('[data-rows]');if(!list)return;
 var rows=[].slice.call(list.children),qi=root.querySelector('[data-q]'),sel=root.querySelector('[data-sort]'),st=root.querySelector('[data-status]'),em=root.querySelector('[data-empty]'),tools=root.querySelector('.mdx-tools');
 var norm=function(s){return s.normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().trim();};
 var u=new URL(location.href),tag=u.searchParams.get('tag')||'',q=u.searchParams.get('q')||'',order=u.searchParams.get('sort')==='new'?'new':'az';
 if(qi)qi.value=q;if(sel)sel.value=order;
 function word(n){if(n===1)return n+' '+st.dataset.one;var m=n%100;return n+' '+(m===0||m>=20?st.dataset.de:st.dataset.many);}
 function run(push){
  var nq=norm(q),shown=0;
  var hitQ=function(r){return !nq||r.dataset.t.indexOf(nq)>-1;};
  rows.forEach(function(r){var ok=hitQ(r)&&(!tag||(' '+r.dataset.tags+' ').indexOf(' '+tag+' ')>-1);r.hidden=!ok;if(ok)shown++;});
  root.querySelectorAll('[data-chips] [data-tag]').forEach(function(c){var t=c.dataset.tag,n=rows.filter(function(r){return hitQ(r)&&(!t||(' '+r.dataset.tags+' ').indexOf(' '+t+' ')>-1);}).length;c.classList.toggle('on',t===tag);c.setAttribute('aria-pressed',String(t===tag));var s=c.querySelector('[data-n]');if(s)s.textContent=n;});
  rows.slice().sort(order==='new'?function(a,b){return b.dataset.d.localeCompare(a.dataset.d)||(+a.dataset.az)-(+b.dataset.az);}:function(a,b){return (+a.dataset.az)-(+b.dataset.az);}).forEach(function(r){list.appendChild(r);});
  if(st)st.textContent=word(shown);if(tools)tools.hidden=!shown;if(em)em.hidden=!!shown;
  if(push){var x=new URL(location.href);['tag','q','sort'].forEach(function(k){x.searchParams.delete(k);});if(tag)x.searchParams.set('tag',tag);if(q)x.searchParams.set('q',q);if(order==='new')x.searchParams.set('sort','new');history.replaceState(null,'',x.pathname+x.search+x.hash);}
 }
 root.addEventListener('click',function(e){var b=e.target.closest('[data-tag]');if(b){tag=b.dataset.tag;run(true);if(b.classList.contains('mdx-tag')){var c=root.querySelector('[data-chips]');if(c)c.scrollIntoView({block:'center',behavior:'smooth'});}return;}
  if(e.target.closest('[data-reset]')){tag='';q='';if(qi)qi.value='';run(true);}});
 if(qi)qi.addEventListener('input',function(){q=qi.value;run(true);});
 if(sel)sel.addEventListener('change',function(){order=sel.value;run(true);});
 run(false);
});
});
</script>
HTML;
    }
}
