<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

/**
 * Small homepage blocks that YOOtheme can't build natively, because they depend on event and visit dates:
 * upcoming events, the hierarchs' upcoming visits, and the newest Credința issue.
 * Everything else on the homepage is native YOOtheme elements that editors change in the builder.
 * The markup uses UIkit classes (cards, buttons, tabs), so the theme's Style settings still apply.
 */
final class Home
{
    private const UI = [
        'all_events' => ['Toate evenimentele', 'All events', 'Todos los eventos'],
        'no_events'  => ['Evenimentele viitoare apar aici imediat ce sunt publicate.', 'Upcoming events appear here as soon as they are published.', 'Los próximos eventos aparecen aquí en cuanto se publican.'],
        'full_itin'  => ['Itinerarul complet', 'Full itinerary', 'Itinerario completo'],
        'no_visits'  => ['Nu sunt vizite anunțate deocamdată.', 'No visits announced yet.', 'Aún no hay visitas anunciadas.'],
        'all_issues' => ['Toate numerele', 'All issues', 'Todos los números'],
    ];

    private const I_PIN = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';

    private static function li(): int
    {
        $l = strtolower(substr(Factory::getApplication()->getLanguage()->getTag(), 0, 2));
        return ['ro' => 0, 'en' => 1, 'es' => 2][$l] ?? 1;
    }

    private static function ui(string $k): string
    {
        return self::UI[$k][self::li()];
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

    /* ------------------------------------------------------------------ upcoming events */

    public static function events(array $props): string
    {
        try {
            [$rows, $list] = Events::upcoming(self::n($props, 'count', 4, 12));
            $e = [self::class, 'e'];
            $cols = self::n($props, 'columns', 4, 4);
            $h = '<div class="mhm mhm-ev">';
            if (!$rows) {
                $h .= '<p class="mhm-empty">' . $e(self::ui('no_events')) . '</p>';
            } else {
                $h .= '<div class="uk-grid-match uk-child-width-1-2@s uk-child-width-1-' . $cols . '@m" uk-grid>';
                foreach ($rows as $r) {
                    $h .= '<div><a class="uk-card uk-card-default uk-card-hover uk-link-toggle mhm-card" href="' . $e($r['url']) . '">'
                        . '<div class="mhm-top">' . $r['box'] . ($r['group'] !== '' ? '<span class="mhm-kick">' . $e($r['group']) . '</span>' : '') . '</div>'
                        . '<div class="mhm-body"><h3 class="uk-h4 uk-margin-remove uk-link-heading">' . $e($r['title']) . '</h3>'
                        . '<div class="uk-text-meta uk-margin-small-top">' . $e($r['span']) . '</div>'
                        . ($r['place'] !== '' ? '<div class="mhm-where">' . self::I_PIN . '<span>' . $e($r['place']) . '</span></div>' : '')
                        . '</div></a></div>';
                }
                $h .= '</div>';
            }
            if (($props['show_link'] ?? true) !== false && $list !== '') {
                $h .= '<p class="mhm-more"><a class="uk-button uk-button-text" href="' . $e($list) . '">' . $e(self::ui('all_events')) . ' →</a></p>';
            }
            return $h . '</div>' . self::css();
        } catch (\Throwable $x) {
            Log::add('Home events: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /* ------------------------------------------------------------------ hierarchs' visits */

    public static function itinerary(array $props): string
    {
        try {
            $groups = array_values(array_filter(Itinerary::upcoming(self::n($props, 'count', 4, 10))));
            if (!$groups) {
                return '';
            }
            $e = [self::class, 'e'];
            $id = 'mhm-it-' . substr(md5(json_encode($props) . microtime()), 0, 6);
            $h = '<div class="mhm mhm-it">';
            if (count($groups) > 1) {
                $h .= '<ul class="uk-subnav uk-subnav-pill mhm-tabs" uk-switcher="connect: #' . $id . '">';
                foreach ($groups as $g) {
                    $h .= '<li><a href="#">' . $e($g['name']) . '</a></li>';
                }
                $h .= '</ul>';
            }
            $h .= '<div id="' . $id . '" class="uk-switcher">';
            foreach ($groups as $g) {
                $h .= '<div>';
                if (!$g['rows']) {
                    $h .= '<p class="mhm-empty">' . $e(self::ui('no_visits')) . '</p>';
                }
                foreach ($g['rows'] as $r) {
                    $h .= '<div class="mhm-row">' . $r['box'] . '<div class="mhm-rowtx">'
                        . ($r['feast'] !== '' ? '<div class="mhm-kick">' . $e($r['feast']) . '</div>' : '')
                        . '<div class="mhm-rt">' . $e($r['title']) . ($r['time'] !== '' ? ' <span class="uk-text-meta">· ' . $e($r['time']) . '</span>' : '') . '</div>'
                        . ($r['place'] !== '' ? '<div class="mhm-where">' . self::I_PIN . '<span>' . $r['place'] . '</span></div>' : '')
                        . '</div></div>';
                }
                if ($g['url'] !== '') {
                    $h .= '<p class="mhm-more"><a class="uk-button uk-button-text" href="' . $e($g['url']) . '">' . $e(self::ui('full_itin')) . ' →</a></p>';
                }
                $h .= '</div>';
            }
            return $h . '</div></div>' . self::css();
        } catch (\Throwable $x) {
            Log::add('Home itinerary: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
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
            $intro = trim((string) ($props['intro'] ?? ''));
            $h = '<div class="mhm mhm-pub"><div class="uk-grid-medium uk-flex-middle" uk-grid>'
                . '<div class="uk-width-1-3@s"><a class="mhm-cover" href="' . $e($i->url) . '">'
                . ($i->cover !== '' ? '<img src="' . $e($i->cover) . '" alt="' . $e($i->name . ', ' . $i->label) . '" width="680" height="880" loading="lazy">' : '<span>' . $e($i->name) . '</span>')
                . '</a></div><div class="uk-width-expand@s">'
                . '<div class="mhm-kick">' . $e($i->cur) . '</div>'
                . '<h3 class="uk-h2 uk-margin-small-top uk-margin-remove-bottom">' . $e($i->name) . '</h3>'
                . '<p class="uk-text-lead uk-margin-small-top">' . $e($i->label) . '</p>'
                . ($intro !== '' ? '<p>' . $e($intro) . '</p>' : '')
                . '<div class="mhm-acts"><a class="uk-button uk-button-primary" href="' . $e($i->url) . '">' . $e($i->read) . '</a>'
                . '<a class="uk-button uk-button-default" href="' . $e($i->pdf) . '" download>' . $e($i->dl) . '</a></div>'
                . ($i->count > 1 ? '<p class="mhm-more"><a class="uk-button uk-button-text" href="' . $e($i->list) . '">' . $e(self::ui('all_issues')) . ' →</a></p>' : '')
                . '</div></div></div>';
            return $h . self::css();
        } catch (\Throwable $x) {
            Log::add('Home publication: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function text(array $props): string
    {
        return '';
    }

    private static function css(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>
/* Homepage blocks (Mitropolia plugin). Colors follow the theme where UIkit classes are used. */
.mhm{--mh-navy:#172E5C;--mh-red:#A32D36;--mh-gold:#B08D2E;--mh-line:#E4D8BE;--mh-muted:#5A6378}
.mhm .db{flex:0 0 auto;width:64px;text-align:center;border:1px solid var(--mh-line);border-radius:4px;background:#fff;padding:6px 4px;line-height:1.1;color:var(--mh-navy)}
.mhm .db .d{font-family:'Baskervville',Georgia,serif;font-size:26px;font-weight:500}
.mhm .db.rng .d{font-size:19px}
.mhm .db .w{font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--mh-red)}
.mhm .db .y{font-size:11px;color:var(--mh-muted)}
.mhm-card{display:flex;flex-direction:column;text-decoration:none;color:inherit;height:100%}
.mhm-card:hover{text-decoration:none}
.mhm-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:20px 20px 0}
.mhm-body{padding:14px 20px 20px}
.mhm-kick{font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--mh-red)}
.mhm-top .mhm-kick{text-align:right;max-width:60%}
.mhm-where{display:flex;gap:6px;align-items:flex-start;margin-top:8px;font-size:15px;color:var(--mh-muted)}
.mhm-where svg{flex:0 0 auto;margin-top:3px}
.mhm-more{margin:20px 0 0}
.mhm-empty{color:var(--mh-muted);margin:0}
.mhm-tabs{margin-bottom:18px}
.mhm-row{display:flex;gap:16px;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--mh-line)}
.mhm-row:first-child{padding-top:0}
.mhm-rowtx{min-width:0}
.mhm-rt{font-weight:600;font-size:17px;line-height:1.35}
.mhm-cover{display:block;box-shadow:0 10px 30px rgba(23,46,92,.18);border-radius:3px;overflow:hidden;max-width:300px}
.mhm-cover img{display:block;width:100%;height:auto}
.mhm-cover span{display:flex;aspect-ratio:17/22;align-items:center;justify-content:center;background:var(--mh-navy);color:#fff;font-family:'Baskervville',Georgia,serif;font-size:22px;padding:16px;text-align:center}
.mhm-acts{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
</style>
HTML;
    }
}
