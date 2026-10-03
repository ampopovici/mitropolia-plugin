<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Publications ("Publication" builder element): Revista Credința and Almanahul Credința, as in the approved
 * mockup of Oct 2. Each issue is an "All languages" article in its category (revista-credinta /
 * almanahul-credinta) with the fields publication-issue (1-4 for the magazine), publication-year and
 * publication-pdf. The page shows the newest issue large, then the earlier ones by year; "Read online" opens the
 * PDF in a page-turning reader (PDF.js from cdnjs). The cover is the article's intro image, else
 * images/publications/covers/<id>.jpg, else it is drawn from the PDF's first page in the browser.
 * An issue's own address shows the same page with that issue open in the reader.
 */
final class Publications
{
    private const CATS = ['revista-credinta' => 'mag', 'almanahul-credinta' => 'alm'];
    private const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
    private const UI = [
        'mag'      => ['Revista Credința', 'Credința Magazine', 'Revista «La Fe»'],
        'alm'      => ['Almanahul Credința', 'Credința Almanac', 'Almanaque «La Fe»'],
        'magsub'   => ['The Faith', 'The Faith', 'Credința · The Faith'],
        'cur_mag'  => ['Numărul curent', 'Current issue', 'Número actual'],
        'cur_alm'  => ['Ediția curentă', 'Current edition', 'Edición actual'],
        'earlier'  => ['Numere anterioare', 'Earlier issues', 'Números anteriores'],
        'earlierA' => ['Ediții anterioare', 'Earlier editions', 'Ediciones anteriores'],
        'read'     => ['Citește online', 'Read online', 'Leer en línea'],
        'dl'       => ['Descarcă', 'Download', 'Descargar'],
        'dlpdf'    => ['Descarcă PDF', 'Download PDF', 'Descargar PDF'],
        'year'     => ['Anul', 'Year', 'Año'],
        'none'     => ['Nu există încă numere publicate.', 'No issues have been published yet.', 'Todavía no hay números publicados.'],
        'close'    => ['Închide', 'Close', 'Cerrar'],
        'prev'     => ['Pagina anterioară', 'Previous page', 'Página anterior'],
        'next'     => ['Pagina următoare', 'Next page', 'Página siguiente'],
        'goto'     => ['Mergi la pagina', 'Go to page', 'Ir a la página'],
        'loading'  => ['Se încarcă…', 'Loading…', 'Cargando…'],
        'failed'   => ['Revista nu a putut fi deschisă aici. O puteți descărca.', 'The issue could not be opened here. You can download it.', 'No se pudo abrir aquí. Puede descargarlo.'],
        'reader'   => ['Cititor', 'Reader', 'Lector'],
        'home'     => ['Acasă', 'Home', 'Inicio'],
        'nr'       => ['Nr.', 'No.', 'N.º'],
    ];
    private const QUARTER = [
        'ro' => ['Ianuarie – Martie', 'Aprilie – Iunie', 'Iulie – Septembrie', 'Octombrie – Decembrie'],
        'en' => ['January – March', 'April – June', 'July – September', 'October – December'],
        'es' => ['Enero – Marzo', 'Abril – Junio', 'Julio – Septiembre', 'Octubre – Diciembre'],
    ];
    private const I_READ = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2zM22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg>';
    private const I_DL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>';

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

    /** [catid => 'mag'|'alm'] */
    private static function cats(): array
    {
        static $c = null;
        if ($c === null) {
            $db = self::db();
            $c = [];
            $rows = $db->setQuery($db->getQuery(true)->select(['id', 'alias'])->from('#__categories')->where('extension = ' . $db->quote('com_content'))
                ->whereIn('alias', array_keys(self::CATS), ParameterType::STRING)->where('published = 1'))->loadObjectList();
            foreach ($rows as $r) {
                $c[(int) $r->id] = self::CATS[$r->alias];
            }
        }
        return $c;
    }

    private static function base(int $cat)
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());
        return $db->getQuery(true)->from($db->quoteName('#__content', 'a'))
            ->where('a.catid = ' . $cat)->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels ?: [1]);
    }

    private static function filePath(string $v): string
    {
        $v = trim($v);
        $j = json_decode($v, true);
        if (is_array($j)) {
            $v = (string) ($j['file'] ?? $j['url'] ?? '');
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

    /** Issues of one publication, newest first. */
    private static function issues(int $cat, string $kind): array
    {
        $db = self::db();
        $rows = $db->setQuery(self::base($cat)->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.images', 'a.publish_up']))->loadObjectList();
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $q = $db->getQuery(true)->select(['v.item_id', 'f.name', 'v.value'])->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->whereIn('f.name', ['publication-issue', 'publication-year', 'publication-pdf'], ParameterType::STRING)
            ->whereIn('v.item_id', array_map('strval', $ids), ParameterType::STRING);
        $vals = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $vals[(int) $r->item_id][$r->name] = trim((string) $r->value);
        }
        $out = [];
        $budget = 2;
        foreach ($rows as $r) {
            $v = $vals[(int) $r->id] ?? [];
            $pdf = self::filePath((string) ($v['publication-pdf'] ?? ''));
            if ($pdf === '') {
                continue;
            }
            $year = (int) ($v['publication-year'] ?? 0) ?: (int) substr((string) $r->publish_up, 0, 4);
            $issue = (int) ($v['publication-issue'] ?? 0);
            $im = json_decode((string) $r->images, true) ?: [];
            $cover = trim(explode('#', (string) ($im['image_intro'] ?? ''), 2)[0]);
            if ($cover === '' || !is_file(JPATH_ROOT . '/' . $cover)) {
                $gen = 'images/publications/covers/' . (int) $r->id . '.jpg';
                $cover = is_file(JPATH_ROOT . '/' . $gen) || ($budget-- > 0 && self::makeCover((int) $r->id, $pdf)) ? $gen : '';
            }
            $out[] = (object) [
                'id'    => (int) $r->id,
                'alias' => (string) $r->alias,
                'catid' => (int) $r->catid,
                'year'  => $year,
                'issue' => $issue,
                'label' => self::label($kind, $year, $issue, (string) $r->title),
                'pdf'   => self::src($pdf),
                'cover' => $cover !== '' ? self::src($cover) . '?v=' . @filemtime(JPATH_ROOT . '/' . $cover) : '',
                'sort'  => $year * 100 + $issue,
                'pub'   => (string) $r->publish_up,
            ];
        }
        usort($out, fn ($a, $b) => [$b->sort, $b->pub] <=> [$a->sort, $a->pub]);
        return $out;
    }

    /**
     * Cover from the PDF's first page (Imagick), saved as images/publications/covers/<id>.jpg.
     * Used for new issues the first time the page is shown, at most two per page view.
     */
    public static function makeCover(int $id, string $pdf): bool
    {
        $abs = realpath(JPATH_ROOT . '/' . ltrim(rawurldecode($pdf), '/'));
        if (!$abs || !str_starts_with($abs, realpath(JPATH_ROOT)) || !is_file($abs) || !class_exists('Imagick')) {
            return false;
        }
        $dir = JPATH_ROOT . '/images/publications/covers';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        try {
            $im = new \Imagick();
            $im->setResolution(110, 110);
            $im->setColorspace(\Imagick::COLORSPACE_SRGB);
            $im->readImage($abs . '[0]');
            if ($im->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            $im->setImageBackgroundColor('white');
            if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            }
            $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $im->thumbnailImage(680, 0);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(82);
            $im->stripImage();
            $ok = $im->writeImage($dir . '/' . $id . '.jpg');
            $im->clear();
            @chmod($dir . '/' . $id . '.jpg', 0644);
            return (bool) $ok;
        } catch (\Throwable $x) {
            Log::add('Publication cover ' . $id . ': ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return false;
        }
    }

    private static function label(string $kind, int $year, int $issue, string $title): string
    {
        if ($kind === 'mag' && $issue >= 1 && $issue <= 4 && $year) {
            return self::QUARTER[self::lang()][$issue - 1] . ' ' . $year;
        }
        if ($kind === 'mag' && $issue && $year) {
            return self::ui('nr') . ' ' . $issue . ' · ' . $year;
        }
        return $year ? (string) $year : $title;
    }

    private static function catUrl(int $cat): string
    {
        $app = Factory::getApplication();
        $tag = $app->getLanguage()->getTag();
        $best = null;
        foreach ($app->getMenu()->getItems(['component'], ['com_content']) as $item) {
            $q = $item->query ?? [];
            if (($q['view'] ?? '') === 'category' && (int) ($q['id'] ?? 0) === $cat) {
                if ($item->language === $tag) {
                    $best = $item;
                    break;
                }
                if ($item->language === '*' && !$best) {
                    $best = $item;
                }
            }
        }
        return $best ? Route::_('index.php?Itemid=' . (int) $best->id) : Route::_(RouteHelper::getCategoryRoute($cat, $tag));
    }

    private static function crumbs(string $here): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        return '<nav class="mpx-crumbs mnx-crumbs" aria-label="Breadcrumb"><a href="' . $e($home ? Route::_('index.php?Itemid=' . (int) $home->id) : Uri::root()) . '">'
            . $e($home ? (string) $home->title : self::ui('home')) . '</a><span aria-hidden="true">›</span><span aria-current="page">' . $e($here) . '</span></nav>';
    }

    /* ------------------------------------------------------------------ render */

    public static function listing(array $props): string
    {
        try {
            $in = Factory::getApplication()->getInput();
            if ($in->get('option') !== 'com_content') {
                return '';
            }
            $cats = self::cats();
            $open = 0;
            if ($in->get('view') === 'category') {
                $cat = $in->getInt('id');
            } elseif ($in->get('view') === 'article') {
                $open = $in->getInt('id');
                $cat = (int) self::db()->setQuery(self::db()->getQuery(true)->select('catid')->from('#__content')->where('id = ' . $open))->loadResult();
            } else {
                return '';
            }
            if (!isset($cats[$cat])) {
                return '';
            }
            return self::html($cat, $cats[$cat], $open, $props) . News::sharedCss() . self::assets();
        } catch (\Throwable $x) {
            Log::add('Publication: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function listingText(array $props): string
    {
        return '';
    }

    private static function cover(object $i, string $name, bool $lazy = true): string
    {
        $e = [self::class, 'e'];
        $inner = $i->cover !== ''
            ? '<img src="' . $e($i->cover) . '" alt="" ' . ($lazy ? 'loading="lazy" ' : '') . 'width="680" height="880">'
            : '<span class="mpb-ph" data-pdfcover="' . $e($i->pdf) . '" aria-hidden="true"><span>' . $e($name) . '</span><small>' . $e($i->label) . '</small></span>';
        return '<button type="button" class="mpb-cov" data-read="' . $i->id . '" aria-label="' . $e(self::ui('read') . ': ' . $name . ', ' . $i->label) . '">' . $inner . '</button>';
    }

    private static function buttons(object $i, bool $small): string
    {
        $e = [self::class, 'e'];
        return '<div class="mpb-acts"><button type="button" class="mpb-btn red' . ($small ? ' sm' : '') . '" data-read="' . $i->id . '">' . self::I_READ . $e(self::ui('read')) . '</button>'
            . '<a class="mpb-btn line' . ($small ? ' sm' : '') . '" href="' . $e($i->pdf) . '" download>' . self::I_DL . $e(self::ui('dl')) . '</a></div>';
    }

    private static function html(int $cat, string $kind, int $open, array $props): string
    {
        $e = [self::class, 'e'];
        $name = trim((string) ($props['title'] ?? '')) ?: self::ui($kind);
        $list = self::issues($cat, $kind);
        $cls = trim((string) ($props['class'] ?? ''));
        $head = '<div class="mnx-band">' . self::crumbs($name) . '</div>';
        if (!$list) {
            return '<div class="mnx mpb' . ($cls !== '' ? ' ' . $e($cls) : '') . '">' . $head
                . '<section class="mpb-hero"><h1 class="mpx-h1">' . $e($name) . '</h1><p class="mnx-empty">' . $e(self::ui('none')) . '</p></section></div>';
        }
        $cur = $list[0];
        $rest = array_slice($list, 1);
        $sub = $kind === 'mag' ? '<i>' . $e(self::ui('magsub')) . '</i>' : '';
        $hero = '<section class="mpb-hero"><div class="mpb-hero-grid"><div class="mpb-covwrap">' . self::cover($cur, $name, false) . '</div><div>'
            . '<p class="mpb-eyebrow">' . $e(self::ui($kind === 'mag' ? 'cur_mag' : 'cur_alm')) . '</p><h1 class="mpb-h1">' . $e($name) . $sub . '</h1>'
            . '<p class="mpb-cur">' . $e($cur->label) . '</p>' . self::buttons($cur, false) . '</div></div></section>';

        $older = '';
        if ($rest) {
            $years = array_values(array_unique(array_map(fn ($i) => $i->year, $rest)));
            $chips = count($years) > 1 ? '<div class="mpb-years" role="group" aria-label="' . $e(self::ui('year')) . '">' : '';
            foreach ($years as $n => $y) {
                if (count($years) > 1) {
                    $chips .= '<button type="button" data-year="' . $y . '"' . ($n === 0 ? ' class="on" aria-pressed="true"' : ' aria-pressed="false"') . '>' . $y . '</button>';
                }
            }
            $chips .= count($years) > 1 ? '</div>' : '';
            $grid = '';
            foreach ($rest as $i) {
                $grid .= '<div class="mpb-issue" data-y="' . $i->year . '"' . (count($years) > 1 && $i->year !== $years[0] ? ' hidden' : '') . '>'
                    . self::cover($i, $name) . '<b>' . $e($i->label) . '</b>' . self::buttons($i, true) . '</div>';
            }
            $older = '<section class="mpb-sec"><div class="mpb-sec-h"><h2 class="mpx-h2">' . $e(self::ui($kind === 'mag' ? 'earlier' : 'earlierA')) . '</h2>' . $chips . '</div><div class="mpb-grid">' . $grid . '</div></section>';
        }

        // data for the reader
        $data = [];
        foreach ($list as $i) {
            $data[$i->id] = ['t' => $name . ' · ' . $i->label, 'pdf' => $i->pdf];
        }
        $cfg = [
            'lib' => self::PDFJS, 'open' => $open && isset($data[$open]) ? $open : 0, 'issues' => $data,
            'ui' => ['close' => self::ui('close'), 'prev' => self::ui('prev'), 'next' => self::ui('next'), 'goto' => self::ui('goto'),
                'loading' => self::ui('loading'), 'failed' => self::ui('failed'), 'dl' => self::ui('dlpdf'), 'reader' => self::ui('reader')],
        ];
        return '<div class="mnx mpb' . ($cls !== '' ? ' ' . $e($cls) : '') . '" data-mpb=\'' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP) . '\'>'
            . $head . $hero . $older . '</div>';
    }

    /* ------------------------------------------------------------------ assets */

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>
/* Publications (Mitropolia plugin): Revista Credința and Almanahul Credința, as in the approved mockup */
.mpb{--navy:#172E5C;--blue:#203D78;--red:#A32D36;--gold:#B99755;--ink:#1B2A4A;--muted:#6B6F7B;--line:#E4D8BE;--ivory:#EFE3CB;--sand:#D9CBAA}
.mpb [hidden]{display:none!important}
.mpb-hero{position:relative;background:var(--ivory);box-shadow:0 0 0 100vmax var(--ivory);clip-path:inset(0 -100vmax);border-bottom:1px solid var(--line);padding:56px 0 64px}
.mpb-hero-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:64px;align-items:center}
.mpb-eyebrow{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--red);margin:0 0 12px}
.mpb-h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:60px;line-height:1.02;color:var(--navy);margin:0}
.mpb-h1 i{display:block;font-size:30px;color:#6B5A34;margin-top:8px}
.mpb-cur{margin:28px 0 0;padding-top:22px;border-top:1px solid var(--sand);font-family:'Baskervville',Georgia,serif;font-size:32px;line-height:1.15;color:var(--navy)}
.mpb-acts{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
.mpb .mpb-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:46px;padding:0 20px;border-radius:4px;font:inherit;font-weight:600;font-size:15px;cursor:pointer;box-sizing:border-box;border:0;white-space:nowrap;text-decoration:none;line-height:1}
.mpb .mpb-btn.red{background:var(--red);color:#fff}.mpb .mpb-btn.red:hover{background:#8a242c;color:#fff}
.mpb .mpb-btn.line{background:#fff;border:1px solid var(--sand);color:var(--blue)}.mpb .mpb-btn.line:hover{border-color:var(--blue);color:var(--blue)}
.mpb .mpb-btn.sm{height:38px;padding:0 10px;font-size:14px}
.mpb .mpb-cov{display:block;width:100%;aspect-ratio:17/22;padding:0;border:0;border-radius:3px;overflow:hidden;background:var(--navy);cursor:pointer;box-shadow:0 18px 40px -22px rgba(23,46,92,.75);transition:transform .2s,box-shadow .2s}
.mpb-cov img,.mpb-cov canvas{width:100%;height:100%;object-fit:cover;display:block}
.mpb-ph{display:flex;flex-direction:column;justify-content:space-between;height:100%;padding:10%;box-sizing:border-box;color:#fff;text-align:left;font-family:'Baskervville',Georgia,serif;font-size:22px;line-height:1.1}
.mpb-ph small{font-family:'Source Sans 3',sans-serif;font-size:13px;color:#EFE3CB;border-top:1px solid rgba(185,151,85,.8);padding-top:8px}
.mpb-sec{padding:64px 0 88px}
.mpb-sec-h{display:flex;justify-content:space-between;align-items:baseline;gap:16px;flex-wrap:wrap;margin-bottom:26px}
.mpb-years{display:flex;gap:6px;flex-wrap:wrap}
.mpb-years button{font:inherit;font-size:14px;font-weight:600;min-width:58px;height:38px;border-radius:4px;border:1px solid var(--line);background:#fff;color:var(--blue);cursor:pointer;font-variant-numeric:tabular-nums}
.mpb-years button.on{background:var(--blue);border-color:var(--blue);color:#fff}
.mpb-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:32px 28px}
.mpb-issue{display:flex;flex-direction:column;gap:12px;min-width:0}
.mpb-issue b{font-weight:600;color:var(--navy);font-size:16px}
.mpb-issue .mpb-acts{margin-top:0;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.mpb-issue:hover .mpb-cov{transform:translateY(-3px);box-shadow:0 24px 40px -22px rgba(23,46,92,.8)}
/* reader */
.mpb-rd{position:fixed;inset:0;z-index:10000;background:#0B1326;display:flex;flex-direction:column;font-family:'Source Sans 3',sans-serif;color:#fff}
.mpb-rd[hidden]{display:none}
.mpb-rd-top{display:flex;align-items:center;gap:14px;padding:12px 18px;border-bottom:1px solid rgba(255,255,255,.1)}
.mpb-rd-top b{font-weight:600;font-size:16px;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mpb-rd-cnt{font-variant-numeric:tabular-nums;color:#C9D1E3;font-size:14px}
.mpb-ib{width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.25);background:transparent;color:#fff!important;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;flex:none;padding:0}
.mpb-ib:hover{background:rgba(255,255,255,.12)}
.mpb-stage{flex:1;display:flex;align-items:center;justify-content:center;gap:16px;padding:20px 12px;min-height:0;touch-action:pan-y;user-select:none}
.mpb-spread{display:flex;align-items:center;justify-content:center;height:100%;min-width:0;flex:1;transition:opacity .15s,transform .15s}
.mpb-spread.out-l{opacity:0;transform:translateX(-24px)}.mpb-spread.out-r{opacity:0;transform:translateX(24px)}
.mpb-spread canvas{display:block;max-height:100%;max-width:50%;background:#fff;box-shadow:0 30px 60px rgba(0,0,0,.5)}
.mpb-spread.one canvas{max-width:100%}
.mpb-spread canvas+canvas{border-left:1px solid #ddd}
.mpb-msg{color:#C9D1E3;font-size:16px;text-align:center;max-width:420px}
.mpb-msg a{color:#EFE3CB;font-weight:600}
.mpb-nav{width:52px;height:52px;border-radius:50%;border:0;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex:none;padding:0}
.mpb-nav:hover{background:rgba(255,255,255,.22)}.mpb-nav:disabled{opacity:.25;cursor:default}
.mpb-rd-bot{display:flex;align-items:center;gap:14px;padding:12px 24px 18px}
.mpb-rd-bot input{flex:1;accent-color:#B99755;margin:0}
.mpb-rd-bot small{color:#C9D1E3;font-size:13px;font-variant-numeric:tabular-nums}
@media (max-width:1000px){.mpb-hero-grid{grid-template-columns:260px minmax(0,1fr);gap:40px}.mpb-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.mpb-h1{font-size:48px}}
@media (max-width:640px){.mpb-hero{padding:32px 0 40px}.mpb-hero-grid{grid-template-columns:minmax(0,1fr);gap:28px}.mpb-covwrap{max-width:240px}.mpb-h1{font-size:40px}.mpb-h1 i{font-size:24px}.mpb-hero .mpb-acts .mpb-btn{flex:1}
.mpb-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:24px 16px}.mpb-issue .mpb-acts{grid-template-columns:minmax(0,1fr)}
.mpb-stage{padding:10px 4px;gap:4px}.mpb-nav{width:40px;height:40px;background:rgba(255,255,255,.08)}.mpb-rd-top .mpb-dl{display:none}}
@media (prefers-reduced-motion:reduce){.mpb-spread,.mpb .mpb-cov{transition:none}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
var root=document.querySelector('[data-mpb]');if(!root)return;
var C=JSON.parse(root.dataset.mpb),U=C.ui;
/* year buttons */
root.querySelectorAll('[data-year]').forEach(function(b){b.addEventListener('click',function(){var y=b.dataset.year;root.querySelectorAll('[data-year]').forEach(function(x){x.classList.toggle('on',x===b);x.setAttribute('aria-pressed',String(x===b));});root.querySelectorAll('.mpb-issue').forEach(function(i){i.hidden=i.dataset.y!==y;});});});
/* PDF.js, loaded on first use */
var lib=null;
function pdfjs(){if(lib)return lib;lib=new Promise(function(ok,no){var s=document.createElement('script');s.setAttribute('s'+'rc',C.lib+'pdf.min.js');s.onload=function(){window.pdfjsLib.GlobalWorkerOptions.workerSrc=C.lib+'pdf.worker.min.js';ok(window.pdfjsLib);};s.onerror=no;document.head.appendChild(s);});return lib;}
/* covers that have no image yet: draw the PDF's first page when it comes into view */
var io='IntersectionObserver' in window?new IntersectionObserver(function(es){es.forEach(function(en){if(!en.isIntersecting)return;io.unobserve(en.target);drawCover(en.target);});},{rootMargin:'200px'}):null;
function drawCover(ph){pdfjs().then(function(L){return L.getDocument({url:ph.dataset.pdfcover,disableAutoFetch:true,disableStream:false}).promise;}).then(function(doc){return doc.getPage(1);}).then(function(p){var v=p.getViewport({scale:1});var sc=Math.min(2,520/v.width);var vp=p.getViewport({scale:sc});var c=document.createElement('canvas');c.width=vp.width;c.height=vp.height;return p.render({canvasContext:c.getContext('2d'),viewport:vp}).promise.then(function(){ph.replaceWith(c);});}).catch(function(){});}
root.querySelectorAll('[data-pdfcover]').forEach(function(ph){if(io)io.observe(ph);else drawCover(ph);});
/* reader */
var rd=document.createElement('div');rd.className='mpb-rd';rd.hidden=true;rd.setAttribute('role','dialog');rd.setAttribute('aria-modal','true');rd.setAttribute('aria-label',U.reader);
var arrow=function(d){return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="'+d+'"/></svg>';};
rd.innerHTML='<div class="mpb-rd-top"><b></b><span class="mpb-rd-cnt"></span><a class="mpb-ib mpb-dl" download title="'+U.dl+'" aria-label="'+U.dl+'">'+arrow('M12 4v11m0 0-4-4m4 4 4-4M5 20h14')+'</a><button type="button" class="mpb-ib mpb-x" aria-label="'+U.close+'">'+arrow('M6 6l12 12M18 6 6 18')+'</button></div>'
 +'<div class="mpb-stage"><button type="button" class="mpb-nav mpb-p" aria-label="'+U.prev+'">'+arrow('M15 5l-7 7 7 7')+'</button><div class="mpb-spread"></div><button type="button" class="mpb-nav mpb-n" aria-label="'+U.next+'">'+arrow('M9 5l7 7-7 7')+'</button></div>'
 +'<div class="mpb-rd-bot"><small>1</small><input type="range" min="1" value="1" aria-label="'+U.goto+'"><small class="mpb-last"></small></div>';
document.body.appendChild(rd);
var sp=rd.querySelector('.mpb-spread'),cnt=rd.querySelector('.mpb-rd-cnt'),sl=rd.querySelector('input'),pv=rd.querySelector('.mpb-p'),nx=rd.querySelector('.mpb-n');
var doc=null,page=1,total=0,last=null,token=0,cache={};
function single(){return window.matchMedia('(max-width:700px)').matches;}
function pagesFor(p){if(single()||p===1)return [p];if(p%2===1)p--;return [p,p+1].filter(function(x){return x<=total;});}
function render(n){if(cache[n])return cache[n];var st=rd.querySelector('.mpb-stage');var H=st.clientHeight-40,W=st.clientWidth-(single()?96:150);
 cache[n]=doc.getPage(n).then(function(p){var v=p.getViewport({scale:1});var one=single()||n===1;var sc=Math.min(H/v.height,(one?W:W/2)/v.width);var dpr=Math.min(window.devicePixelRatio||1,2);var vp=p.getViewport({scale:sc*dpr});var c=document.createElement('canvas');c.width=vp.width;c.height=vp.height;c.style.width=Math.floor(vp.width/dpr)+'px';c.style.height=Math.floor(vp.height/dpr)+'px';return p.render({canvasContext:c.getContext('2d'),viewport:vp}).promise.then(function(){return c;});});return cache[n];}
function show(dir){var ps=pagesFor(page);page=ps[0];var my=++token;
 cnt.textContent=(ps.length>1?ps[0]+'–'+ps[1]:ps[0])+' / '+total;sl.value=page;pv.disabled=page<=1;nx.disabled=ps[ps.length-1]>=total;
 if(dir)sp.classList.add(dir>0?'out-l':'out-r');
 Promise.all(ps.map(render)).then(function(cs){if(my!==token)return;sp.innerHTML='';sp.classList.toggle('one',cs.length===1);cs.forEach(function(c){sp.appendChild(c);});sp.classList.remove('out-l','out-r');
  var nxt=pagesFor(Math.min(total,ps[ps.length-1]+1));nxt.forEach(render);});}
function go(d){if(!doc)return;var ps=pagesFor(page);var np=d>0?ps[ps.length-1]+1:ps[0]-1;if(np<1||np>total)return;page=d<0?pagesFor(np)[0]:np;show(d);}
function open(id){var it=C.issues[id];if(!it)return;last=document.activeElement;rd.hidden=false;document.documentElement.style.overflow='hidden';
 rd.querySelector('b').textContent=it.t;rd.querySelector('.mpb-dl').setAttribute('href',it.pdf);cnt.textContent='';sp.innerHTML='<p class="mpb-msg">'+U.loading+'</p>';rd.querySelector('.mpb-x').focus();
 doc=null;cache={};page=1;var my=++token;
 pdfjs().then(function(L){return L.getDocument({url:it.pdf}).promise;}).then(function(d){if(my!==token)return;doc=d;total=d.numPages;sl.max=total;rd.querySelector('.mpb-last').textContent=total;show(0);})
 .catch(function(){if(my!==token)return;sp.innerHTML='<p class="mpb-msg">'+U.failed+' <a href="'+it.pdf+'" download>'+U.dl+'</a></p>';});
 try{var u=new URL(location.href);u.searchParams.set('n',id);history.replaceState(null,'',u.pathname+u.search);}catch(e){}}
function close(){rd.hidden=true;document.documentElement.style.overflow='';token++;doc=null;cache={};if(last&&last.focus)last.focus();try{var u=new URL(location.href);u.searchParams.delete('n');history.replaceState(null,'',u.pathname+u.search);}catch(e){}}
root.addEventListener('click',function(e){var b=e.target.closest('[data-read]');if(b){e.preventDefault();open(b.dataset.read);}});
rd.querySelector('.mpb-x').addEventListener('click',close);pv.addEventListener('click',function(){go(-1);});nx.addEventListener('click',function(){go(1);});
sl.addEventListener('change',function(){if(doc){page=+sl.value;show(0);}});
document.addEventListener('keydown',function(e){if(rd.hidden)return;if(e.key==='Escape')close();else if(e.key==='ArrowRight')go(1);else if(e.key==='ArrowLeft')go(-1);});
var sx=null,stg=rd.querySelector('.mpb-stage');stg.addEventListener('pointerdown',function(e){if(!e.target.closest('.mpb-nav'))sx=e.clientX;});stg.addEventListener('pointerup',function(e){if(sx===null)return;var dx=e.clientX-sx;sx=null;if(Math.abs(dx)>50)go(dx<0?1:-1);});
var rt=null;window.addEventListener('resize',function(){if(rd.hidden||!doc)return;clearTimeout(rt);rt=setTimeout(function(){cache={};show(0);},200);});
var want=C.open||+(new URL(location.href).searchParams.get('n')||0);if(want&&C.issues[want])open(want);
});
</script>
HTML;
    }
}
