<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

/**
 * Homepage blocks whose content comes from the database: the news hero, the news block,
 * the hierarchs' visits, events and the newest Credința issue.
 * They print the approved homepage markup (class prefix "mh-"). Their styles live with the rest of the
 * homepage styles in YOOtheme → Settings → CSS ("Homepage" part), so colours and sizes are changed in one place.
 * Every text has a builder field; an empty field uses the standard wording of the page language.
 */
final class Home
{
    private const UI = [
        'read'        => ['Citește știrea', 'Read the story', 'Leer la noticia'],
        'all_news'    => ['Toate știrile', 'All news', 'Todas las noticias'],
        'prev'        => ['Știrea anterioară', 'Previous story', 'Noticia anterior'],
        'next'        => ['Știrea următoare', 'Next story', 'Noticia siguiente'],
        'show'        => ['Arată știrea %d', 'Show story %d', 'Mostrar la noticia %d'],
        'ev_kicker'   => ['Evenimente', 'Events', 'Eventos'],
        'ev_title'    => ['Întâlniri în toată Mitropolia', 'Gatherings across the Metropolia', 'Encuentros en toda la Metrópolis'],
        'upcoming'    => ['Viitoare', 'Upcoming', 'Próximos'],
        'recent'      => ['Recente', 'Recent', 'Recientes'],
        'all_events'  => ['Toate evenimentele', 'All events', 'Todos los eventos'],
        'no_upcoming' => ['Evenimentele viitoare apar aici imediat ce sunt publicate.', 'Upcoming events appear here as soon as the office publishes them.', 'Los próximos eventos aparecen aquí en cuanto se publican.'],
        'no_recent'   => ['Nu există evenimente recente.', 'No recent events.', 'No hay eventos recientes.'],
        'pub_kicker'  => ['Publicații', 'Publications', 'Publicaciones'],
        'pub_title'   => ['Credința', 'The Faith Magazine', 'Revista «La Fe»'],
        'pub_text'    => ['Revista Mitropoliei, care apare în fiecare trimestru. Numărul %s se poate descărca.', 'The magazine of the Metropolia, published each quarter. The %s issue is ready to download.', 'La revista de la Metrópolis, publicada cada trimestre. El número de %s ya se puede descargar.'],
        'download'    => ['Descarcă PDF', 'Download PDF', 'Descargar PDF'],
        'past'        => ['Numere anterioare', 'Past issues', 'Números anteriores'],
        'read_issue'  => ['Citește online', 'Read online', 'Leer en línea'],
    ];

    private const I_PIN = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
    private const I_PREV = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>';
    private const I_NEXT = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

    private static function li(): int
    {
        $l = strtolower(substr(Factory::getApplication()->getLanguage()->getTag(), 0, 2));
        return ['ro' => 0, 'en' => 1, 'es' => 2][$l] ?? 1;
    }

    private static function ui(string $k): string
    {
        return self::UI[$k][self::li()];
    }

    /** Builder field if filled, else the standard wording. */
    private static function t(array $props, string $field, string $key): string
    {
        $v = trim((string) ($props[$field] ?? ''));
        return $v !== '' ? $v : self::ui($key);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function n(array $props, string $k, int $def, int $max): int
    {
        $v = (int) ($props[$k] ?? 0);
        return $v > 0 ? min($v, $max) : $def;
    }

    private static function cls(array $props, string $base): string
    {
        $c = trim((string) ($props['class'] ?? ''));
        return $base . ($c !== '' ? ' ' . self::e($c) : '');
    }

    /* ------------------------------------------------------------------ hero: latest news */

    public static function hero(array $props): string
    {
        try {
            $items = News::homeItems(self::n($props, 'count', 4, 8), 0, 200);
            if (!$items) {
                return '';
            }
            $e = [self::class, 'e'];
            $read = self::t($props, 'read_text', 'read');
            $all = self::t($props, 'all_text', 'all_news');
            $list = News::homeListUrl();
            $nav = function (int $cur) use ($items, $e): string {
                if (count($items) < 2) {
                    return '';
                }
                $h = '<div class="mh-hero-nav"><button type="button" class="mh-circ" data-mh-prev aria-label="' . $e(self::ui('prev')) . '">' . self::I_PREV . '</button><div class="mh-dots">';
                foreach ($items as $i => $it) {
                    $h .= '<button type="button" data-mh-dot="' . $i . '" aria-label="' . $e(sprintf(self::ui('show'), $i + 1)) . '"' . ($i === $cur ? ' aria-current="true"' : '') . '></button>';
                }
                return $h . '</div><button type="button" class="mh-circ" data-mh-next aria-label="' . $e(self::ui('next')) . '">' . self::I_NEXT . '</button></div>';
            };
            $h = '<div class="' . self::cls($props, 'mh-hero') . '" data-mh-hero><span class="mh-wm" aria-hidden="true"></span><div class="mh-hero-in">';
            foreach ($items as $i => $it) {
                $tag = $i === 0 ? 'h1' : 'h2';
                $h .= '<div class="mh-slide"' . ($i ? ' hidden' : '') . '>'
                    . '<div class="mh-hero-tx"><div class="mh-hero-kick">' . $e(trim($it->kicker . ($it->kicker !== '' ? ' · ' : '') . $it->date)) . '</div>'
                    . '<' . $tag . ' class="mh-hero-h"><a href="' . $e($it->url) . '">' . $e($it->title) . '</a></' . $tag . '>'
                    . '<div class="mh-hero-btns"><a class="mh-btn mh-btn-gold" href="' . $e($it->url) . '">' . $e($read) . '</a>'
                    . '<a class="mh-btn mh-btn-ghost" href="' . $e($list) . '">' . $e($all) . '</a></div>' . $nav($i) . '</div>'
                    . '<div class="mh-hero-ph">'
                    . ($it->img !== '' ? '<img src="' . $e($it->img) . '" alt="' . $e($it->alt) . '"' . ($i ? ' loading="lazy"' : ' fetchpriority="high"') . '>' : '<span class="mh-noimg"></span>')
                    . '</div></div>';
            }
            return $h . '</div></div>' . self::js();
        } catch (\Throwable $x) {
            Log::add('Home hero: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /* ------------------------------------------------------------------ news: one large story and a list */

    public static function news(array $props): string
    {
        try {
            $offset = isset($props['offset']) && $props['offset'] !== '' ? max(0, (int) $props['offset']) : 4;
            $items = News::homeItems(1 + self::n($props, 'count', 3, 8), $offset, 240);
            if (!$items) {
                return '';
            }
            $e = [self::class, 'e'];
            $f = array_shift($items);
            $kick = fn ($it, $d) => $e(trim($it->kicker . ($it->kicker !== '' ? ' · ' : '') . $d));
            $h = '<div class="' . self::cls($props, 'mh-news') . '"><a class="mh-feat" href="' . $e($f->url) . '">'
                . ($f->img !== '' ? '<img src="' . $e($f->img) . '" alt="' . $e($f->alt) . '" loading="lazy">' : '<span class="mh-noimg"></span>')
                . '<span class="mh-meta">' . $kick($f, $f->date) . '</span><span class="mh-feat-t">' . $e($f->title) . '</span>'
                . ($f->text !== '' ? '<span class="mh-feat-x">' . $e($f->text) . '</span>' : '') . '</a><div class="mh-nlist">';
            foreach ($items as $it) {
                $h .= '<a class="mh-nrow" href="' . $e($it->url) . '">'
                    . ($it->img !== '' ? '<img src="' . $e($it->img) . '" alt="" loading="lazy">' : '<span class="mh-noimg"></span>')
                    . '<span class="mh-nrow-tx"><span class="mh-meta">' . $kick($it, $it->short) . '</span><span class="mh-nrow-t">' . $e($it->title) . '</span></span></a>';
            }
            return $h . '<a class="mh-btn mh-btn-line" href="' . $e(News::homeListUrl()) . '">' . $e(self::t($props, 'all_text', 'all_news')) . '</a></div></div>';
        } catch (\Throwable $x) {
            Log::add('Home news: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /* ------------------------------------------------------------------ events */

    public static function events(array $props): string
    {
        try {
            $n = self::n($props, 'count', 4, 12);
            $up = Events::homeRows(false, $n);
            $past = Events::homeRows(true, $n);
            $e = [self::class, 'e'];
            $first = $up || !$past ? 0 : 1;
            $h = '<div class="' . self::cls($props, 'mh-events') . '" data-mh-tabs><div class="mh-ev-head"><div class="mh-head"><span class="mh-kick">' . $e(self::t($props, 'kicker', 'ev_kicker')) . '</span>'
                . '<h2 class="mh-h2">' . $e(self::t($props, 'title', 'ev_title')) . '</h2></div><div class="mh-ev-ctl"><div class="mh-pills" role="tablist">'
                . '<button type="button" role="tab" data-mh-tab="0" aria-selected="' . ($first === 0 ? 'true' : 'false') . '">' . $e(self::ui('upcoming')) . '</button>'
                . '<button type="button" role="tab" data-mh-tab="1" aria-selected="' . ($first === 1 ? 'true' : 'false') . '">' . $e(self::ui('recent')) . '</button></div>'
                . '<a class="mh-link" href="' . $e(Events::homeListUrl()) . '">' . $e(self::ui('all_events')) . ' →</a></div></div>';
            foreach ([[$up, 'no_upcoming'], [$past, 'no_recent']] as $i => [$rows, $none]) {
                $h .= '<div class="mh-pane" data-mh-pane="' . $i . '"' . ($i !== $first ? ' hidden' : '') . '>';
                if (!$rows) {
                    $h .= '<div class="mh-ev-none">' . $e(self::ui($none)) . '</div>';
                } else {
                    $h .= '<div class="mh-ev-grid">';
                    foreach ($rows as $r) {
                        $h .= '<a class="mh-ev" href="' . $e($r['url']) . '"><span class="mh-ev-d"><b>' . $e($r['day']) . '</b><span>' . $e($r['when']) . '</span></span>'
                            . '<span class="mh-ev-t">' . $e($r['title']) . '</span>'
                            . ($r['place'] !== '' ? '<span class="mh-ev-p">' . self::I_PIN . '<span>' . $e($r['place']) . '</span></span>' : '') . '</a>';
                    }
                    $h .= '</div>';
                }
                $h .= '</div>';
            }
            return $h . '</div>' . self::js();
        } catch (\Throwable $x) {
            Log::add('Home events: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /* ------------------------------------------------------------------ newest Credința issue */

    public static function publication(array $props): string
    {
        try {
            $i = Publications::latest(($props['kind'] ?? '') === 'alm' ? 'alm' : 'mag');
            if (!$i) {
                return '';
            }
            $e = [self::class, 'e'];
            $text = trim((string) ($props['intro'] ?? ''));
            $text = $text !== '' ? $text : sprintf(self::ui('pub_text'), $i->label);
            return '<div class="' . self::cls($props, 'mh-pub') . '"><a class="mh-cover" href="' . $e($i->url) . '" aria-label="' . $e(self::ui('read_issue') . ': ' . $i->label) . '">'
                . ($i->cover !== '' ? '<img src="' . $e($i->cover) . '" alt="" loading="lazy">' : '<span>' . $e($i->name) . '</span>') . '</a>'
                . '<div class="mh-pub-tx"><span class="mh-kick">' . $e(self::t($props, 'kicker', 'pub_kicker')) . '</span>'
                . '<h2 class="mh-h2">' . $e(self::t($props, 'title', 'pub_title')) . '</h2><p>' . $e($text) . '</p>'
                . '<div class="mh-hero-btns"><a class="mh-btn mh-btn-navy" href="' . $e($i->pdf) . '" download>' . $e(self::ui('download')) . '</a>'
                . '<a class="mh-btn mh-btn-line" href="' . $e($i->list) . '">' . $e(self::ui('past')) . '</a></div></div></div>';
        } catch (\Throwable $x) {
            Log::add('Home publication: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function text(array $props): string
    {
        return '';
    }

    /** Hero slides and tabs (once per page). */
    private static function js(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<script>
(function(){
function hero(root){var s=root.querySelectorAll('.mh-slide'),d=root.querySelectorAll('[data-mh-dot]'),k=0;
function go(n){k=(n+s.length)%s.length;s.forEach(function(x,i){x.hidden=i!==k;});d.forEach(function(b){if(+b.getAttribute('data-mh-dot')===k){b.setAttribute('aria-current','true');}else{b.removeAttribute('aria-current');}});}
d.forEach(function(b){b.addEventListener('click',function(){go(+b.getAttribute('data-mh-dot'));});});
root.querySelectorAll('[data-mh-prev]').forEach(function(b){b.addEventListener('click',function(){go(k-1);});});root.querySelectorAll('[data-mh-next]').forEach(function(b){b.addEventListener('click',function(){go(k+1);});});}
function tabs(root){var t=root.querySelectorAll('[data-mh-tab]'),p=root.querySelectorAll('[data-mh-pane]');
t.forEach(function(b){b.addEventListener('click',function(){t.forEach(function(x){x.setAttribute('aria-selected',String(x===b));});p.forEach(function(x){x.hidden=x.getAttribute('data-mh-pane')!==b.getAttribute('data-mh-tab');});});});}
function init(){document.querySelectorAll('[data-mh-hero]').forEach(hero);document.querySelectorAll('[data-mh-tabs]').forEach(tabs);}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();
</script>
HTML;
    }
}
