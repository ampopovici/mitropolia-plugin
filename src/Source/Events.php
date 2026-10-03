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
 * Events ("Events list" and "Event" builder elements), as in the approved mockups with Alex's changes of Oct 2:
 * no calendar buttons, no links to news reports, and date boxes that show the whole span (7–9 DEC),
 * like the pastoral itinerary.
 * An event is an article in Events (RO/EN/ES), one per language, linked as associations. Fields: event-start,
 * event-end, event-all-day, event-parish, event-place, event-address, event-group, event-registration,
 * event-deadline, event-fee; photos from news-gallery-folder when that field is assigned. The registration link
 * is the article's own Link A. Spanish falls back to the English events while the Spanish category is empty.
 */
final class Events
{
    private const PER_PAGE = 12;
    private const FIELDS = ['event-start', 'event-end', 'event-all-day', 'event-parish', 'event-place', 'event-address', 'event-group',
        'event-registration', 'event-deadline', 'event-fee', 'news-gallery-folder'];
    private const GROUPS = ['metropolia', 'archdiocese', 'canada', 'south-america'];
    private const UI = [
        'title'     => ['Evenimente', 'Events', 'Eventos'],
        'intro'     => ['Sărbători, congrese, retrageri și întâlniri din toată Mitropolia. Vizitele ierarhilor sunt în itinerarul pastoral.', 'Feasts, congresses, retreats and gatherings across the Metropolia. The hierarchs’ visits are in the pastoral itinerary.', 'Fiestas, congresos, retiros y encuentros en toda la Metrópolis. Las visitas de los jerarcas están en el itinerario pastoral.'],
        'itin'      => ['itinerarul pastoral', 'pastoral itinerary', 'itinerario pastoral'],
        'all'       => ['Toate', 'All', 'Todos'],
        'upcoming'  => ['Evenimente viitoare', 'Upcoming', 'Próximos eventos'],
        'past'      => ['Evenimente trecute', 'Past events', 'Eventos pasados'],
        'allpast'   => ['Toate evenimentele trecute', 'All past events', 'Todos los eventos pasados'],
        'none_up'   => ['Nu sunt evenimente programate în această secțiune.', 'No upcoming events in this section yet.', 'Todavía no hay eventos programados en esta sección.'],
        'none'      => ['Nu există încă evenimente.', 'There are no events yet.', 'Todavía no hay eventos.'],
        'n1'        => ['eveniment', 'event', 'evento'],
        'nn'        => ['evenimente', 'events', 'eventos'],
        'details'   => ['Detalii', 'Details', 'Detalles'],
        'register'  => ['Înscriere', 'Register', 'Inscripción'],
        'ended'     => ['Acest eveniment s-a încheiat.', 'This event has ended.', 'Este evento ya terminó.'],
        'photos'    => ['Fotografii', 'Photos', 'Fotos'],
        'seephotos' => ['Vedeți fotografiile', 'View photos', 'Ver las fotos'],
        'dir'       => ['Indicații', 'Directions', 'Cómo llegar'],
        'date'      => ['Data', 'Date', 'Fecha'],
        'time'      => ['Ora', 'Time', 'Hora'],
        'allday'    => ['Toată ziua', 'All day', 'Todo el día'],
        'place'     => ['Locul', 'Place', 'Lugar'],
        'parish'    => ['Pagina parohiei', 'Parish page', 'Página de la parroquia'],
        'org'       => ['Organizator', 'Organizer', 'Organiza'],
        'contact'   => ['Contact', 'Contact', 'Contacto'],
        'regbox'    => ['Înscriere', 'Registration', 'Inscripción'],
        'deadline'  => ['Înscrierile se încheie pe', 'Registration closes', 'Inscripciones hasta el'],
        'fee'       => ['Taxă', 'Fee', 'Cuota'],
        'regreq'    => ['Este necesară înscrierea.', 'Registration is required.', 'Se requiere inscripción.'],
        'share'     => ['Distribuiți', 'Share', 'Compartir'],
        'copy'      => ['Copiază linkul', 'Copy link', 'Copiar enlace'],
        'copied'    => ['Link copiat', 'Link copied', 'Enlace copiado'],
        'more'      => ['Alte evenimente viitoare', 'More upcoming events', 'Más próximos eventos'],
        'allev'     => ['Toate evenimentele', 'All events', 'Todos los eventos'],
        'pages'     => ['Pagini', 'Pages', 'Páginas'],
        'home'      => ['Acasă', 'Home', 'Inicio'],
        'enlarge'   => ['apăsați pe o fotografie pentru a o mări', 'click a photo to enlarge it', 'pulse una foto para ampliarla'],
        'back'      => ['Înapoi la evenimentele viitoare', 'Back to upcoming events', 'Volver a los próximos eventos'],
    ];
    private const GROUP_LABEL = [
        'metropolia'    => ['Mitropolia', 'Metropolia', 'Metrópolis'],
        'archdiocese'   => ['Arhiepiscopia', 'Archdiocese', 'Arquidiócesis'],
        'canada'        => ['Episcopia Canadei', 'Diocese of Canada', 'Diócesis de Canadá'],
        'south-america' => ['America de Sud', 'South America', 'Sudamérica'],
    ];
    private const ORGANIZER = [
        'metropolia'  => ['Mitropolia Ortodoxă Română a celor Două Americi', 'Romanian Orthodox Metropolia of the Americas', 'Metrópolis Ortodoxa Rumana de las Dos Américas'],
        'archdiocese' => ['Arhiepiscopia Ortodoxă Română a Statelor Unite ale Americii', 'Romanian Orthodox Archdiocese of the United States of America', 'Arquidiócesis Ortodoxa Rumana de los Estados Unidos de América'],
        'canada'      => ['Episcopia Ortodoxă Română a Canadei', 'Romanian Orthodox Diocese of Canada', 'Diócesis Ortodoxa Rumana de Canadá'],
    ];
    private const MON = [
        'ro' => ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Noi', 'Dec'],
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'es' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
    ];
    private const MONTH = [
        'ro' => ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    ];
    private const DAY = [
        'ro' => ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'],
        'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'es' => ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
    ];
    private const I_PIN = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
    private const I_CAL = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>';
    private const I_CLOCK = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
    private const I_USER = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>';
    private const I_MAIL = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>';
    private const I_DIR = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 11l18-8-8 18-2-8z"/></svg>';
    private const I_FB = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8h3V4h-3c-2.8 0-4 1.7-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8.5c0-.3.2-.5.5-.5z"/></svg>';
    private const I_LINK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>';

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
                ->where('alias = ' . $db->quote('events-' . $l))->where('published = 1'), 0, 1)->loadResult();
        }
        return $ids[$l];
    }

    private static function today(): string
    {
        return Factory::getDate('now', Factory::getApplication()->get('offset', 'UTC'))->format('Y-m-d', true);
    }

    private static function base(array $cats)
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());
        return $db->getQuery(true)->from($db->quoteName('#__content', 'a'))
            ->whereIn('a.catid', array_map('intval', $cats))->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels ?: [1]);
    }

    private static function values(array $ids, array $names): array
    {
        if (!$ids) {
            return [];
        }
        $db = self::db();
        $q = $db->getQuery(true)->select(['v.item_id', 'f.name', 'v.value'])->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')
            ->whereIn('f.name', $names, ParameterType::STRING)
            ->whereIn('v.item_id', array_map('strval', $ids), ParameterType::STRING);
        $out = [];
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(int) $r->item_id][$r->name] = trim((string) $r->value);
        }
        return $out;
    }

    /** All published events of the page language (Spanish: English while there is no Spanish event). */
    private static function all(): array
    {
        $l = self::lang();
        $cat = self::catId($l);
        $db = self::db();
        $rows = $cat ? $db->setQuery(self::base([$cat])->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.images', 'a.urls', 'a.introtext', 'a.publish_up']))->loadObjectList() : [];
        if (!$rows && $l === 'es' && self::catId('en')) {
            $rows = $db->setQuery(self::base([self::catId('en')])->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.images', 'a.urls', 'a.introtext', 'a.publish_up']))->loadObjectList();
        }
        return self::shape($rows);
    }

    /** Events with their fields and parish, ordered by start. */
    private static function shape(array $rows): array
    {
        $vals = self::values(array_map(fn ($r) => (int) $r->id, $rows), self::FIELDS);
        $pids = [];
        foreach ($vals as $v) {
            if ((int) ($v['event-parish'] ?? 0)) {
                $pids[(int) $v['event-parish']] = true;
            }
        }
        $par = self::parishes(array_keys($pids));
        $out = [];
        foreach ($rows as $r) {
            $v = $vals[(int) $r->id] ?? [];
            // calendar fields are stored in UTC: show them in the site's time zone
            foreach (['event-start', 'event-end', 'event-deadline'] as $k) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', (string) ($v[$k] ?? ''))) {
                    $v[$k] = self::local((string) $v[$k]);
                }
            }
            $s = (string) ($v['event-start'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
                $s = (string) $r->publish_up;
            }
            $en = (string) ($v['event-end'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $en) || $en < $s) {
                $en = '';
            }
            // a place typed in the event wins over the parish (the parish list has no empty choice)
            $p = trim((string) ($v['event-place'] ?? '')) === '' ? ($par[(int) ($v['event-parish'] ?? 0)] ?? null) : null;
            $group = in_array($v['event-group'] ?? '', self::GROUPS, true) ? $v['event-group'] : '';
            $out[] = (object) [
                'id' => (int) $r->id, 'title' => (string) $r->title, 'a' => $r, 'start' => $s, 'end' => $en,
                'allday' => ($v['event-all-day'] ?? '') === '1', 'group' => $group, 'parish' => $p,
                'place' => (string) ($v['event-place'] ?? ''), 'address' => (string) ($v['event-address'] ?? ''),
                'reg' => ($v['event-registration'] ?? '') === '1', 'deadline' => (string) ($v['event-deadline'] ?? ''),
                'fee' => (string) ($v['event-fee'] ?? ''), 'folder' => (string) ($v['news-gallery-folder'] ?? ''),
                'link' => self::regLink((string) $r->urls),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a->start, $b->start) ?: $a->id <=> $b->id);
        return $out;
    }

    private static function local(string $utc): string
    {
        try {
            $tz = new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC'));
            return Factory::getDate(strlen($utc) === 10 ? $utc . ' 00:00:00' : $utc, 'UTC')->setTimezone($tz)->format('Y-m-d H:i:s', true);
        } catch (\Throwable $x) {
            return $utc;
        }
    }

    private static function regLink(string $urls): string
    {
        $u = json_decode($urls, true) ?: [];
        $a = trim((string) ($u['urla'] ?? ''));
        return preg_match('#^(https?://|mailto:|/)#i', $a) ? $a : '';
    }

    /** id => [name, city line, url, point [lat, lng] or null, email, phone]. */
    private static function parishes(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $l = self::lang();
        $db = self::db();
        $rows = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias', 'catid'])->from('#__content')->whereIn('id', array_map('intval', $ids))->where('state = 1'))->loadObjectList();
        $v = self::values(array_map(fn ($r) => (int) $r->id, $rows), ['parish-name-ro', 'parish-name-en', 'parish-name-es', 'parish-city', 'parish-state', 'parish-country', 'parish-street', 'parish-location', 'parish-email', 'parish-phone']);
        $out = [];
        foreach ($rows as $r) {
            $x = $v[(int) $r->id] ?? [];
            $name = ($x['parish-name-' . $l] ?? '') ?: (($l === 'es' ? ($x['parish-name-en'] ?? '') : '') ?: (($x['parish-name-ro'] ?? '') ?: (string) $r->title));
            $city = trim(implode(', ', array_filter([(string) ($x['parish-city'] ?? ''), (string) ($x['parish-state'] ?? '')])));
            $pt = null;
            if (preg_match('/(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)/', (string) ($x['parish-location'] ?? ''), $m)
                && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180 && ((float) $m[1] || (float) $m[2])) {
                $pt = [(float) $m[1], (float) $m[2]];
            }
            $out[(int) $r->id] = [
                'name' => $name, 'city' => $city, 'street' => (string) ($x['parish-street'] ?? ''), 'country' => (string) ($x['parish-country'] ?? ''),
                'url' => Route::_(RouteHelper::getArticleRoute($r->id . ':' . $r->alias, (int) $r->catid, Factory::getApplication()->getLanguage()->getTag())),
                'point' => $pt, 'email' => (string) ($x['parish-email'] ?? ''), 'phone' => (string) ($x['parish-phone'] ?? ''),
            ];
        }
        return $out;
    }

    private static function url(object $ev): string
    {
        $a = $ev->a;
        return Route::_(RouteHelper::getArticleRoute($a->id . ':' . $a->alias, (int) $a->catid, (string) $a->language));
    }

    private static function d1(object $ev): string
    {
        return substr($ev->start, 0, 10);
    }

    private static function d2(object $ev): string
    {
        $e = $ev->end !== '' ? substr($ev->end, 0, 10) : '';
        return $e !== '' && $e > self::d1($ev) ? $e : '';
    }

    private static function isPast(object $ev): bool
    {
        return (self::d2($ev) ?: self::d1($ev)) < self::today();
    }

    /** Date box as in the pastoral itinerary: 7–9 / DEC / 2026, or NOV–DEC for a span over two months. */
    private static function dateBox(object $ev): string
    {
        $l = self::lang();
        $M = self::MON[$l];
        $a = new \DateTimeImmutable(self::d1($ev));
        $d2 = self::d2($ev);
        if ($d2 !== '') {
            $b = new \DateTimeImmutable($d2);
            $mon = $a->format('n') === $b->format('n') ? $M[(int) $a->format('n') - 1] : $M[(int) $a->format('n') - 1] . '–' . $M[(int) $b->format('n') - 1];
            return '<div class="db rng" aria-hidden="true"><div class="d">' . $a->format('j') . '–' . $b->format('j') . '</div><div class="w">' . self::e($mon) . '</div><div class="y">' . $b->format('Y') . '</div></div>';
        }
        return '<div class="db" aria-hidden="true"><div class="d">' . $a->format('j') . '</div><div class="w">' . self::e($M[(int) $a->format('n') - 1]) . '</div><div class="y">' . $a->format('Y') . '</div></div>';
    }

    /** "7–9 decembrie 2026", "December 7 – 9, 2026", "7–9 de diciembre de 2026" (short, for meta lines). */
    private static function span(object $ev): string
    {
        $l = self::lang();
        $M = self::MONTH[$l];
        $a = new \DateTimeImmutable(self::d1($ev));
        $d2 = self::d2($ev);
        $b = $d2 !== '' ? new \DateTimeImmutable($d2) : null;
        $ma = $M[(int) $a->format('n') - 1];
        if (!$b) {
            return $l === 'en' ? $ma . ' ' . $a->format('j') . ', ' . $a->format('Y') : ($l === 'es' ? $a->format('j') . ' de ' . $ma . ' de ' . $a->format('Y') : $a->format('j') . ' ' . $ma . ' ' . $a->format('Y'));
        }
        $mb = $M[(int) $b->format('n') - 1];
        $sameM = $a->format('Y-n') === $b->format('Y-n');
        $sameY = $a->format('Y') === $b->format('Y');
        if ($l === 'en') {
            return $sameM ? $ma . ' ' . $a->format('j') . ' – ' . $b->format('j') . ', ' . $b->format('Y')
                : $ma . ' ' . $a->format('j') . ($sameY ? '' : ', ' . $a->format('Y')) . ' – ' . $mb . ' ' . $b->format('j') . ', ' . $b->format('Y');
        }
        if ($l === 'es') {
            return $sameM ? $a->format('j') . '–' . $b->format('j') . ' de ' . $mb . ' de ' . $b->format('Y')
                : $a->format('j') . ' de ' . $ma . ($sameY ? '' : ' de ' . $a->format('Y')) . ' – ' . $b->format('j') . ' de ' . $mb . ' de ' . $b->format('Y');
        }
        return $sameM ? $a->format('j') . '–' . $b->format('j') . ' ' . $mb . ' ' . $b->format('Y')
            : $a->format('j') . ' ' . $ma . ($sameY ? '' : ' ' . $a->format('Y')) . ' – ' . $b->format('j') . ' ' . $mb . ' ' . $b->format('Y');
    }

    /** Long form with weekdays, for the event page. */
    private static function longDate(object $ev): string
    {
        $l = self::lang();
        $one = function (\DateTimeImmutable $d, bool $year) use ($l): string {
            $w = self::DAY[$l][(int) $d->format('w')];
            $m = self::MONTH[$l][(int) $d->format('n') - 1];
            if ($l === 'en') {
                return $w . ', ' . $m . ' ' . $d->format('j') . ($year ? ', ' . $d->format('Y') : '');
            }
            if ($l === 'es') {
                return $w . ' ' . $d->format('j') . ' de ' . $m . ($year ? ' de ' . $d->format('Y') : '');
            }
            return $w . ', ' . $d->format('j') . ' ' . $m . ($year ? ' ' . $d->format('Y') : '');
        };
        $a = new \DateTimeImmutable(self::d1($ev));
        $d2 = self::d2($ev);
        if ($d2 === '') {
            return self::ucfirst($one($a, true));
        }
        $b = new \DateTimeImmutable($d2);
        return self::ucfirst($one($a, $a->format('Y') !== $b->format('Y')) . ' – ' . $one($b, true));
    }

    private static function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function clock(string $dt): string
    {
        if (!preg_match('/\s(\d{2}):(\d{2})/', $dt, $m) || ($m[1] === '00' && $m[2] === '00')) {
            return '';
        }
        $h = (int) $m[1];
        return self::lang() === 'en' ? sprintf('%d:%s %s', $h % 12 ?: 12, $m[2], $h >= 12 ? 'PM' : 'AM') : $h . ':' . $m[2];
    }

    private static function timeText(object $ev): string
    {
        if ($ev->allday) {
            return self::ui('allday');
        }
        $a = self::clock($ev->start);
        $b = self::d2($ev) === '' && $ev->end !== '' ? self::clock($ev->end) : '';
        return $a !== '' ? $a . ($b !== '' && $b !== $a ? ' – ' . $b : '') : '';
    }

    /** [name, second line, parish url or ''] */
    private static function where(object $ev): array
    {
        if ($ev->parish) {
            $p = $ev->parish;
            return [$p['name'], $p['city'], $p['url']];
        }
        return [$ev->place, $ev->address, ''];
    }

    private static function placeLine(object $ev): string
    {
        [$n, $c] = self::where($ev);
        return trim($n . ($n !== '' && $c !== '' ? ', ' : '') . $c);
    }

    private static function groupLabel(string $g): string
    {
        return $g !== '' ? self::GROUP_LABEL[$g][self::li()] : '';
    }

    private static function image(object $ev): string
    {
        $im = json_decode((string) $ev->a->images, true) ?: [];
        foreach (['image_fulltext', 'image_intro'] as $k) {
            $p = trim(explode('#', (string) ($im[$k] ?? ''), 2)[0]);
            if ($p !== '' && strpos($p, '..') === false && is_file(JPATH_ROOT . '/' . ltrim($p, '/'))) {
                return ltrim($p, '/');
            }
        }
        $ph = $ev->folder !== '' ? News::folderPhotos($ev->folder) : [];
        return $ph[0] ?? '';
    }

    private static function listUrl(): string
    {
        static $url = null;
        if ($url !== null) {
            return $url;
        }
        $app = Factory::getApplication();
        $tag = $app->getLanguage()->getTag();
        $l = self::lang();
        $cats = array_filter([self::catId($l), $l === 'es' ? self::catId('en') : 0]);
        foreach ($app->getMenu()->getItems(['component', 'language'], ['com_content', $tag]) as $item) {
            $q = $item->query ?? [];
            if (($q['view'] ?? '') === 'category' && in_array((int) ($q['id'] ?? 0), $cats, true)) {
                return $url = Route::_('index.php?Itemid=' . (int) $item->id);
            }
        }
        return $url = Route::_(RouteHelper::getCategoryRoute(self::catId($l) ?: self::catId('en'), $tag));
    }

    /** Link to the Metropolitan's pastoral itinerary page in the page language, if there is a menu item. */
    private static function itineraryUrl(): string
    {
        $app = Factory::getApplication();
        foreach ($app->getMenu()->getItems(['language'], [$app->getLanguage()->getTag()]) as $item) {
            if (preg_match('#(mitropolit|metropolitan|metropolitano|metropolita)/(itinerar|pastoral-itinerary|itinerario-pastoral|itinerary|itinerario)$#', (string) $item->route)) {
                return Route::_('index.php?Itemid=' . (int) $item->id);
            }
        }
        return '';
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
        return '<nav class="mev-crumbs" aria-label="Breadcrumb">' . implode('<span aria-hidden="true">›</span>', $out) . '</nav>';
    }

    /* ------------------------------------------------------------------ list */

    public static function listing(array $props): string
    {
        try {
            return self::listHtml($props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Events list: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function listingText(array $props): string
    {
        return '';
    }

    private static function row(object $ev): string
    {
        $e = [self::class, 'e'];
        $g = self::groupLabel($ev->group);
        $pl = self::placeLine($ev);
        $reg = $ev->reg && $ev->link !== '' && !self::isPast($ev) && ($ev->deadline === '' || substr($ev->deadline, 0, 10) >= self::today());
        return '<div class="evrow" data-g="' . $e($ev->group) . '">' . self::dateBox($ev)
            . '<div class="info"><div class="meta">' . ($g !== '' ? '<span class="kick">' . $e($g) . '</span><span class="dot" aria-hidden="true"></span>' : '') . '<span>' . $e(self::span($ev)) . '</span>'
            . (($t = self::timeText($ev)) !== '' && !$ev->allday ? '<span class="dot" aria-hidden="true"></span><span>' . $e($t) . '</span>' : '') . '</div>'
            . '<h3><a class="stretch" href="' . $e(self::url($ev)) . '">' . $e($ev->title) . '</a></h3>'
            . ($pl !== '' ? '<div class="meta where">' . self::I_PIN . '<span>' . $e($pl) . '</span></div>' : '') . '</div>'
            . '<div class="acts">' . ($reg ? '<a class="btn red" href="' . $e($ev->link) . '"' . (preg_match('#^https?://#', $ev->link) && stripos($ev->link, Uri::root()) !== 0 ? ' target="_blank" rel="noopener"' : '') . '>' . $e(self::ui('register')) . '</a>' : '')
            . '<span class="btn line" aria-hidden="true">' . $e(self::ui('details')) . '</span></div></div>';
    }

    private static function card(object $ev): string
    {
        $e = [self::class, 'e'];
        $img = self::image($ev);
        $g = self::groupLabel($ev->group);
        $pl = self::placeLine($ev);
        return '<a class="evcard" data-g="' . $e($ev->group) . '" href="' . $e(self::url($ev)) . '"><span class="ph">'
            . ($img !== '' ? '<img src="' . $e(News::thumbUrl($img)) . '" alt="" loading="lazy">' : '') . '</span><span class="b">'
            . '<span class="meta">' . ($g !== '' ? '<span class="kick">' . $e($g) . '</span><span class="dot" aria-hidden="true"></span>' : '') . '<span>' . $e(self::span($ev)) . '</span></span>'
            . '<h3>' . $e($ev->title) . '</h3>' . ($pl !== '' ? '<span class="meta where">' . self::I_PIN . '<span>' . $e($pl) . '</span></span>' : '') . '</span></a>';
    }

    private static function listHtml(array $props): string
    {
        $e = [self::class, 'e'];
        $all = self::all();
        $up = array_values(array_filter($all, fn ($x) => !self::isPast($x)));
        $past = array_reverse(array_values(array_filter($all, fn ($x) => self::isPast($x))));
        $in = Factory::getApplication()->getInput();
        $showPast = $in->getInt('past', 0) === 1;
        $l = self::lang();

        $title = trim((string) ($props['title'] ?? '')) ?: self::ui('title');
        $intro = trim((string) ($props['intro'] ?? '')) ?: self::ui('intro');
        $itin = self::itineraryUrl();
        $introHtml = $e($intro);
        if ($itin !== '' && trim((string) ($props['intro'] ?? '')) === '') {
            $introHtml = str_replace($e(self::ui('itin')), '<a href="' . $e($itin) . '">' . $e(self::ui('itin')) . '</a>', $introHtml);
        }
        $groups = array_values(array_unique(array_filter(array_map(fn ($x) => $x->group, $all))));
        $chips = '';
        if (count($groups) > 1) {
            $chips = '<div class="chips" role="group" aria-label="' . $e(self::ui('title')) . '"><button type="button" class="chip on" data-g="">' . $e(self::ui('all')) . '</button>';
            foreach (self::GROUPS as $g) {
                if (in_array($g, $groups, true)) {
                    $chips .= '<button type="button" class="chip" data-g="' . $e($g) . '">' . $e(self::groupLabel($g)) . '</button>';
                }
            }
            $chips .= '</div>';
        }
        $crumbs = $showPast ? self::crumbs([[$title, self::listUrl()], [self::ui('past'), '']]) : self::crumbs([[$title, '']]);
        $hero = '<div class="mev-hero"><div class="mev-in">' . $crumbs
            . '<h1 class="mev-h1">' . $e($showPast ? self::ui('past') : $title) . '</h1>' . ($showPast ? '' : '<p class="mev-lead">' . $introHtml . '</p>') . $chips . '</div></div>';

        // parts, for pages whose header is built with YOOtheme elements
        $part = (string) ($props['part'] ?? '');
        if ($part === 'intro') {
            return '<div class="mev mev-tools"><h1 class="mev-h1">' . $e($showPast ? self::ui('past') : $title) . '</h1>' . ($showPast ? '' : '<p class="mev-lead">' . $introHtml . '</p>') . '</div>';
        }
        if ($part === 'chips') {
            return $chips !== '' && !$showPast ? '<div class="mev mev-tools">' . $chips . '</div>' : '';
        }
        if ($part === 'body') {
            $hero = '';
        }

        if ($showPast) {
            $page = max(1, $in->getInt('p', 1));
            $pages = max(1, (int) ceil(count($past) / self::PER_PAGE));
            $page = min($page, $pages);
            $slice = array_slice($past, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
            $base = self::listUrl();
            $sep = strpos($base, '?') === false ? '?' : '&';
            $pg = '';
            if ($pages > 1) {
                $pg = '<nav class="mev-pg" aria-label="' . $e(self::ui('pages')) . '">';
                for ($n = 1; $n <= $pages; $n++) {
                    $pg .= $n === $page ? '<span class="on" aria-current="page">' . $n . '</span>' : '<a href="' . $e($base . $sep . 'past=1' . ($n > 1 ? '&p=' . $n : '')) . '">' . $n . '</a>';
                }
                $pg .= '</nav>';
            }
            $body = $slice ? '<div class="evgrid" data-list>' . implode('', array_map([self::class, 'card'], $slice)) . '</div>' . $pg : '<p class="mev-empty">' . $e(self::ui('none')) . '</p>';
            return '<div class="mev mev-list">' . $hero . '<div class="mev-in mev-sec">' . $body
                . '<p class="mev-back"><a href="' . $e(self::listUrl()) . '">← ' . $e(self::ui('back')) . '</a></p></div></div>';
        }

        $rows = '';
        $cur = '';
        foreach ($up as $ev) {
            $mk = substr(self::d1($ev), 0, 7);
            if ($mk !== $cur) {
                $m = self::MONTH[$l][(int) substr($mk, 5, 2) - 1];
                $rows .= '<h2 class="month">' . $e(self::ucfirst($m) . ' ' . substr($mk, 0, 4)) . '</h2>';
                $cur = $mk;
            }
            $rows .= self::row($ev);
        }
        $n = count($up);
        $upHtml = '<div class="mev-in mev-sec" data-up><div class="sechead"><h2 class="mev-h2">' . $e(self::ui('upcoming')) . '</h2><span class="meta" data-count data-one="' . $e(self::ui('n1')) . '" data-many="' . $e(self::ui('nn')) . '">'
            . $n . ' ' . $e($n === 1 ? self::ui('n1') : self::ui('nn')) . '</span></div>'
            . ($rows !== '' ? '<div class="evlist" data-list>' . $rows . '</div>' : '')
            . '<p class="mev-empty" data-empty' . ($rows !== '' ? ' hidden' : '') . '>' . $e(self::ui('none_up')) . '</p></div>';
        $pastHtml = '';
        if ($past) {
            $base = self::listUrl();
            $pastHtml = '<div class="mev-pastband"><div class="mev-in"><div class="sechead"><h2 class="mev-h2">' . $e(self::ui('past')) . '</h2>'
                . (count($past) > 6 ? '<a class="more" href="' . $e($base . (strpos($base, '?') === false ? '?' : '&') . 'past=1') . '">' . $e(self::ui('allpast')) . ' →</a>' : '') . '</div>'
                . '<div class="evgrid" data-list>' . implode('', array_map([self::class, 'card'], array_slice($past, 0, 6))) . '</div></div></div>';
        }
        return '<div class="mev mev-list">' . $hero . $upHtml . $pastHtml . '</div>';
    }

    /* ------------------------------------------------------------------ one event */

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
            $rows = $db->setQuery(self::base($cats)->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.images', 'a.urls', 'a.introtext', $db->quoteName('a.fulltext'), 'a.publish_up'])->where('a.id = ' . $id))->loadObjectList();
            if (!$rows) {
                return '';
            }
            $ev = self::shape($rows)[0];
            return self::pageHtml($ev, $rows[0], $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Event page: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function pageText(array $props): string
    {
        return '';
    }

    private static function pageHtml(object $ev, object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $past = self::isPast($ev);
        $g = self::groupLabel($ev->group);
        [$pn, $pc, $purl] = self::where($ev);
        $intro = (string) $a->introtext;
        $full = (string) $a->fulltext;
        $lead = trim($full) !== '' ? trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($intro), ENT_QUOTES, 'UTF-8'))) : '';
        $body = trim($full) !== '' ? $full : $intro;
        try {
            $body = HTMLHelper::_('content.prepare', $body, null, 'com_content.article');
        } catch (\Throwable $x) {
        }
        $photos = $ev->folder !== '' ? News::folderPhotos($ev->folder) : [];
        $img = self::image($ev);
        $here = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path']);
        $reg = $ev->reg && !$past;
        $regOpen = $reg && ($ev->deadline === '' || substr($ev->deadline, 0, 10) >= self::today());
        $regBtn = $regOpen && $ev->link !== '' ? '<a class="btn red" href="' . $e($ev->link) . '"' . (preg_match('#^https?://#', $ev->link) && stripos($ev->link, Uri::root()) !== 0 ? ' target="_blank" rel="noopener"' : '') . '>' . $e(self::ui('register')) . '</a>' : '';
        $addr = $ev->parish ? trim(implode(', ', array_filter([$ev->parish['street'], $ev->parish['city'], $ev->parish['country']]))) : trim($ev->place . ', ' . $ev->address, ', ');
        $pt = $ev->parish['point'] ?? null;
        $dirUrl = $pt ? 'https://www.google.com/maps/dir/?api=1&destination=' . $pt[0] . ',' . $pt[1] : ($addr !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($addr) : '');

        $btns = '';
        if (!$past) {
            $btns = $regBtn . ($dirUrl !== '' ? '<a class="btn line" href="' . $e($dirUrl) . '" target="_blank" rel="noopener">' . self::I_DIR . ' ' . $e(self::ui('dir')) . '</a>' : '');
        } elseif ($photos) {
            $btns = '<a class="btn line" href="#mev-photos">' . $e(self::ui('seephotos')) . '</a>';
        }

        $facts = '<div class="fact">' . self::I_CAL . '<div><b>' . $e(self::ui('date')) . '</b>' . $e(self::longDate($ev)) . '</div></div>';
        if (($t = self::timeText($ev)) !== '') {
            $facts .= '<div class="fact">' . self::I_CLOCK . '<div><b>' . $e(self::ui('time')) . '</b>' . $e($t) . '</div></div>';
        }
        if ($pn !== '' || $pc !== '') {
            $facts .= '<div class="fact">' . self::I_PIN . '<div><b>' . $e(self::ui('place')) . '</b>' . $e($pn) . ($pn !== '' && $pc !== '' ? '<br>' : '') . $e($pc)
                . ($purl !== '' ? '<br><a class="plink" href="' . $e($purl) . '">' . $e(self::ui('parish')) . ' →</a>' : '') . '</div></div>';
        }
        if (isset(self::ORGANIZER[$ev->group])) {
            $facts .= '<div class="fact">' . self::I_USER . '<div><b>' . $e(self::ui('org')) . '</b>' . $e(self::ORGANIZER[$ev->group][self::li()]) . '</div></div>';
        }
        if ($ev->parish && $ev->parish['email'] !== '') {
            $facts .= '<div class="fact">' . self::I_MAIL . '<div><b>' . $e(self::ui('contact')) . '</b><a href="mailto:' . $e($ev->parish['email']) . '">' . $e($ev->parish['email']) . '</a></div></div>';
        }

        $side = '';
        if ($pt) {
            [$tiles, $attr] = Parishes::tiles();
            $cfg = json_encode(['pt' => $pt, 'tiles' => $tiles, 'attr' => $attr], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
            $side .= '<div class="box mapbox"><div class="mev-map" data-map=\'' . $cfg . '\'></div><div class="mapcap"><b>' . $e($pn) . '</b>' . ($pc !== '' ? '<span>' . $e($pc) . '</span>' : '') . '</div></div>';
        }
        if ($reg) {
            $side .= '<div class="box"><h4>' . $e(self::ui('regbox')) . '</h4><p>' . $e(self::ui('regreq'))
                . ($ev->deadline !== '' ? ' ' . $e(self::ui('deadline')) . ' ' . $e(self::span((object) ['start' => $ev->deadline, 'end' => ''])) . '.' : '')
                . ($ev->fee !== '' ? '<br>' . $e(self::ui('fee')) . ': ' . $e($ev->fee) : '') . '</p>' . ($regBtn !== '' ? str_replace('class="btn red"', 'class="btn red wide"', $regBtn) : '') . '</div>';
        }
        $share = rawurlencode($here);
        $side .= '<div class="box"><h4>' . $e(self::ui('share')) . '</h4><div class="share">'
            . '<a href="https://www.facebook.com/sharer/sharer.php?u=' . $share . '" target="_blank" rel="noopener" aria-label="Facebook">' . self::I_FB . '</a>'
            . '<a href="mailto:?subject=' . rawurlencode($ev->title) . '&amp;body=' . $share . '" aria-label="Email">' . str_replace('20', '18', self::I_MAIL) . '</a>'
            . '<button type="button" data-copy="' . $e($here) . '" data-done="' . $e(self::ui('copied')) . '" aria-label="' . $e(self::ui('copy')) . '">' . self::I_LINK . '</button></div></div>';

        $gal = $photos ? '<section class="mev-photos" id="mev-photos"><div class="sechead"><h2 class="mev-h2">' . $e(self::ui('photos')) . '</h2><span class="meta">'
            . $e(News::photoCount(count($photos))) . ' · ' . $e(self::ui('enlarge')) . '</span></div>' . News::galleryHtml($photos, false) . '</section>' : '';

        // more upcoming events
        $more = '';
        $others = array_slice(array_values(array_filter(self::all(), fn ($x) => !self::isPast($x) && $x->id !== $ev->id)), 0, 3);
        if ($others) {
            $cards = '';
            foreach ($others as $o) {
                $cards .= '<a class="evmini" href="' . $e(self::url($o)) . '">' . self::dateBox($o) . '<span class="info">' . (($og = self::groupLabel($o->group)) !== '' ? '<span class="kick">' . $e($og) . '</span>' : '')
                    . '<span class="t">' . $e($o->title) . '</span>' . (($opl = self::placeLine($o)) !== '' ? '<span class="meta">' . $e($opl) . '</span>' : '') . '</span></a>';
            }
            $more = '<div class="mev-moreband"><div class="mev-in"><div class="sechead"><h2 class="mev-h2">' . $e(self::ui('more')) . '</h2><a class="more" href="' . $e(self::listUrl()) . '">' . $e(self::ui('allev')) . ' →</a></div><div class="evminis">' . $cards . '</div></div></div>';
        }

        return '<article class="mev mev-art' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">'
            . (($props['part'] ?? '') === 'body' ? '' : '<div class="mev-band"><div class="mev-in">' . self::crumbs(array_values(array_filter([[self::ui('title'), self::listUrl()], $g !== '' ? [$g, ''] : [$ev->title, '']]))) . '</div></div>')
            . ($past ? '<div class="mev-ended"><div class="mev-in"><b>' . $e(self::ui('ended')) . '</b>' . ($photos ? '<a href="#mev-photos">' . $e(self::ui('seephotos')) . ' ↓</a>' : '') . '</div></div>' : '')
            . '<header class="mev-in mev-head"><div class="grid"><div>'
            . ($g !== '' ? '<div class="meta"><span class="kick">' . $e($g) . '</span></div>' : '')
            . '<h1 class="mev-title">' . $e($ev->title) . '</h1>' . ($lead !== '' ? '<p class="mev-leadp">' . $e($lead) . '</p>' : '')
            . ($btns !== '' ? '<div class="btns">' . $btns . '</div>' : '') . '</div>'
            . '<aside class="box facts">' . $facts . '</aside></div></header>'
            . '<div class="mev-in mev-body"><div class="grid"><div class="main">'
            . ($img !== '' ? '<figure class="lead"><img src="' . $e(Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $img)))) . '" alt=""></figure>' : '')
            . (trim(strip_tags($body, '<img>')) !== '' ? '<div class="prose">' . $body . '</div>' : '') . $gal . '</div>'
            . '<aside class="side">' . $side . '</aside></div></div>'
            . $more . '</article>';
    }

    /* ------------------------------------------------------------------ CSS and JS */

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return News::sharedCss() . <<<'HTML'
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>
<style>
/* Events list and Event page (Mitropolia plugin), as in the approved mockups. */
.mev{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-gold:#B99755;--mp-ink:#1B2A4A;--mp-muted:#6B6F7B;--mp-line:#E4D8BE;--mp-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:var(--mp-ink)}
.mev a{text-decoration:none;color:var(--mp-blue)}
.mev a:hover{color:var(--mp-red)}
.mev [hidden]{display:none!important}
.mev-in{max-width:1184px;margin:0 auto;box-sizing:border-box}
.mev-hero,.mev-band,.mev-pastband,.mev-moreband,.mev-ended{position:relative;box-shadow:0 0 0 100vmax var(--bgc);clip-path:inset(0 -100vmax);background:var(--bgc)}
.mev-hero{--bgc:var(--mp-ivory);border-bottom:1px solid var(--mp-line);padding:22px 0 44px}
.mev-band{--bgc:var(--mp-ivory);border-bottom:1px solid var(--mp-line);padding:18px 0}
.mev-ended{--bgc:var(--mp-blue);color:#fff;padding:14px 0;font-size:16px}
.mev-ended .mev-in{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.mev-ended a{color:#EFE3CB!important;font-weight:600;text-decoration:underline}
.mev-crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:14px;color:#6B5A34}
.mev-h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:56px;line-height:1.05;color:var(--mp-navy);margin:30px 0 14px}
.mev-lead{font-family:'Source Serif 4',Georgia,serif;font-size:20px;line-height:1.55;color:#3C4A66;margin:0;max-width:680px}
.mev-lead a{text-decoration:underline!important}
.mev-h2{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:38px;line-height:1.15;color:var(--mp-navy);margin:0}
.mev .chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:28px}
.mev .chip{font:inherit;font-size:15px;font-weight:600;padding:8px 18px;border-radius:999px;border:1px solid #D9CBAA;background:#fff;color:var(--mp-blue);cursor:pointer}
.mev .chip.on,.mev .chip:hover{background:var(--mp-blue);border-color:var(--mp-blue);color:#fff}
.mev .meta{font-size:14px;color:var(--mp-muted);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.mev .meta .dot{width:3px;height:3px;border-radius:50%;background:var(--mp-gold)}
.mev .kick{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-red)}
.mev .where{gap:6px;color:#3C4A66;flex-wrap:nowrap;align-items:flex-start}.mev .where svg{margin-top:2px}.mev .where svg{flex:none;color:var(--mp-red)}
.mev-sec{padding:24px 0 56px}
.mev .sechead{display:flex;justify-content:space-between;align-items:baseline;gap:16px;flex-wrap:wrap;margin:24px 0 22px}
.mev .sechead .more{font-weight:600;color:var(--mp-red)}
.mev .month{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:26px;color:var(--mp-navy);margin:36px 0 14px;display:flex;align-items:center;gap:16px}
.mev .month::after{content:"";flex:1;height:1px;background:var(--mp-line)}
.mev .evlist .month:first-child{margin-top:8px}
.mev-empty{color:var(--mp-muted);font-size:18px;padding:12px 0}
/* date box: the same as in the pastoral itinerary */
.mev .db{flex:none;width:84px;box-sizing:border-box;border:1px solid var(--mp-line);border-radius:5px;background:#fff;text-align:center;padding:10px 0 9px;display:flex;flex-direction:column;justify-content:center;min-height:84px}
.mev .db .d{font-family:'Baskervville',Georgia,serif;font-size:30px;color:var(--mp-navy);line-height:1}
.mev .db.rng .d{font-size:21px;white-space:nowrap}
.mev .db .w{margin-top:8px;padding:0 4px;font-size:14px;font-weight:700;color:var(--mp-red);letter-spacing:.1em;line-height:1.2;white-space:nowrap;text-transform:uppercase}
.mev .db.rng .w{font-size:12px;letter-spacing:.04em}
.mev .db .y{padding-top:2px;font-size:14px;font-weight:600;color:#3C4A66;letter-spacing:.04em;line-height:1.2}
/* upcoming rows */
.mev .evlist{display:flex;flex-direction:column;gap:14px}
.mev .evrow{position:relative;display:flex;gap:24px;align-items:center;background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:20px 24px;min-height:124px;box-sizing:border-box;transition:box-shadow .2s}
.mev .evrow:hover{box-shadow:0 14px 32px -18px rgba(23,46,92,.45)}
.mev .evrow .info{flex:1;min-width:0;display:flex;flex-direction:column;gap:8px}
.mev .evrow h3{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:24px;line-height:1.25;margin:0}
.mev .evrow h3 a{color:var(--mp-navy)}
.mev .evrow .stretch::after{content:"";position:absolute;inset:0;border-radius:6px}
.mev .evrow .acts{flex:none;display:flex;gap:10px;align-items:center;justify-content:flex-end;min-width:120px}
.mev .evrow .acts a{position:relative;z-index:1}
.mev .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:4px;padding:12px 20px;font:inherit;font-weight:600;font-size:15px;line-height:1.2;border:1px solid transparent;cursor:pointer;white-space:nowrap;box-sizing:border-box}
.mev .btn.red{background:var(--mp-red);color:#fff!important}.mev .btn.red:hover{background:#8A2530}
.mev .btn.line{border-color:var(--mp-blue);color:var(--mp-blue)!important;background:#fff}.mev .btn.line:hover{background:var(--mp-blue);color:#fff!important}
.mev .btn.wide{width:100%}
/* past cards */
.mev-pastband{--bgc:#fff;border-top:1px solid var(--mp-line);padding:40px 0 80px}
.mev .evgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px}
.mev .evcard{display:flex;flex-direction:column;background:#fff;border:1px solid var(--mp-line);border-radius:6px;overflow:hidden;transition:box-shadow .2s}
.mev .evcard:hover{box-shadow:0 14px 32px -18px rgba(23,46,92,.45)}
.mev .evcard .ph{display:block;aspect-ratio:16/10;background:var(--mp-ivory)}
.mev .evcard .ph img{width:100%;height:100%;object-fit:cover;display:block}
.mev .evcard .b{display:flex;flex-direction:column;gap:8px;padding:18px 20px 20px;flex:1}
.mev .evcard h3{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:22px;line-height:1.25;color:var(--mp-navy);margin:0}
.mev-pg{display:flex;gap:6px;justify-content:center;margin-top:32px}
.mev-pg a,.mev-pg span{min-width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--mp-line);border-radius:4px;font-weight:600}
.mev-pg .on{background:var(--mp-blue);border-color:var(--mp-blue);color:#fff}
.mev-back{margin-top:36px}
/* event page */
.mev-head{padding:56px 0 40px}
.mev .grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:56px;align-items:start}
.mev-title{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:50px;line-height:1.12;color:var(--mp-navy);margin:18px 0 22px}
.mev-leadp{font-family:'Source Serif 4',Georgia,serif;font-size:21px;line-height:1.6;color:#3C4A66;margin:0 0 28px}
.mev .btns{display:flex;gap:10px;flex-wrap:wrap}
.mev .box{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:22px}
.mev .box h4{margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34}
.mev .box p{margin:0 0 14px;font-family:'Source Serif 4',Georgia,serif;font-size:16px;line-height:1.55;color:#3C4A66}
.mev .facts{padding:26px}
.mev .fact{display:flex;gap:14px;align-items:flex-start;padding:16px 0;border-top:1px solid #EFE3CB;font-size:16px;line-height:1.45}
.mev .fact:first-child{border-top:0;padding-top:0}.mev .fact:last-child{padding-bottom:0}
.mev .fact svg{flex:none;color:var(--mp-red);margin-top:2px}
.mev .fact b{display:block;font-size:13px;letter-spacing:.1em;text-transform:uppercase;color:#6B5A34;margin-bottom:3px}
.mev .fact .plink{font-weight:600;font-size:15px}
.mev-body{padding-bottom:40px}
.mev .lead{margin:0 0 40px}.mev .lead img{width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:6px;display:block}
.mev .prose{font-family:'Source Serif 4',Georgia,serif;font-size:19px;line-height:1.7;color:#1B2A4A}
.mev .prose h2,.mev .prose h3{font-family:'Baskervville',Georgia,serif;font-weight:500;color:var(--mp-navy);line-height:1.2}
.mev .prose h2{font-size:32px;margin:36px 0 14px}.mev .prose h3{font-size:24px;margin:28px 0 10px}
.mev .prose h4{font-family:'Source Sans 3',sans-serif;font-size:15px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mp-red);margin:24px 0 8px}
.mev .prose img{max-width:100%;height:auto}
.mev .side{display:flex;flex-direction:column;gap:20px;position:sticky;top:100px}
.mev .mapbox{padding:0;overflow:hidden}
.mev .mev-map{height:220px;background:#e9efe3}
.mev .mapcap{padding:14px 20px;font-size:15px}.mev .mapcap b{display:block;color:var(--mp-navy)}.mev .mapcap span{color:var(--mp-muted)}
.mev .share{display:flex;gap:8px}
.mev .share a,.mev .share button{width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--mp-line);border-radius:50%;background:#fff;color:var(--mp-blue);cursor:pointer;font:inherit}
.mev .share a:hover,.mev .share button:hover{background:var(--mp-blue);color:#fff;border-color:var(--mp-blue)}
.mev-photos{margin-top:40px}
.mev-moreband{--bgc:var(--mp-ivory);border-top:1px solid var(--mp-line);margin-top:48px;padding:48px 0 72px}
.mev .evminis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.mev .evmini{display:flex;gap:16px;align-items:center;background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:16px 18px}
.mev .evmini .db{width:70px;min-height:74px}.mev .evmini .db .d{font-size:26px}.mev .evmini .db.rng .d{font-size:18px}
.mev .evmini .info{display:flex;flex-direction:column;gap:4px;min-width:0}
.mev .evmini .t{font-family:'Baskervville',Georgia,serif;font-size:19px;line-height:1.25;color:var(--mp-navy)}
.mev .evmini .meta{font-size:13px}
@media (max-width:1000px){.mev .grid{grid-template-columns:minmax(0,1fr);gap:32px}.mev .side{position:static}.mev .evgrid,.mev .evminis{grid-template-columns:repeat(2,minmax(0,1fr))}.mev .evrow{flex-wrap:wrap}.mev .evrow .acts{width:100%;justify-content:flex-start;padding-left:108px}}
@media (max-width:640px){.mev-h1{font-size:40px}.mev-h2{font-size:30px}.mev-title{font-size:34px}.mev .evrow{padding:16px;gap:14px;align-items:flex-start}.mev .db{width:70px}.mev .evrow h3{font-size:21px}.mev .evrow .acts{padding-left:0}.mev .evrow .acts .btn{flex:1}.mev .evgrid,.mev .evminis{grid-template-columns:minmax(0,1fr)}.mev-head{padding:32px 0 24px}}
@media (prefers-reduced-motion:reduce){.mev .evrow,.mev .evcard{transition:none}}
</style>
<script>
(function(){
function init(){
/* group chips: show only that group's events */
document.querySelectorAll('.mev .chips').forEach(function(box){var root=box.closest('.mev-list')||document;box.addEventListener('click',function(e){var b=e.target.closest('.chip');if(!b)return;var g=b.dataset.g;
 box.querySelectorAll('.chip').forEach(function(x){x.classList.toggle('on',x===b);});
 root.querySelectorAll('[data-list]>[data-g]').forEach(function(x){x.hidden=!!g&&x.dataset.g!==g;});
 root.querySelectorAll('[data-list] .month').forEach(function(h){var x=h.nextElementSibling,any=false;while(x&&!x.classList.contains('month')){if(!x.hidden)any=true;x=x.nextElementSibling;}h.hidden=!any;});
 var up=root.querySelector('[data-up]');if(up){var n=up.querySelectorAll('.evrow:not([hidden])').length,c=up.querySelector('[data-count]');if(c)c.textContent=n+' '+(n===1?c.dataset.one:c.dataset.many);var em=up.querySelector('[data-empty]');if(em)em.hidden=n>0;}});});
/* copy link */
document.querySelectorAll('.mev [data-copy]').forEach(function(b){b.addEventListener('click',function(){var t=b.dataset.copy;(navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){b.title=b.dataset.done;b.style.background='#203D78';b.style.color='#fff';setTimeout(function(){b.style.background='';b.style.color='';},1200);}).catch(function(){window.prompt('',t);});});});
/* map */
function maps(){if(!window.L)return setTimeout(maps,200);document.querySelectorAll('.mev [data-map]').forEach(function(el){if(el._m)return;var c=JSON.parse(el.dataset.map);var m=L.map(el,{scrollWheelZoom:false,zoomControl:true}).setView(c.pt,14);el._m=m;L.tileLayer(c.tiles,{attribution:c.attr,maxZoom:18,subdomains:'abcd'}).addTo(m);L.circleMarker(c.pt,{radius:9,color:'#fff',weight:3,fillColor:'#A32D36',fillOpacity:1}).addTo(m);});}
if(document.querySelector('.mev [data-map]'))maps();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>
HTML;
    }

    /* ------------------------------------------------------------------ homepage */

    /**
     * Next upcoming events for the homepage block: title, url, date span, place, day/month box, photo.
     * Returns [rows, url of the events page].
     */
    public static function upcoming(int $n): array
    {
        $up = array_values(array_filter(self::all(), fn ($x) => !self::isPast($x)));
        $rows = [];
        foreach (array_slice($up, 0, max(1, $n)) as $ev) {
            $img = self::image($ev);
            $rows[] = [
                'title' => $ev->title,
                'url'   => self::url($ev),
                'span'  => self::span($ev),
                'place' => self::placeLine($ev),
                'group' => self::groupLabel($ev->group),
                'box'   => self::dateBox($ev),
                'img'   => $img !== '' ? News::thumbUrl($img) : '',
            ];
        }
        return [$rows, self::listUrl()];
    }

    /**
     * Events for the homepage block: upcoming (soonest first) or recent (latest first).
     * Each row: title, url, day (first day number), when ("Aug 31 – Sep 3"), place.
     */
    public static function homeRows(bool $past, int $n): array
    {
        $all = self::all();
        $list = array_values(array_filter($all, fn ($x) => self::isPast($x) === $past));
        if ($past) {
            $list = array_reverse($list);
        }
        $M = self::MON[self::lang()];
        $rows = [];
        foreach (array_slice($list, 0, max(1, $n)) as $ev) {
            $a = new \DateTimeImmutable(self::d1($ev));
            $d2 = self::d2($ev);
            $when = $M[(int) $a->format('n') - 1] . ' ' . $a->format('j');
            if ($d2 !== '') {
                $b = new \DateTimeImmutable($d2);
                $when .= ' – ' . ($a->format('n') === $b->format('n') ? '' : $M[(int) $b->format('n') - 1] . ' ') . $b->format('j');
            }
            $rows[] = ['title' => $ev->title, 'url' => self::url($ev), 'day' => $a->format('j'), 'when' => $when, 'place' => self::placeLine($ev)];
        }
        return $rows;
    }

    public static function homeListUrl(): string
    {
        return self::listUrl();
    }
}
