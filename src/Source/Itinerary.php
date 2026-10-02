<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Pastoral itinerary: the public page ("Pastoral itinerary" builder element) and the
 * Romanian form where a hierarch adds, edits and removes his visits ("Itinerary manager").
 *
 * Every visit is an "All languages" article in the hierarch's sub-category under Itinerary (alias "itinerary").
 * Fields: itinerary-date, itinerary-end, itinerary-time, itinerary-title-ro/en/es (service or event),
 * itinerary-occasion-ro/en/es (feast), itinerary-type, itinerary-parish, itinerary-place, itinerary-city.
 * The form fills the three languages from the lists below; free text is kept as written.
 *
 * Access to the form follows Joomla permissions on each hierarch's category:
 * add = core.create, edit = core.edit (or core.edit.own on one's own visits), remove = core.edit.state (or own).
 * Removing a visit moves it to the trash.
 */
final class Itinerary
{
    private const ROOT_ALIAS = 'itinerary';
    private const PARISH_CAT = 124;

    /** Services: key => [ro, en, es] */
    public const SVC = [
        'liturgy'      => ['Sfânta Liturghie', 'Divine Liturgy', 'Divina Liturgia'],
        'hliturgy'     => ['Sfânta Liturghie arhierească', 'Hierarchal Divine Liturgy', 'Divina Liturgia pontifical'],
        'vespers'      => ['Vecernie', 'Vespers', 'Vísperas'],
        'vigil'        => ['Priveghere', 'Vigil', 'Vigilia'],
        'tedeum'       => ['Te Deum', 'Te Deum', 'Te Deum'],
        'consecration' => ['Sfințirea bisericii', 'Consecration of the church', 'Consagración de la iglesia'],
        'unction'      => ['Sfântul Maslu', 'Holy Unction', 'Santa Unción'],
        'memorial'     => ['Parastas', 'Memorial service', 'Oficio de difuntos'],
        'ordination'   => ['Hirotonie', 'Ordination', 'Ordenación'],
        'visit'        => ['Vizită pastorală', 'Pastoral visit', 'Visita pastoral'],
        'congress'     => ['Congres', 'Congress', 'Congreso'],
        'conference'   => ['Conferință clericală', 'Clergy conference', 'Conferencia del clero'],
    ];

    /** Visit types: key => [ro, en, es, css class] */
    public const TYP = [
        'patronal'     => ['Hram', 'Patronal Feast', 'Fiesta patronal', 'pf'],
        'canonical'    => ['Vizită canonică', 'Canonical Visitation', 'Visita canónica', 'cv'],
        'pastoral'     => ['Vizită pastorală', 'Pastoral Visit', 'Visita pastoral', ''],
        'consecration' => ['Sfințire de biserică', 'Church Consecration', 'Consagración de iglesia', ''],
        'ordination'   => ['Hirotonie', 'Ordination', 'Ordenación', ''],
        'congress'     => ['Congres', 'Congress', 'Congreso', ''],
        'conference'   => ['Conferință', 'Conference', 'Conferencia', ''],
    ];

    /** Fixed feasts: mm-dd => [ro, en, es] */
    public const FIX = [
        '01-01' => ['Tăierea împrejur a Domnului; Sf. Vasile cel Mare', 'Circumcision of the Lord; St. Basil the Great', 'Circuncisión del Señor; San Basilio el Grande'],
        '01-06' => ['Botezul Domnului', 'Theophany', 'Teofanía'],
        '01-07' => ['Soborul Sf. Ioan Botezătorul', 'Synaxis of St. John the Baptist', 'Sinaxis de San Juan Bautista'],
        '01-30' => ['Sfinții Trei Ierarhi', 'The Three Holy Hierarchs', 'Los Tres Santos Jerarcas'],
        '02-02' => ['Întâmpinarea Domnului', 'Presentation of the Lord', 'Presentación del Señor'],
        '03-25' => ['Buna Vestire', 'The Annunciation', 'La Anunciación'],
        '04-23' => ['Sf. Mare Mucenic Gheorghe', 'St. George the Great Martyr', 'San Jorge, Gran Mártir'],
        '05-21' => ['Sfinții Împărați Constantin și Elena', 'Ss. Constantine and Helen', 'Santos Constantino y Elena'],
        '06-24' => ['Nașterea Sf. Ioan Botezătorul', 'Nativity of St. John the Baptist', 'Natividad de San Juan Bautista'],
        '06-29' => ['Sfinții Apostoli Petru și Pavel', 'Ss. Peter and Paul', 'Santos Pedro y Pablo'],
        '07-20' => ['Sf. Proroc Ilie', 'St. Elijah the Prophet', 'San Elías, Profeta'],
        '08-06' => ['Schimbarea la Față', 'Transfiguration of the Lord', 'Transfiguración del Señor'],
        '08-15' => ['Adormirea Maicii Domnului', 'Dormition of the Mother of God', 'Dormición de la Madre de Dios'],
        '08-29' => ['Tăierea capului Sf. Ioan Botezătorul', 'Beheading of St. John the Baptist', 'Degollación de San Juan Bautista'],
        '09-01' => ['Începutul anului bisericesc', 'Church New Year', 'Año Nuevo eclesiástico'],
        '09-08' => ['Nașterea Maicii Domnului', 'Nativity of the Mother of God', 'Natividad de la Madre de Dios'],
        '09-14' => ['Înălțarea Sfintei Cruci', 'Exaltation of the Holy Cross', 'Exaltación de la Santa Cruz'],
        '10-01' => ['Acoperământul Maicii Domnului', 'Protection of the Mother of God', 'Protección de la Madre de Dios'],
        '10-14' => ['Sf. Cuv. Parascheva', 'St. Parascheva', 'Santa Parascheva'],
        '10-26' => ['Sf. Mare Mucenic Dimitrie', 'St. Demetrius the Great Martyr', 'San Demetrio, Gran Mártir'],
        '10-27' => ['Sf. Cuv. Dimitrie cel Nou', 'St. Demetrius the New', 'San Demetrio el Nuevo'],
        '11-08' => ['Soborul Sfinților Arhangheli Mihail și Gavriil', 'Synaxis of the Archangels Michael and Gabriel', 'Sinaxis de los Arcángeles Miguel y Gabriel'],
        '11-21' => ['Intrarea în Biserică a Maicii Domnului', 'Entry of the Mother of God into the Temple', 'Presentación de la Madre de Dios en el Templo'],
        '11-30' => ['Sf. Apostol Andrei', 'St. Andrew the Apostle', 'San Andrés Apóstol'],
        '12-06' => ['Sf. Ierarh Nicolae', 'St. Nicholas', 'San Nicolás'],
        '12-25' => ['Nașterea Domnului', 'Nativity of the Lord', 'Natividad del Señor'],
        '12-27' => ['Sf. Arhidiacon Ștefan', 'St. Stephen the Archdeacon', 'San Esteban, Archidiácono'],
    ];

    /** Movable feasts: key => [days from Pascha, ro, en, es] */
    public const MOV = [
        'palm'      => [-7, 'Intrarea Domnului în Ierusalim (Florii)', 'Entry of the Lord into Jerusalem', 'Entrada del Señor en Jerusalén'],
        'pascha'    => [0, 'Învierea Domnului (Sfintele Paști)', 'Pascha, the Resurrection of the Lord', 'Pascua, la Resurrección del Señor'],
        'ascension' => [39, 'Înălțarea Domnului', 'Ascension of the Lord', 'Ascensión del Señor'],
        'pentecost' => [49, 'Pogorârea Sfântului Duh (Rusaliile)', 'Pentecost', 'Pentecostés'],
        'trinity'   => [50, 'Sfânta Treime', 'Holy Trinity', 'Santísima Trinidad'],
    ];

    /** Hierarch by category alias: [ro, en, es, ro genitive] */
    private const WHO = [
        'metropolitan-nicolae' => ['Mitropolitul Nicolae', 'His Eminence Metropolitan Nicolae', 'Su Eminencia el Metropolita Nicolae', 'Înaltpreasfințitului Mitropolit Nicolae'],
        'bishop-ioan-casian'   => ['Episcopul Ioan Casian', 'His Grace Bishop Ioan Casian', 'Su Excelencia el Obispo Ioan Casian', 'Preasfințitului Episcop Ioan Casian'],
    ];

    /** Public page wording: key => [ro, en, es] */
    private const UI = [
        'title'    => ['Itinerar pastoral', 'Pastoral Itinerary', 'Itinerario pastoral'],
        'intro'    => ['Slujirile și vizitele pastorale ale %s. Datele se pot schimba.', 'Where %s serves and visits. Dates may change.', 'Dónde sirve y visita %s. Las fechas pueden cambiar.'],
        'upcoming' => ['Vizite arhierești viitoare', 'Upcoming Hierarchical Visits', 'Próximas visitas jerárquicas'],
        'past'     => ['Vizite arhierești trecute', 'Past Hierarchical Visits', 'Visitas jerárquicas anteriores'],
        'none_up'  => ['Nu sunt vizite anunțate deocamdată.', 'No visits announced yet.', 'Aún no hay visitas anunciadas.'],
        'none_past' => ['Nicio vizită înregistrată în acest an.', 'No visits recorded for this year.', 'No hay visitas registradas este año.'],
        'year'     => ['Anul', 'Year', 'Año'],
        'print'    => ['Tipărește', 'Print', 'Imprimir'],
        'services' => ['slujbe', 'services', 'servicios'],
        'service'  => ['slujbă', 'service', 'servicio'],
        'places'   => ['biserici și mănăstiri', 'churches and monasteries', 'iglesias y monasterios'],
        'place'    => ['biserică sau mănăstire', 'church or monastery', 'iglesia o monasterio'],
        'countries' => ['țări', 'countries', 'países'],
        'country'  => ['țară', 'country', 'país'],
        'feasts'   => ['praznice', 'feast days', 'fiestas'],
        'feast'    => ['praznic', 'feast day', 'fiesta'],
        'show'     => ['Arată', 'Show', 'Mostrar'],
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

    private const FIELDS = ['itinerary-date', 'itinerary-end', 'itinerary-time', 'itinerary-title-ro', 'itinerary-title-en', 'itinerary-title-es',
        'itinerary-occasion-ro', 'itinerary-occasion-en', 'itinerary-occasion-es', 'itinerary-type', 'itinerary-parish', 'itinerary-place', 'itinerary-city'];

    private const I_PIN = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
    private const I_PRINT = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>';

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

    private static function li(string $l): int
    {
        return ['ro' => 0, 'en' => 1, 'es' => 2][$l] ?? 1;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function ui(string $key, string $l): string
    {
        return self::UI[$key][self::li($l)] ?? $key;
    }

    private static function root(): ?object
    {
        static $r = false;
        if ($r === false) {
            $db = self::db();
            $r = $db->setQuery($db->getQuery(true)->select(['id', 'lft', 'rgt'])->from('#__categories')
                ->where('alias = ' . $db->quote(self::ROOT_ALIAS))->where('extension = ' . $db->quote('com_content'))
                ->where('published = 1'), 0, 1)->loadObject() ?: null;
        }
        return $r;
    }

    /** Hierarch categories under Itinerary: id => row (id, title, alias). */
    private static function hierarchCats(): array
    {
        $root = self::root();
        if (!$root) {
            return [];
        }
        $db = self::db();
        $out = [];
        foreach ($db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias'])->from('#__categories')
            ->where('parent_id = ' . (int) $root->id)->where('published = 1')->where('extension = ' . $db->quote('com_content'))
            ->order('lft'))->loadObjectList() as $c) {
            $out[(int) $c->id] = $c;
        }
        return $out;
    }

    private static function who(object $cat, int $i): string
    {
        return self::WHO[(string) $cat->alias][$i] ?? (string) $cat->title;
    }

    private static function fieldIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $db = self::db();
            $ids = [];
            foreach ($db->setQuery($db->getQuery(true)->select(['id', 'name'])->from('#__fields')
                ->where('context = ' . $db->quote('com_content.article'))
                ->whereIn('name', self::FIELDS, ParameterType::STRING))->loadObjectList() as $f) {
                $ids[(string) $f->name] = (int) $f->id;
            }
        }
        return $ids;
    }

    private static function fieldValues(array $ids, array $names): array
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

    /** Orthodox Pascha (Gregorian date) for a year. */
    public static function pascha(int $y): \DateTimeImmutable
    {
        $a = $y % 4;
        $b = $y % 7;
        $c = $y % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $m = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;
        return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day)))->modify('+13 days');
    }

    /* ------------------------------------------------------------------ data */

    /**
     * Visits of the given categories, oldest first.
     * $manage: also unpublished items, no access-level filter, and the permissions of the current user.
     */
    private static function visits(array $cats, bool $manage = false): array
    {
        if (!$cats) {
            return [];
        }
        $db = self::db();
        $q = $db->getQuery(true)->select(['a.id', 'a.catid', 'a.created_by', 'a.state'])->from($db->quoteName('#__content', 'a'))
            ->whereIn('a.catid', array_map('intval', $cats));
        if ($manage) {
            $q->whereIn('a.state', [0, 1]);
        } else {
            $now = Factory::getDate()->toSql();
            $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels());
            $q->where('a.state = 1')
                ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
                ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
                ->whereIn('a.access', $levels ?: [1]);
        }
        $rows = $db->setQuery($q)->loadObjectList();
        if (!$rows) {
            return [];
        }
        $f = self::fieldValues(array_map(fn ($r) => (int) $r->id, $rows), self::FIELDS);
        $user = Factory::getApplication()->getIdentity();
        $out = [];
        foreach ($rows as $r) {
            $v = $f[(int) $r->id] ?? [];
            $d1 = substr((string) ($v['itinerary-date'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
                continue;
            }
            $d2 = substr((string) ($v['itinerary-end'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2) || $d2 <= $d1) {
                $d2 = '';
            }
            $tm = (string) ($v['itinerary-time'] ?? '');
            $row = [
                'id'     => (int) $r->id,
                'cat'    => (int) $r->catid,
                'd1'     => $d1,
                'd2'     => $d2,
                'tm'     => preg_match('/^\d{1,2}:\d{2}$/', $tm) ? $tm : '',
                't'      => [(string) ($v['itinerary-title-ro'] ?? ''), (string) ($v['itinerary-title-en'] ?? ''), (string) ($v['itinerary-title-es'] ?? '')],
                'f'      => [(string) ($v['itinerary-occasion-ro'] ?? ''), (string) ($v['itinerary-occasion-en'] ?? ''), (string) ($v['itinerary-occasion-es'] ?? '')],
                'type'   => isset(self::TYP[$v['itinerary-type'] ?? '']) ? (string) $v['itinerary-type'] : '',
                'parish' => (int) ($v['itinerary-parish'] ?? 0),
                'place'  => (string) ($v['itinerary-place'] ?? ''),
                'city'   => (string) ($v['itinerary-city'] ?? ''),
            ];
            if ($manage) {
                $own = (int) $r->created_by === (int) $user->id;
                $asset = 'com_content.article.' . (int) $r->id;
                $row['edit'] = $user->authorise('core.edit', $asset) || ($own && $user->authorise('core.edit.own', $asset));
                $row['del'] = $user->authorise('core.edit.state', $asset) || ($own && $user->authorise('core.edit.own', $asset));
            }
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => strcmp($a['d1'], $b['d1']) ?: $a['id'] <=> $b['id']);
        return $out;
    }

    /** Parishes: id => [ro, en, es, city, state, country, url]. */
    private static function parishes(array $only = []): array
    {
        $db = self::db();
        $q = $db->getQuery(true)->select(['id', 'title', 'alias', 'catid'])->from('#__content')
            ->where('catid = ' . self::PARISH_CAT)->where('state = 1');
        if ($only) {
            $q->whereIn('id', array_map('intval', $only));
        }
        $rows = $db->setQuery($q)->loadObjectList();
        $f = self::fieldValues(array_map(fn ($r) => (int) $r->id, $rows),
            ['parish-name-ro', 'parish-name-en', 'parish-name-es', 'parish-city', 'parish-state', 'parish-country']);
        $out = [];
        foreach ($rows as $r) {
            $v = $f[(int) $r->id] ?? [];
            $ro = ($v['parish-name-ro'] ?? '') ?: (string) $r->title;
            $out[(int) $r->id] = [
                $ro, ($v['parish-name-en'] ?? '') ?: $ro, ($v['parish-name-es'] ?? '') ?: $ro,
                (string) ($v['parish-city'] ?? ''), (string) ($v['parish-state'] ?? ''), (string) ($v['parish-country'] ?? '') ?: 'USA',
                (int) $r->id . ':' . (string) $r->alias,
            ];
        }
        uasort($out, fn ($a, $b) => strcoll($a[0], $b[0]));
        return $out;
    }

    /* ------------------------------------------------------------------ public page */

    public static function listing(array $props): string
    {
        try {
            $in = Factory::getApplication()->getInput();
            $cat = (int) ($props['category_id'] ?? 0);
            if (!$cat && $in->get('option') === 'com_content' && $in->get('view') === 'category') {
                $cat = $in->getInt('id');
            }
            $cats = self::hierarchCats();
            if (!isset($cats[$cat])) {
                return '';
            }
            return self::publicHtml($cats[$cat], $props) . self::css();
        } catch (\Throwable $x) {
            Log::add('Itinerary: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function listingText(array $props): string
    {
        return '';
    }

    private static function placeOf(array $v, array $par, string $l, bool $link = true): array
    {
        if ($v['parish'] && isset($par[$v['parish']])) {
            $p = $par[$v['parish']];
            $name = $p[self::li($l)];
            $city = implode(', ', array_filter([$p[3], $p[4], $p[5] !== 'USA' ? $p[5] : '']));
            [$id, $alias] = explode(':', $p[6], 2);
            $url = Route::_(RouteHelper::getArticleRoute($id . ':' . $alias, self::PARISH_CAT));
            return [
                'html'    => ($link ? '<a href="' . self::e($url) . '">' . self::e($name) . '</a>' : self::e($name)) . ($city !== '' ? ', ' . self::e($city) : ''),
                'key'     => 'p' . $v['parish'],
                'country' => $p[5] ?: 'USA',
            ];
        }
        $html = self::e($v['place']) . ($v['city'] !== '' ? ($v['place'] !== '' ? ', ' : '') . self::e($v['city']) : '');
        $parts = array_map('trim', explode(',', $v['city']));
        $last = end($parts) ?: '';
        $country = ($last === '' || preg_match('/^[A-Z]{2}$/', $last)) ? 'USA' : $last;
        return ['html' => $html, 'key' => 'x' . mb_strtolower($v['place'] . '|' . $v['city']), 'country' => $country];
    }

    private static function text(array $arr, string $l): string
    {
        $i = self::li($l);
        return trim($arr[$i] ?? '') !== '' ? trim($arr[$i]) : trim($arr[0] ?? '');
    }

    private static function dateBox(array $v, string $l): string
    {
        $a = new \DateTimeImmutable($v['d1']);
        $M = self::MON[$l];
        if ($v['d2'] !== '') {
            $b = new \DateTimeImmutable($v['d2']);
            $same = $a->format('n') === $b->format('n');
            $mon = $same ? $M[(int) $a->format('n') - 1] : $M[(int) $a->format('n') - 1] . '–' . $M[(int) $b->format('n') - 1];
            return '<div class="db rng"><div class="d">' . $a->format('j') . '–' . $b->format('j') . '</div><div class="w">' . self::e($mon) . '</div><div class="y">' . $b->format('Y') . '</div></div>';
        }
        return '<div class="db"><div class="d">' . $a->format('j') . '</div><div class="w">' . self::e($M[(int) $a->format('n') - 1]) . '</div><div class="y">' . $a->format('Y') . '</div></div>';
    }

    private static function time(string $t, string $l): string
    {
        if ($t === '') {
            return '';
        }
        if ($l !== 'en') {
            return $t;
        }
        [$h, $m] = array_map('intval', explode(':', $t));
        return sprintf('%d:%02d %s', $h % 12 ?: 12, $m, $h >= 12 ? 'PM' : 'AM');
    }

    private static function row(array $v, array $par, string $l, bool $up): string
    {
        $t = self::text($v['t'], $l);
        $fe = self::text($v['f'], $l);
        $p = self::placeOf($v, $par, $l);
        $tag = '';
        if ($v['type'] !== '') {
            $ty = self::TYP[$v['type']];
            $tag = '<span class="tag' . ($ty[3] !== '' ? ' ' . $ty[3] : '') . '">' . self::e($ty[self::li($l)]) . '</span>';
        }
        $tm = $up ? self::time($v['tm'], $l) : '';
        return '<div class="srow' . ($up ? ' up' : '') . '">' . self::dateBox($v, $l) . '<div>'
            . ($fe !== '' ? '<div class="fe">' . self::e($fe) . '</div>' : '')
            . '<h3>' . self::e($t) . $tag . '</h3>'
            . ($p['html'] !== '' ? '<p>' . self::I_PIN . '<span>' . $p['html'] . '</span></p>' : '')
            . '</div>' . ($tm !== '' ? '<span class="tm">' . self::e($tm) . '</span>' : '<span></span>') . '</div>';
    }

    private static function publicHtml(object $cat, array $props): string
    {
        $l = self::lang();
        $all = self::visits([(int) $cat->id]);
        $par = self::parishes(array_values(array_unique(array_filter(array_map(fn ($v) => $v['parish'], $all)))));
        $today = Factory::getDate('now', Factory::getApplication()->get('offset', 'UTC'))->format('Y-m-d', true);
        $up = $past = [];
        foreach ($all as $v) {
            if (($v['d2'] ?: $v['d1']) >= $today) {
                $up[] = $v;
            } else {
                $past[] = $v;
            }
        }
        $thisYear = (int) substr($today, 0, 4);
        $years = [$thisYear => true];
        foreach ($past as $v) {
            $years[(int) substr($v['d1'], 0, 4)] = true;
        }
        krsort($years);
        $ySel = Factory::getApplication()->getInput()->getInt('an');
        if (!isset($years[$ySel])) {
            $ySel = $thisYear;
        }
        $pastY = array_reverse(array_values(array_filter($past, fn ($v) => (int) substr($v['d1'], 0, 4) === $ySel)));

        $title = !empty($props['title']) ? (string) $props['title'] : self::ui('title', $l);
        $whoName = $l === 'ro' ? self::who($cat, 3) : self::who($cat, self::li($l));
        $intro = !empty($props['intro']) ? (string) $props['intro'] : sprintf(self::ui('intro', $l), $whoName);

        $h = '<div class="mit">';
        $h .= '<div class="mit-hero"><div class="mit-hero-in"><div class="mit-hero-row"><div class="mit-hero-text"><h1 class="mit-h1">' . self::e($title) . '</h1>'
            . '<p class="mit-intro">' . self::e($intro) . '</p></div>'
            . '<button type="button" class="mit-btn" onclick="window.print()">' . self::I_PRINT . ' ' . self::e(self::ui('print', $l)) . '</button></div></div></div>';

        $h .= '<div class="mit-body"><h2 class="mit-sec">' . self::e(self::ui('upcoming', $l)) . '</h2>';
        if ($up) {
            $h .= '<div class="mit-list">' . implode('', array_map(fn ($v) => self::row($v, $par, $l, true), $up)) . '</div>';
        } else {
            $h .= '<p class="mit-empty">' . self::e(self::ui('none_up', $l)) . '</p>';
        }

        $opts = '';
        foreach (array_keys($years) as $y) {
            $opts .= '<option value="' . (int) $y . '"' . ($y === $ySel ? ' selected' : '') . '>' . (int) $y . '</option>';
        }
        $base = Uri::getInstance();
        $action = self::e($base->toString(['path']));
        $h .= '<div class="mit-past-head"><h2 class="mit-h2" id="mit-past">' . self::e(self::ui('past', $l)) . '</h2>'
            . '<form method="get" action="' . $action . '#mit-past"><select class="mit-sel" name="an" aria-label="' . self::e(self::ui('year', $l)) . '" onchange="this.form.submit()">' . $opts . '</select>'
            . '<noscript><button class="mit-btn" type="submit">' . self::e(self::ui('show', $l)) . '</button></noscript></form></div>';

        // stats for the year
        $places = $countries = [];
        $feasts = 0;
        foreach ($pastY as $v) {
            $p = self::placeOf($v, $par, $l, false);
            $places[$p['key']] = true;
            $countries[$p['country']] = true;
            if (self::text($v['f'], $l) !== '') {
                $feasts++;
            }
        }
        $stat = function (int $n, string $one, string $many) use ($l): string {
            return '<div><b>' . $n . '</b><span>' . self::e(self::ui($n === 1 ? $one : $many, $l)) . '</span></div>';
        };
        $h .= '<div class="mit-stats">' . $stat(count($pastY), 'service', 'services') . $stat(count($places), 'place', 'places')
            . $stat(count($countries), 'country', 'countries') . $stat($feasts, 'feast', 'feasts') . '</div>';

        if ($pastY) {
            $month = '';
            $list = '';
            foreach ($pastY as $v) {
                $mk = substr($v['d1'], 0, 7);
                if ($mk !== $month) {
                    $list .= ($month !== '' ? '</div>' : '') . '<h3 class="mit-month">' . self::e(self::mcap(self::MONTH[$l][(int) substr($mk, 5, 2) - 1], $l)) . ' ' . substr($mk, 0, 4) . '</h3><div class="mit-list">';
                    $month = $mk;
                }
                $list .= self::row($v, $par, $l, false);
            }
            $h .= $list . '</div>';
        } else {
            $h .= '<p class="mit-empty">' . self::e(self::ui('none_past', $l)) . '</p>';
        }
        return $h . '</div></div>';
    }

    private static function mcap(string $s, string $l): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
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
/* Pastoral itinerary (Mitropolia plugin) */
.mit{--mi-navy:#172E5C;--mi-blue:#203D78;--mi-red:#A32D36;--mi-ink:#1B2A4A;--mi-muted:#5A6378;--mi-soft:#6B6F7B;--mi-line:#E4D8BE;--mi-line2:#D9CBAA;--mi-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:var(--mi-ink)}
.mit a{text-decoration:none}
.mit-hero{position:relative;background:var(--mi-ivory);box-shadow:0 0 0 100vmax var(--mi-ivory);clip-path:inset(0 -100vmax);border-bottom:1px solid var(--mi-line)}
.mit-hero-in{padding:34px 0 44px}
.mit-hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:32px;flex-wrap:wrap}
.mit-hero-text{max-width:700px}
.mit-h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:56px;line-height:1.05;margin:0 0 14px;color:var(--mi-navy)}
.mit-intro{font-family:'Source Serif 4',Georgia,serif;font-size:20px;line-height:1.55;color:#3C4A66;margin:0}
.mit-btn{display:inline-flex;align-items:center;gap:8px;height:46px;border:1px solid var(--mi-blue);border-radius:4px;padding:0 18px;font:inherit;font-weight:600;font-size:16px;background:#fff;color:var(--mi-blue);cursor:pointer}
.mit-body{max-width:1080px;margin:0 auto;padding:44px 0 88px}
.mit-sec{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:26px;color:var(--mi-navy);margin:0 0 14px}
.mit-h2{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:38px;color:var(--mi-navy);margin:0}
.mit-past-head{display:flex;justify-content:space-between;align-items:baseline;gap:16px;flex-wrap:wrap;margin:60px 0 18px}
.mit-sel{height:44px;border:1px solid var(--mi-line2);border-radius:4px;padding:0 14px;font:inherit;font-size:16px;background:#fff;color:var(--mi-blue)}
.mit-month{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:26px;color:var(--mi-navy);margin:36px 0 12px;display:flex;align-items:center;gap:16px}
.mit-month::after{content:"";flex:1;height:1px;background:var(--mi-line)}
.mit-list{display:flex;flex-direction:column;gap:10px}
.mit-empty{color:var(--mi-soft);font-size:17px;background:#fff;border:1px dashed var(--mi-line2);border-radius:6px;padding:22px;text-align:center;margin:0}
.mit-stats+.mit-empty,.mit-stats+.mit-month{margin-top:28px}
.mit-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:var(--mi-line);border:1px solid var(--mi-line);border-radius:6px;overflow:hidden}
.mit-stats div{background:#fff;padding:18px 20px;text-align:center}
.mit-stats b{display:block;font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:34px;color:var(--mi-navy);line-height:1.1}
.mit-stats span{font-size:14px;color:var(--mi-soft)}
.mit .srow{display:grid;grid-template-columns:84px minmax(0,1fr) 150px;gap:22px;align-items:center;background:#fff;border:1px solid var(--mi-line);border-radius:6px;padding:14px 20px;min-height:98px;box-sizing:border-box}
.mit .srow.up{border-color:var(--mi-blue);border-left:4px solid var(--mi-blue)}
.mit .db{border:1px solid var(--mi-line);border-radius:5px;text-align:center;padding:10px 0 9px;display:flex;flex-direction:column;justify-content:center;min-height:76px}
.mit .db .d{font-family:'Baskervville',Georgia,serif;font-size:28px;color:var(--mi-navy);line-height:1}
.mit .db.rng .d{font-size:20px;white-space:nowrap}
.mit .db .w{margin-top:8px;padding:0 4px;font-size:14px;font-weight:700;color:var(--mi-red);letter-spacing:.1em;line-height:1.2;white-space:nowrap;text-transform:uppercase}
.mit .db.rng .w{font-size:12px;letter-spacing:.04em}
.mit .db .y{padding-top:2px;font-size:14px;font-weight:600;color:#3C4A66;letter-spacing:.04em;line-height:1.2}
.mit .srow .fe{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mi-red)}
.mit .srow h3{margin:2px 0 3px;font-size:18px;font-weight:600;color:var(--mi-navy);line-height:1.3;font-family:'Source Sans 3',sans-serif}
.mit .srow p{margin:0;font-size:15px;color:var(--mi-muted);display:flex;gap:6px;align-items:center}
.mit .srow p svg{flex:none}
.mit .srow p span{min-width:0}
.mit .srow p a{color:var(--mi-blue);text-decoration:underline;text-decoration-color:rgba(32,61,120,.3)}
.mit .srow .tm{justify-self:end;font-weight:600;font-size:15px;color:var(--mi-blue);white-space:nowrap}
.mit .tag{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mi-blue);background:#EEF2F9;border:1px solid #C9D3E6;border-radius:3px;padding:1px 6px;margin-left:8px;vertical-align:middle;white-space:nowrap;font-family:'Source Sans 3',sans-serif}
.mit .tag.pf{color:#8A6D1E;background:#FBF1D6;border-color:#ECD9A0}
.mit .tag.cv{color:var(--mi-red);background:#FBEDEE;border-color:#EBC5C8}
@media (max-width:760px){.mit-h1{font-size:40px}.mit-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.mit .srow{grid-template-columns:70px minmax(0,1fr);gap:14px}.mit .srow>span:empty{display:none}.mit .srow .tm{grid-column:2;justify-self:start}.mit-past-head form,.mit-sel{width:100%}}
@media print{.mit-btn,.mit-past-head form{display:none}.mit-hero{box-shadow:none;clip-path:none;background:none}.mit .srow{break-inside:avoid}}
</style>
HTML;
    }

    /* ------------------------------------------------------------------ manager (Romanian form) */

    /** Hierarch categories the current user may add visits to. */
    public static function allowedCats(): array
    {
        $user = Factory::getApplication()->getIdentity();
        if (!$user || $user->guest) {
            return [];
        }
        return array_filter(self::hierarchCats(), fn ($c) => $user->authorise('core.create', 'com_content.category.' . (int) $c->id));
    }

    public static function manage(array $props): string
    {
        return Admin::page($props);
    }

    /** The itinerary-only manager (kept for reference; the menu uses the combined page). */
    public static function manageItinerary(array $props): string
    {
        try {
            $app = Factory::getApplication();
            $user = $app->getIdentity();
            $here = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);
            if (!$user || $user->guest) {
                return self::loginHtml($here) . self::formCss();
            }
            $cats = self::allowedCats();
            if (!$cats) {
                return '<div class="mif"><div class="mif-card mif-narrow"><h2>Itinerar pastoral</h2><p>Contul dumneavoastră nu are acces la itinerar. Vă rugăm să luați legătura cu administratorul site-ului.</p>'
                    . self::logoutForm($here) . '</div></div>' . self::formCss();
            }
            return self::formHtml($cats, $user, $here) . self::formCss();
        } catch (\Throwable $x) {
            Log::add('Itinerary manager: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function manageText(array $props): string
    {
        return '';
    }

    private static function loginHtml(string $here): string
    {
        $action = Route::_('index.php?option=com_users&task=user.login');
        return '<div class="mif"><form class="mif-card mif-narrow" method="post" action="' . self::e($action) . '">'
            . '<h2>Itinerar pastoral</h2><p class="mif-hint">Intrați în cont pentru a adăuga sau modifica vizitele.</p>'
            . '<div class="f"><label for="mif-u">Utilizator</label><input type="text" id="mif-u" name="username" autocomplete="username" required></div>'
            . '<div class="f"><label for="mif-p">Parolă</label><input type="password" id="mif-p" name="password" autocomplete="current-password" required></div>'
            . '<input type="hidden" name="return" value="' . self::e(base64_encode($here)) . '">'
            . '<input type="hidden" name="' . Session::getFormToken() . '" value="1">'
            . '<button class="btn" type="submit">Intră în cont</button></form></div>';
    }

    private static function logoutForm(string $here): string
    {
        $action = Route::_('index.php?option=com_users&task=user.logout');
        return '<form method="post" action="' . self::e($action) . '" class="mif-out">'
            . '<input type="hidden" name="return" value="' . self::e(base64_encode($here)) . '">'
            . '<input type="hidden" name="' . Session::getFormToken() . '" value="1">'
            . '<button type="submit">Ieșire</button></form>';
    }

    /** The form and list; $hero = false when embedded in the combined Administrare page. */
    public static function formHtml(array $cats, $user, string $here, bool $hero = true): string
    {
        $par = self::parishes();
        $data = [
            'api'      => Uri::getInstance()->toString(['scheme', 'host', 'port', 'path']) . '?mitit=api',
            'token'    => Session::getFormToken(),
            'today'    => Factory::getDate('now', Factory::getApplication()->get('offset', 'UTC'))->format('Y-m-d', true),
            'cats'     => array_values(array_map(fn ($c) => ['id' => (int) $c->id, 'name' => self::who($c, 0)], $cats)),
            'parishes' => array_values(array_map(fn ($id, $p) => [$id, $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]], array_keys($par), $par)),
            'svc'      => array_map(fn ($k, $v) => [$k, $v[0], $v[1], $v[2]], array_keys(self::SVC), self::SVC),
            'typ'      => array_map(fn ($k, $v) => [$k, $v[0], $v[1], $v[2], $v[3]], array_keys(self::TYP), self::TYP),
            'fix'      => self::FIX,
            'mov'      => array_map(fn ($k, $v) => [$k, $v[0], $v[1], $v[2], $v[3]], array_keys(self::MOV), self::MOV),
            'visits'   => self::visits(array_keys($cats), true),
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $catSel = count($cats) > 1
            ? '<div class="f" style="margin-bottom:22px"><label for="mif-cat">Itinerarul</label><select id="mif-cat">'
              . implode('', array_map(fn ($c) => '<option value="' . (int) $c->id . '">' . self::e(self::who($c, 0)) . '</option>', $cats)) . '</select></div>'
            : '';
        return '<div class="mif">'
            . ($hero ? '<div class="mif-hero"><div class="mif-hero-in"><div><h1>Adăugați o vizită</h1><p>Vizita apare imediat pe site, în română, engleză și spaniolă.</p></div>'
            . '<div class="mif-user"><span>Conectat: <b>' . self::e((string) $user->name) . '</b></span>' . self::logoutForm($here) . '</div></div></div>' : '')
            . '<div class="mif-wrap">'
            . '<form class="mif-card" id="mif-frm" novalidate>' . $catSel . <<<'HTML'
 <div class="ok" id="okmsg" hidden></div>
 <h2 id="ftitle">Vizită nouă</h2>
 <div class="row">
  <div class="f"><label for="d1">Data</label><input type="date" id="d1" required></div>
  <div class="f"><label for="tm">Ora <span class="opt">(opțional)</span></label><input type="time" id="tm"></div>
 </div>
 <label class="check"><input type="checkbox" id="multi"> Durează mai multe zile</label>
 <div class="f" id="d2wrap" hidden><label for="d2">Până la data de</label><input type="date" id="d2"></div>
 <div class="f"><label for="svc">Slujba sau evenimentul</label><select id="svc"></select>
  <input type="text" id="svcx" maxlength="200" placeholder="Scrieți evenimentul, de ex. Vizită în parohiile din Florida" hidden></div>
 <div class="f"><span class="lab">Praznicul <span class="opt">(opțional)</span></span>
  <div class="suggest" id="fsug" hidden></div>
  <select id="fst" aria-label="Praznicul"></select>
  <input type="text" id="fstx" maxlength="200" placeholder="Scrieți praznicul" hidden></div>
 <div class="f"><span class="lab">Tipul vizitei <span class="opt">(opțional)</span></span><div class="chips" id="types"></div></div>
 <div class="f"><span class="lab">Locul</span>
  <div class="seg" role="group" aria-label="Tipul locului"><button type="button" id="mOurs" aria-pressed="true">Parohie a noastră</button><button type="button" id="mOther" aria-pressed="false">Alt loc</button></div>
  <div id="oursBox">
   <div class="ac" id="acBox"><input type="text" id="pq" placeholder="Scrieți numele parohiei sau orașul" autocomplete="off" aria-label="Caută parohia"><div class="sug" id="sug" hidden></div></div>
   <div class="picked" id="picked" hidden><div><b id="pkN"></b><span id="pkC"></span></div><button type="button" class="link" id="pkX">Schimbă</button></div>
  </div>
  <div id="otherBox" hidden><div class="row">
   <input type="text" id="oN" maxlength="200" placeholder="Biserica sau locul" aria-label="Biserica sau locul">
   <input type="text" id="oC" maxlength="200" placeholder="Orașul, statul sau țara" aria-label="Orașul, statul sau țara">
  </div></div>
 </div>
 <details class="tr" id="trBox" hidden>
  <summary>Traduceri <span class="opt">(opțional)</span></summary>
  <p class="hint" style="margin:8px 0 0">Textul scris de mână apare în română și pe paginile în engleză și spaniolă, dacă nu adăugați o traducere.</p>
  <div class="f"><label for="enT">English</label><input type="text" id="enT" maxlength="200"></div>
  <div class="f"><label for="esT">Español</label><input type="text" id="esT" maxlength="200"></div>
 </details>
 <div class="preview">
  <div class="pvh"><span class="lab">Cum va apărea pe site</span>
   <div class="ptabs" role="group" aria-label="Limba"><button type="button" data-l="ro" aria-pressed="true">RO</button><button type="button" data-l="en" aria-pressed="false">EN</button><button type="button" data-l="es" aria-pressed="false">ES</button></div></div>
  <div id="pv"></div><p class="note-pv" id="pvnote"></p>
 </div>
 <div class="actions"><button class="btn" type="submit" id="save">Salvează vizita</button><button class="btn sec" type="button" id="cancel" hidden>Renunță</button><span class="err" id="err" role="alert"></span></div>
</form>
<aside class="mif-card side">
 <h2>Vizitele mele</h2>
 <div class="mls"><input type="search" id="lq" placeholder="Căutați după slujbă sau loc" aria-label="Căutați după slujbă sau loc"><input type="date" id="ldt" aria-label="Data"><button type="button" class="link" id="lx" hidden>Șterge căutarea</button></div>
 <div id="tabsBox"><div class="seg" role="group" aria-label="Ce vizite"><button type="button" id="tUp" aria-pressed="true">Următoare</button><button type="button" id="tPast" aria-pressed="false">Trecute</button></div>
 <div id="yrBox" hidden style="margin-bottom:12px"><select id="yr" aria-label="Anul"></select></div></div>
 <p class="hint" id="lhint">Ștergerea face vizita să dispară de pe site.</p>
 <div class="list" id="list"></div>
</aside>
</div></div>
HTML
            . '<script type="application/json" id="mif-data">' . $json . '</script>' . self::formJs();
    }

    public static function formCss(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>
/* Itinerary manager (Mitropolia plugin) */
.mif{--navy:#172E5C;--blue:#203D78;--ink:#1B2A4A;--muted:#5A6378;--soft:#6B6F7B;--red:#A32D36;--gold:#8A6D1E;--sand:#EFE3CB;--line:#E4D8BE;--line2:#D9CBAA;font-family:'Source Sans 3',sans-serif;font-size:16px;line-height:1.45;color:var(--ink)}
.mif *{box-sizing:border-box}
.mif [hidden]{display:none!important}
.mif-hero{position:relative;background:var(--sand);box-shadow:0 0 0 100vmax var(--sand);clip-path:inset(0 -100vmax);border-bottom:1px solid var(--line)}
.mif-hero-in{padding:30px 0 26px;display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap}
.mif h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:40px;line-height:1.1;color:var(--navy);margin:0 0 8px}
.mif-hero p{margin:0;color:#3C4A66;font-size:18px}
.mif-user{display:flex;align-items:center;gap:12px;font-size:15px;color:var(--muted);flex-wrap:wrap}
.mif-user b{color:var(--navy)}
.mif-out{margin:0}
.mif-out button{border:1px solid var(--line2);background:#fff;border-radius:4px;padding:6px 12px;font:inherit;font-weight:600;color:var(--blue);cursor:pointer}
.mif-wrap{padding:28px 0 80px;display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:28px;align-items:start}
.mif-card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:26px 26px 24px}
.mif-narrow{max-width:460px;margin:40px auto 80px}
.mif h2{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:26px;color:var(--navy);margin:0 0 18px}
.mif .f{display:flex;flex-direction:column;gap:6px;margin-bottom:18px;min-width:0}
.mif .f>label,.mif .lab{font-weight:700;font-size:15px;color:var(--navy)}
.mif .opt{font-weight:400;color:var(--soft);font-size:14px}
.mif .hint,.mif-hint{font-size:14px;color:var(--soft)}
.mif input[type=text],.mif input[type=search],.mif input[type=email],.mif input[type=password],.mif input[type=date],.mif input[type=time],.mif select{width:100%;min-height:48px;border:1px solid var(--line2);border-radius:6px;padding:10px 12px;font:inherit;font-size:17px;color:var(--ink);background:#fff;margin:0}
.mif input[type=search]{-webkit-appearance:none;appearance:none}
.mif input:focus,.mif select:focus{outline:2px solid #9DB0D6;outline-offset:1px;border-color:var(--blue)}
.mif .row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.mif .check{display:flex;align-items:center;gap:10px;font-size:16px;cursor:pointer;margin:-6px 0 16px}
.mif .check input{width:20px;height:20px;accent-color:var(--blue)}
.mif .chips{display:flex;flex-wrap:wrap;gap:8px}
.mif .chip{border:1px solid var(--line2);background:#fff;border-radius:999px;padding:9px 15px;font:inherit;font-size:15px;color:var(--ink);cursor:pointer;min-height:42px}
.mif .chip[aria-pressed=true]{background:var(--blue);border-color:var(--blue);color:#fff}
.mif .seg{display:inline-flex;border:1px solid var(--line2);border-radius:6px;overflow:hidden;margin-bottom:10px}
.mif .seg button{border:0;background:#fff;font:inherit;font-size:15px;padding:9px 16px;color:var(--ink);cursor:pointer;min-height:42px}
.mif .seg button+button{border-left:1px solid var(--line2)}
.mif .seg button[aria-pressed=true]{background:var(--blue);color:#fff}
.mif .ac{position:relative}
.mif .sug{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--line2);border-radius:6px;box-shadow:0 8px 24px rgba(23,46,92,.12);z-index:5;max-height:300px;overflow:auto}
.mif .sug button{display:block;width:100%;text-align:left;border:0;background:none;padding:10px 14px;font:inherit;cursor:pointer;border-bottom:1px solid #F0E8D6}
.mif .sug button:hover,.mif .sug button.on{background:#EEF2F9}
.mif .sug b{display:block;color:var(--navy);font-weight:600}
.mif .sug span{font-size:14px;color:var(--soft)}
.mif .picked{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #C9D3E6;background:#EEF2F9;border-radius:6px;padding:10px 14px}
.mif .picked b{color:var(--navy)}
.mif .picked span{display:block;font-size:14px;color:var(--muted)}
.mif .link{border:0;background:none;color:var(--red);font:inherit;font-weight:600;cursor:pointer;padding:6px 0;white-space:nowrap}
.mif .suggest{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#FBF1D6;border:1px solid #ECD9A0;border-radius:6px;padding:9px 12px;font-size:15px;color:#5B4714}
.mif .suggest button{border:1px solid #D9BE74;background:#fff;border-radius:4px;font:inherit;font-size:14px;font-weight:600;color:var(--gold);padding:5px 10px;cursor:pointer}
.mif details.tr{border:1px dashed var(--line2);border-radius:6px;padding:10px 14px;margin-bottom:18px}
.mif details.tr summary{cursor:pointer;font-weight:600;color:var(--blue)}
.mif details.tr .f{margin:12px 0 0}
.mif .preview{border-top:1px solid var(--line);margin-top:6px;padding-top:18px}
.mif .pvh{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.mif .ptabs{display:flex;gap:6px;margin-bottom:10px}
.mif .ptabs button{border:1px solid var(--line2);background:#fff;border-radius:4px;font:inherit;font-size:13px;font-weight:700;letter-spacing:.08em;padding:5px 10px;color:var(--blue);cursor:pointer}
.mif .ptabs button[aria-pressed=true]{background:var(--navy);color:#fff;border-color:var(--navy)}
.mif .srow{display:grid;grid-template-columns:84px minmax(0,1fr) auto;gap:18px;align-items:center;background:#fff;border:1px solid var(--blue);border-left:4px solid var(--blue);border-radius:6px;padding:14px 18px}
.mif .db{border:1px solid var(--line);border-radius:5px;text-align:center;padding:10px 0 9px}
.mif .db .d{font-family:'Baskervville',Georgia,serif;font-size:28px;color:var(--navy);line-height:1}
.mif .db.rng .d{font-size:20px;white-space:nowrap}
.mif .db .w{margin-top:8px;font-size:14px;font-weight:700;color:var(--red);letter-spacing:.1em;line-height:1.2;text-transform:uppercase}
.mif .db.rng .w{font-size:12px;letter-spacing:.04em}
.mif .db .y{padding-top:2px;font-size:14px;font-weight:600;color:#3C4A66;line-height:1.2}
.mif .srow .fe{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--red)}
.mif .srow h3{margin:2px 0 3px;font-size:18px;font-weight:700;color:var(--navy);line-height:1.3;font-family:'Source Sans 3',sans-serif}
.mif .srow p{margin:0;font-size:15px;color:var(--muted)}
.mif .srow p a{color:var(--blue)}
.mif .srow .tm{font-weight:700;color:var(--blue);white-space:nowrap}
.mif .tag{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--blue);background:#EEF2F9;border:1px solid #C9D3E6;border-radius:3px;padding:1px 6px;margin-left:8px;vertical-align:middle;white-space:nowrap}
.mif .tag.pf{color:var(--gold);background:#FBF1D6;border-color:#ECD9A0}
.mif .tag.cv{color:var(--red);background:#FBEDEE;border-color:#EBC5C8}
.mif .note-pv{font-size:14px;color:var(--soft);margin:10px 0 0}
.mif .actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:22px}
.mif .btn{border:0;background:var(--red);color:#fff;font:inherit;font-weight:700;font-size:17px;border-radius:6px;padding:13px 26px;cursor:pointer;min-height:50px}
.mif .btn.sec{background:#fff;color:var(--blue);border:1px solid var(--line2)}
.mif .btn[disabled]{opacity:.6;cursor:wait}
.mif .err{color:var(--red);font-size:15px;font-weight:600}
.mif .ok{background:#E8F3EA;border:1px solid #B9DABF;color:#24572F;border-radius:6px;padding:10px 14px;font-weight:600;margin-bottom:16px}
.mif .list{display:flex;flex-direction:column;gap:10px}
.mif .it{background:#fff;border:1px solid var(--line);border-radius:6px;padding:12px 14px;display:grid;grid-template-columns:62px minmax(0,1fr);gap:12px}
.mif .it .db{padding:7px 0 6px}
.mif .it .db .d{font-size:22px}.mif .it .db.rng .d{font-size:16px}
.mif .it .db .w{font-size:12px;margin-top:5px}.mif .it .db .y{font-size:12px}
.mif .it b{display:block;color:var(--navy);font-size:16px;line-height:1.3}
.mif .it .pl{font-size:14px;color:var(--muted)}
.mif .it .ia{display:flex;gap:16px;margin-top:6px}
.mif .it .ia button{border:0;background:none;font:inherit;font-size:14px;font-weight:700;color:var(--blue);padding:4px 0;cursor:pointer}
.mif .it .ia button.del{color:var(--red)}
.mif .confirm{grid-column:1/-1;display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#FBEDEE;border:1px solid #EBC5C8;border-radius:5px;padding:8px 12px;font-size:15px}
.mif .confirm button{border:1px solid #EBC5C8;background:#fff;border-radius:4px;font:inherit;font-size:14px;font-weight:700;padding:6px 12px;cursor:pointer;color:var(--red)}
.mif .confirm button.no{color:var(--ink);border-color:var(--line2)}
.mif .empty{color:var(--soft);font-size:15px;padding:12px 0;margin:0}
.mif .side h2{margin-bottom:10px}
.mif .side .hint{margin:0 0 16px}
@media (max-width:900px){.mif-wrap{grid-template-columns:minmax(0,1fr)}}
@media (max-width:560px){.mif h1{font-size:32px}.mif-card{padding:20px 16px}.mif .row{grid-template-columns:minmax(0,1fr)}.mif .srow{grid-template-columns:70px minmax(0,1fr)}.mif .srow .tm{grid-column:2}.mif .btn{width:100%}}
</style>
HTML;
    }

    private static function formJs(): string
    {
        return <<<'HTML'
<script>
(function(){
const D=JSON.parse(document.getElementById('mif-data').textContent);
const $=id=>document.getElementById(id),esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const PAR={};D.parishes.forEach(p=>PAR[p[0]]=p);
const SVC=D.svc,TYP=D.typ,LI={ro:1,en:2,es:3};
const FLIST=[...D.mov.map(m=>[m[0],m[2],m[3],m[4]]),...Object.keys(D.fix).map(k=>[k,...D.fix[k]])];
const LMON={ro:["Ian","Feb","Mar","Apr","Mai","Iun","Iul","Aug","Sep","Oct","Noi","Dec"],en:["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],es:["Ene","Feb","Mar","Abr","May","Jun","Jul","Ago","Sep","Oct","Nov","Dic"]};
let LIST=D.visits.slice(),CAT=D.cats[0].id,TAB='up',SHOWN=10;const PER=10;
const S={type:'',parish:0,mode:'ours',lang:'ro',editing:0};
const endOf=v=>v.d2||v.d1;
function pascha(y){const a=y%4,b=y%7,c=y%19,d=(19*c+15)%30,e=(2*a+4*b-d+34)%7,m=Math.floor((d+e+114)/31),day=(d+e+114)%31+1;const j=new Date(Date.UTC(y,m-1,day));j.setUTCDate(j.getUTCDate()+13);return j;}
function feastKey(iso){if(!iso)return '';const[y,m,d]=iso.split('-').map(Number);const dt=Date.UTC(y,m-1,d),p=pascha(y);for(const f of D.mov){const x=new Date(p);x.setUTCDate(x.getUTCDate()+f[1]);if(x.getTime()===dt)return f[0];}return D.fix[iso.slice(5)]?iso.slice(5):'';}
function fl(k){return FLIST.find(f=>f[0]===k);}
$('svc').innerHTML='<option value="">Alegeți…</option>'+SVC.map(s=>`<option value="${s[0]}">${esc(s[1])}</option>`).join('')+'<option value="x">Altceva (scriu eu)…</option>';
$('fst').innerHTML='<option value="">Fără praznic</option>'+FLIST.map(f=>`<option value="${f[0]}">${esc(f[1])}</option>`).join('')+'<option value="x">Alt praznic (scriu eu)…</option>';
$('types').innerHTML=TYP.map(t=>`<button type="button" class="chip" data-k="${t[0]}" aria-pressed="false">${esc(t[1])}</button>`).join('');
if($('mif-cat'))$('mif-cat').onchange=()=>{CAT=+$('mif-cat').value;reset();renderList();};
$('multi').onchange=()=>{$('d2wrap').hidden=!$('multi').checked;if(!$('multi').checked)$('d2').value='';upd();};
$('svc').onchange=()=>{$('svcx').hidden=$('svc').value!=='x';if(!$('svcx').hidden)$('svcx').focus();upd();};
$('fst').onchange=()=>{$('fstx').hidden=$('fst').value!=='x';$('fsug').hidden=true;upd();};
$('types').onclick=e=>{const b=e.target.closest('.chip');if(!b)return;S.type=S.type===b.dataset.k?'':b.dataset.k;paintTypes();upd();};
function paintTypes(){$('types').querySelectorAll('.chip').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.k===S.type)));}
function setMode(m){S.mode=m;$('mOurs').setAttribute('aria-pressed',String(m==='ours'));$('mOther').setAttribute('aria-pressed',String(m==='other'));$('oursBox').hidden=m!=='ours';$('otherBox').hidden=m!=='other';upd();}
$('mOurs').onclick=()=>setMode('ours');$('mOther').onclick=()=>setMode('other');
const norm=s=>String(s).normalize('NFD').replace(/[̀-ͯ„”"]/g,'').toLowerCase();
const cityOf=p=>p[4]+(p[5]?', '+p[5]:'')+(p[6]&&p[6]!=='USA'?', '+p[6]:'');
let sel=-1;
$('pq').oninput=()=>{const q=norm($('pq').value.trim()),box=$('sug');if(q.length<2){box.hidden=true;return;}
 const hits=D.parishes.filter(p=>norm(p[1]+' '+p[2]+' '+p[4]+' '+p[5]).includes(q)).slice(0,8);sel=-1;
 box.innerHTML=hits.length?hits.map(p=>`<button type="button" data-i="${p[0]}"><b>${esc(p[1])}</b><span>${esc(cityOf(p))}</span></button>`).join(''):'<p class="empty" style="padding:10px 14px">Nu am găsit. Dacă nu este parohia noastră, alegeți „Alt loc”.</p>';box.hidden=false;};
$('pq').onkeydown=e=>{const bs=[...$('sug').querySelectorAll('button')];if(!bs.length)return;if(e.key==='ArrowDown'){sel=Math.min(sel+1,bs.length-1);e.preventDefault();}else if(e.key==='ArrowUp'){sel=Math.max(sel-1,0);e.preventDefault();}else if(e.key==='Enter'){e.preventDefault();(bs[sel]||bs[0]).click();return;}bs.forEach((b,i)=>b.classList.toggle('on',i===sel));};
$('sug').onclick=e=>{const b=e.target.closest('button');if(b)pick(+b.dataset.i);};
document.addEventListener('click',e=>{if(!$('acBox').contains(e.target))$('sug').hidden=true;});
function pick(id){const p=PAR[id];S.parish=p?id:0;$('sug').hidden=true;$('pq').value='';if(p){$('pkN').textContent=p[1];$('pkC').textContent=cityOf(p);}$('picked').hidden=!p;$('acBox').hidden=!!p;upd();}
$('pkX').onclick=()=>{S.parish=0;$('picked').hidden=true;$('acBox').hidden=false;$('pq').focus();upd();};
['d1','d2','tm','svcx','fstx','oN','oC','enT','esT'].forEach(id=>$(id).addEventListener('input',upd));
$('d1').addEventListener('change',()=>{suggestFeast();upd();});
document.querySelectorAll('.mif .ptabs button').forEach(b=>b.onclick=()=>{S.lang=b.dataset.l;document.querySelectorAll('.mif .ptabs button').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));upd();});
function suggestFeast(){const k=feastKey($('d1').value),box=$('fsug');if(!k||$('fst').value!==''){box.hidden=true;return;}const f=fl(k);
 box.innerHTML=`<span>Praznicul zilei: <b>${esc(f[1])}</b></span><button type="button">Adaugă</button>`;box.hidden=false;
 box.querySelector('button').onclick=()=>{$('fst').value=k;box.hidden=true;$('fstx').hidden=true;upd();};}
function read(){return{d1:$('d1').value,d2:$('multi').checked?$('d2').value:'',tm:$('tm').value,svc:$('svc').value,svcx:$('svcx').value.trim(),fst:$('fst').value,fstx:$('fstx').value.trim(),type:S.type,mode:S.mode,parish:S.parish,oN:$('oN').value.trim(),oC:$('oC').value.trim(),en:$('enT').value.trim(),es:$('esT').value.trim()};}
/* the stored shape: t/f = [ro,en,es] */
function toStored(v){let t,f;if(v.svc==='x')t=[v.svcx,v.en,v.es];else if(v.svc){const s=SVC.find(s=>s[0]===v.svc);t=[s[1],s[2],s[3]];}else t=['','',''];
 if(v.fst==='x')f=[v.fstx,'',''];else if(v.fst){const x=fl(v.fst);f=[x[1],x[2],x[3]];}else f=['','',''];
 return{d1:v.d1,d2:v.d2,tm:v.tm,t,f,type:v.type,parish:v.mode==='ours'?v.parish:0,place:v.mode==='other'?v.oN:'',city:v.mode==='other'?v.oC:''};}
function pick3(a,l){const i=LI[l]-1;return (a[i]||'').trim()||(a[0]||'').trim();}
function placeOf(s,l){if(s.parish&&PAR[s.parish]){const p=PAR[s.parish];return{n:p[LI[l]],c:cityOf(p),link:true};}return{n:s.place,c:s.city,link:false};}
function dateBox(s,l){if(!s.d1)return'<div class="db"><div class="d">–</div><div class="w">&nbsp;</div><div class="y">&nbsp;</div></div>';
 const a=new Date(s.d1+'T12:00'),b=s.d2?new Date(s.d2+'T12:00'):null,M=LMON[l];
 if(b&&b>a){const same=a.getMonth()===b.getMonth();return`<div class="db rng"><div class="d">${a.getDate()}–${b.getDate()}</div><div class="w">${same?M[a.getMonth()]:M[a.getMonth()]+'–'+M[b.getMonth()]}</div><div class="y">${b.getFullYear()}</div></div>`;}
 return`<div class="db"><div class="d">${a.getDate()}</div><div class="w">${M[a.getMonth()]}</div><div class="y">${a.getFullYear()}</div></div>`;}
function tfmt(t,l){if(!t)return'';if(l==='en'){let[h,m]=t.split(':').map(Number);const ap=h>=12?'PM':'AM';h=h%12||12;return`${h}:${String(m).padStart(2,'0')} ${ap}`;}return t;}
const PH={ro:['Slujba sau evenimentul','Locul'],en:['Service or event','Place'],es:['Servicio o evento','Lugar']};
function upd(){const v=read(),s=toStored(v),l=S.lang,ev=pick3(s.t,l),fe=pick3(s.f,l),pl=placeOf(s,l),ty=TYP.find(t=>t[0]===s.type);
 $('trBox').hidden=v.svc!=='x';
 const pn=pl.n?(pl.link?`<a>${esc(pl.n)}</a>`:esc(pl.n))+(pl.c?', '+esc(pl.c):''):(pl.c?esc(pl.c):`<span style="opacity:.5">${PH[l][1]}</span>`);
 $('pv').innerHTML=`<div class="srow">${dateBox(s,l)}<div>${fe?`<div class="fe">${esc(fe)}</div>`:''}<h3>${ev?esc(ev):`<span style="opacity:.5">${PH[l][0]}</span>`}${ty?`<span class="tag ${ty[4]}">${esc(ty[LI[l]])}</span>`:''}</h3><p>${pn}</p></div><span class="tm">${tfmt(s.tm,l)}</span></div>`;
 const n=[];if(l!=='ro'){if(v.svc==='x'&&v.svcx&&!(l==='en'?v.en:v.es))n.push('Evenimentul scris de mână apare în română până adăugați traducerea.');if(v.fst==='x'&&v.fstx)n.push('Praznicul scris de mână apare la fel în toate limbile.');if(v.mode==='other'&&v.oN)n.push('Numele locului apare așa cum l-ați scris.');}
 $('pvnote').textContent=n.join(' ');}
function load(r){const svc=r.t[0]?(SVC.find(s=>s[1]===r.t[0])||[null])[0]:'';const fk=r.f[0]?((FLIST.find(f=>f[1]===r.f[0])||[null])[0]):'';
 $('d1').value=r.d1;$('multi').checked=!!r.d2;$('d2wrap').hidden=!r.d2;$('d2').value=r.d2;$('tm').value=r.tm;
 $('svc').value=svc===null?'x':svc;$('svcx').value=svc===null?r.t[0]:'';$('svcx').hidden=svc!==null;$('enT').value=svc===null?r.t[1]:'';$('esT').value=svc===null?r.t[2]:'';
 $('fst').value=fk===null?'x':fk;$('fstx').value=fk===null?r.f[0]:'';$('fstx').hidden=fk!==null;
 S.type=r.type||'';paintTypes();setMode(r.parish?'ours':(r.place||r.city?'other':'ours'));$('oN').value=r.place||'';$('oC').value=r.city||'';
 if(r.parish)pick(r.parish);else{S.parish=0;$('picked').hidden=true;$('acBox').hidden=false;}$('fsug').hidden=true;upd();}
function reset(){load({d1:'',d2:'',tm:'',t:['','',''],f:['','',''],type:'',parish:0,place:'',city:''});S.editing=0;$('ftitle').textContent='Vizită nouă';$('save').textContent='Salvează vizita';$('cancel').hidden=true;$('err').textContent='';}
$('cancel').onclick=reset;
async function post(fields){const fd=new FormData();Object.entries(fields).forEach(([k,v])=>fd.append(k,v==null?'':v));fd.append(D.token,'1');
 const r=await fetch(D.api,{method:'POST',body:fd,credentials:'same-origin'});let j;try{j=await r.json();}catch(e){throw new Error('Răspuns neașteptat de la server ('+r.status+').');}if(!j.ok)throw new Error(j.error||'Eroare.');return j;}
$('mif-frm').onsubmit=async e=>{e.preventDefault();const v=read(),miss=[];
 if(!v.d1)miss.push('data');if(v.d2&&v.d2<=v.d1)miss.push('a doua dată (trebuie să fie după prima)');if(!v.svc||(v.svc==='x'&&!v.svcx))miss.push('slujba sau evenimentul');if(v.fst==='x'&&!v.fstx)miss.push('praznicul');if(v.mode==='ours'?!v.parish:!v.oN)miss.push('locul');
 if(miss.length){$('err').textContent='Lipsește: '+miss.join(', ')+'.';return;}
 $('err').textContent='';$('save').disabled=true;
 try{const j=await post({action:'save',id:S.editing||'',cat:CAT,d1:v.d1,d2:v.d2,tm:v.tm,svc:v.svc,svc_ro:v.svcx,svc_en:v.en,svc_es:v.es,fst:v.fst,fst_ro:v.fstx,type:v.type,parish:v.mode==='ours'?v.parish:'',place:v.mode==='other'?v.oN:'',city:v.mode==='other'?v.oC:''});
  const was=S.editing;LIST=LIST.filter(x=>x.id!==j.item.id);LIST.push(j.item);LIST.sort((a,b)=>a.d1.localeCompare(b.d1));reset();
  if(endOf(j.item)<D.today&&TAB==='up')setTab('past',j.item.d1.slice(0,4));else renderList();
  $('okmsg').textContent=was?'Modificarea a fost salvată.':(endOf(j.item)<D.today?'Vizita a fost adăugată la „Trecute”.':'Vizita a fost adăugată și apare pe site.');$('okmsg').hidden=false;setTimeout(()=>$('okmsg').hidden=true,5000);$('mif-frm').scrollIntoView({behavior:'smooth'});
 }catch(x){$('err').textContent=x.message;}finally{$('save').disabled=false;}};
function renderList(){const el=$('list'),mine=LIST.filter(v=>v.cat===CAT);
 const years=[...new Set(mine.filter(v=>endOf(v)<D.today).map(v=>v.d1.slice(0,4)).concat([D.today.slice(0,4)]))].sort().reverse();const cur=years.includes($('yr').value)?$('yr').value:years[0];
 $('yr').innerHTML=years.map(y=>`<option${y===cur?' selected':''}>${y}</option>`).join('');
 const q=norm($('lq').value.trim()),dq=$('ldt').value,find=!!(q||dq);$('tabsBox').hidden=find;$('lx').hidden=!find;
 let rows;
 if(find){rows=mine.filter(v=>{if(dq&&!(v.d1<=dq&&endOf(v)>=dq))return false;if(!q)return true;const pl=placeOf(v,'ro');return q.split(/\s+/).every(w=>norm([pick3(v.t,'ro'),pick3(v.f,'ro'),pl.n,pl.c,v.d1].join(' ')).includes(w));}).slice().reverse();}
 else{rows=mine.filter(v=>TAB==='up'?endOf(v)>=D.today:(endOf(v)<D.today&&v.d1.slice(0,4)===cur));if(TAB==='past')rows=rows.slice().reverse();}
 if(!rows.length){el.innerHTML=`<p class="empty">${find?'Nicio vizită găsită.':(TAB==='up'?'Nicio vizită programată.':'Nicio vizită în acest an.')}</p>`;return;}
 el.innerHTML=rows.slice(0,SHOWN).map(v=>{const pl=placeOf(v,'ro');return`<div class="it" data-id="${v.id}">${dateBox(v,'ro')}<div><b>${esc(pick3(v.t,'ro'))}</b><span class="pl">${esc(pl.n)}${pl.c?(pl.n?', ':'')+esc(pl.c):''}${v.tm?' · '+v.tm:''}</span><div class="ia">${v.edit?'<button type="button" class="ed">Editează</button>':''}${v.del?'<button type="button" class="del">Șterge</button>':''}</div></div></div>`;}).join('')
  +(rows.length>SHOWN?'<div class="mmore">Se încarcă…</div>':(rows.length>PER?`<div class="mmore">Toate cele ${rows.length} sunt afișate.</div>`:''));
 el._more=rows.length>SHOWN;requestAnimationFrame(nearEnd);}
function nearEnd(){const el=$('list'),mm=el.querySelector('.mmore');if(!mm||!el._more)return;const r=mm.getBoundingClientRect(),lr=el.getBoundingClientRect();if(!r.height)return;if(r.top<Math.min(innerHeight,lr.bottom)+200&&r.bottom>0){SHOWN+=PER;const st=el.scrollTop;renderList();el.scrollTop=st;}}
$('list').addEventListener('scroll',nearEnd,{passive:true});addEventListener('scroll',nearEnd,{passive:true});addEventListener('resize',nearEnd);
const fresh=()=>{SHOWN=PER;$('list').scrollTop=0;renderList();};
$('lq').oninput=fresh;$('ldt').onchange=fresh;$('lx').onclick=()=>{$('lq').value='';$('ldt').value='';fresh();};
function setTab(t,y){TAB=t;SHOWN=PER;$('list').scrollTop=0;$('tUp').setAttribute('aria-pressed',String(t==='up'));$('tPast').setAttribute('aria-pressed',String(t==='past'));$('yrBox').hidden=t!=='past';$('lhint').textContent=t==='up'?'Ștergerea face vizita să dispară de pe site.':'Puteți corecta sau șterge și vizitele care au trecut.';if(y){renderList();$('yr').value=y;}renderList();}
$('tUp').onclick=()=>setTab('up');$('tPast').onclick=()=>setTab('past');$('yr').onchange=fresh;
$('list').onclick=async e=>{const it=e.target.closest('.it');if(!it)return;const id=+it.dataset.id,v=LIST.find(x=>x.id===id);if(!v)return;
 if(e.target.classList.contains('ed')){S.editing=id;load(v);$('ftitle').textContent='Modificați vizita';$('save').textContent='Salvează modificările';$('cancel').hidden=false;$('mif-frm').scrollIntoView({behavior:'smooth'});}
 else if(e.target.classList.contains('del')){if(it.querySelector('.confirm'))return;const c=document.createElement('div');c.className='confirm';c.innerHTML='<span>Ștergeți această vizită de pe site?</span><button type="button" class="yes">Da, șterge</button><button type="button" class="no">Renunță</button>';it.appendChild(c);}
 else if(e.target.classList.contains('yes')){e.target.disabled=true;try{await post({action:'trash',id});LIST=LIST.filter(x=>x.id!==id);if(S.editing===id)reset();renderList();}catch(x){e.target.disabled=false;e.target.closest('.confirm').querySelector('span').textContent=x.message;}}
 else if(e.target.classList.contains('no')){e.target.closest('.confirm').remove();}};
reset();renderList();
})();
</script>
HTML;
    }

    /* ------------------------------------------------------------------ API (save / trash) */

    /** Handles ?mitit=api POSTs from the manager form; prints JSON and ends the request. */
    public static function api(): void
    {
        $app = Factory::getApplication();
        try {
            $out = self::apiRun();
        } catch (\Throwable $x) {
            Log::add('Itinerary API: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            $out = ['ok' => false, 'error' => 'Eroare la salvare. Încercați din nou.'];
        }
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        $app->close();
    }

    private static function apiRun(): array
    {
        $app = Factory::getApplication();
        $in = $app->getInput();
        if (strtoupper($in->getMethod()) !== 'POST') {
            return ['ok' => false, 'error' => 'Cerere invalidă.'];
        }
        $user = $app->getIdentity();
        if (!$user || $user->guest) {
            return ['ok' => false, 'error' => 'Sesiunea a expirat. Reîncărcați pagina și intrați din nou în cont.'];
        }
        if (!Session::checkToken('post')) {
            return ['ok' => false, 'error' => 'Sesiunea a expirat. Reîncărcați pagina.'];
        }
        $action = $in->post->getCmd('action');
        if ($action === 'import') {
            return self::import();
        }
        $id = $in->post->getInt('id');
        $cats = self::hierarchCats();
        $existing = null;
        if ($id) {
            $db = self::db();
            $existing = $db->setQuery($db->getQuery(true)->select(['id', 'catid', 'created_by', 'state'])->from('#__content')->where('id = ' . $id))->loadObject();
            if (!$existing || !isset($cats[(int) $existing->catid]) || (int) $existing->state === -2) {
                return ['ok' => false, 'error' => 'Vizita nu mai există.'];
            }
        }
        $asset = $id ? 'com_content.article.' . $id : '';
        $own = $existing && (int) $existing->created_by === (int) $user->id;

        if ($action === 'trash') {
            if (!$existing) {
                return ['ok' => false, 'error' => 'Vizita nu mai există.'];
            }
            if (!($user->authorise('core.edit.state', $asset) || ($own && $user->authorise('core.edit.own', $asset)))) {
                return ['ok' => false, 'error' => 'Nu aveți dreptul să ștergeți această vizită.'];
            }
            $table = self::table();
            if (!$table->publish([$id], -2, (int) $user->id)) {
                return ['ok' => false, 'error' => 'Vizita nu a putut fi ștearsă.'];
            }
            return ['ok' => true];
        }
        if ($action !== 'save') {
            return ['ok' => false, 'error' => 'Cerere invalidă.'];
        }

        $p = $in->post;
        $cat = $existing ? (int) $existing->catid : $p->getInt('cat');
        if (!isset($cats[$cat])) {
            return ['ok' => false, 'error' => 'Itinerar necunoscut.'];
        }
        if ($existing) {
            if (!($user->authorise('core.edit', $asset) || ($own && $user->authorise('core.edit.own', $asset)))) {
                return ['ok' => false, 'error' => 'Nu aveți dreptul să modificați această vizită.'];
            }
        } elseif (!$user->authorise('core.create', 'com_content.category.' . $cat)) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să adăugați vizite în acest itinerar.'];
        }

        $clean = fn (string $s, int $max = 200): string => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s))), 0, $max);
        $d1 = (string) $p->getString('d1');
        $d2 = (string) $p->getString('d2');
        $tm = (string) $p->getString('tm');
        $valid = fn ($d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        if (!$valid($d1)) {
            return ['ok' => false, 'error' => 'Data nu este corectă.'];
        }
        if ($d2 !== '' && (!$valid($d2) || $d2 <= $d1)) {
            return ['ok' => false, 'error' => 'A doua dată trebuie să fie după prima.'];
        }
        if ($tm !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $tm)) {
            return ['ok' => false, 'error' => 'Ora nu este corectă.'];
        }
        $svc = $p->getCmd('svc');
        if (isset(self::SVC[$svc])) {
            $t = self::SVC[$svc];
        } elseif ($svc === 'x' && $clean($p->getString('svc_ro')) !== '') {
            $t = [$clean($p->getString('svc_ro')), $clean($p->getString('svc_en')), $clean($p->getString('svc_es'))];
        } else {
            return ['ok' => false, 'error' => 'Alegeți slujba sau evenimentul.'];
        }
        $fst = (string) $p->getString('fst');
        if ($fst === '') {
            $f = ['', '', ''];
        } elseif (isset(self::FIX[$fst])) {
            $f = self::FIX[$fst];
        } elseif (isset(self::MOV[$fst])) {
            $f = array_slice(self::MOV[$fst], 1);
        } elseif ($fst === 'x' && $clean($p->getString('fst_ro')) !== '') {
            $f = [$clean($p->getString('fst_ro')), '', ''];
        } else {
            return ['ok' => false, 'error' => 'Praznicul nu este corect.'];
        }
        $type = $p->getCmd('type');
        if ($type !== '' && !isset(self::TYP[$type])) {
            $type = '';
        }
        $parish = $p->getInt('parish');
        $place = $clean($p->getString('place'));
        $city = $clean($p->getString('city'));
        $par = [];
        if ($parish) {
            $par = self::parishes([$parish]);
            if (!isset($par[$parish])) {
                return ['ok' => false, 'error' => 'Parohia nu a fost găsită.'];
            }
            $place = $city = '';
        } elseif ($place === '') {
            return ['ok' => false, 'error' => 'Scrieți locul vizitei.'];
        }

        // the article
        $where = $parish ? $par[$parish][3] : ($city !== '' ? $city : $place);
        $title = mb_substr($t[0] . ' – ' . $where . ' (' . $d1 . ')', 0, 250);
        $table = self::table();
        $now = Factory::getDate()->toSql();
        if ($existing) {
            $table->load($id);
            $table->title = $title;
            $table->modified = $now;
            $table->modified_by = (int) $user->id;
        } else {
            $alias = OutputFilter::stringURLSafe($d1 . ' ' . $t[0]);
            $table->bind([
                'title' => $title, 'alias' => ($alias !== '' ? $alias : $d1) . '-' . substr(bin2hex(random_bytes(3)), 0, 5),
                'catid' => $cat, 'state' => 1, 'access' => 1, 'language' => '*', 'introtext' => '', 'fulltext' => '',
                'created' => $now, 'created_by' => (int) $user->id, 'publish_up' => $now, 'featured' => 0,
                'images' => '{}', 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}', 'metakey' => '', 'metadesc' => '', 'note' => '',
            ]);
        }
        if (!$table->check() || !$table->store()) {
            Log::add('Itinerary save failed' . (method_exists($table, 'getError') ? ': ' . $table->getError() : ''), Log::WARNING, 'mitropolia');
            return ['ok' => false, 'error' => 'Vizita nu a putut fi salvată.'];
        }
        $newId = (int) $table->id;

        $values = [
            'itinerary-date'        => $d1 . ' 12:00:00',
            'itinerary-end'         => $d2 !== '' ? $d2 . ' 12:00:00' : '',
            'itinerary-time'        => $tm,
            'itinerary-title-ro'    => $t[0], 'itinerary-title-en' => $t[1], 'itinerary-title-es' => $t[2],
            'itinerary-occasion-ro' => $f[0], 'itinerary-occasion-en' => $f[1], 'itinerary-occasion-es' => $f[2],
            'itinerary-type'        => $type,
            'itinerary-parish'      => $parish ? (string) $parish : '',
            'itinerary-place'       => $place,
            'itinerary-city'        => $city,
        ];
        self::writeFields($newId, $values);
        self::ensureWorkflow([$cat]);

        $row = self::visits([$cat], true);
        foreach ($row as $r) {
            if ($r['id'] === $newId) {
                return ['ok' => true, 'item' => $r];
            }
        }
        return ['ok' => false, 'error' => 'Vizita a fost salvată, dar nu poate fi citită. Reîncărcați pagina.'];
    }

    /**
     * Bulk import of past itineraries (administrator only, Super User).
     * POST items = JSON list of {cat, d1, d2, tm, t:[ro,en,es], f:[ro,en,es], type, pid, place, city}.
     */
    private static function import(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$app->isClient('administrator') || !$user->authorise('core.admin')) {
            return ['ok' => false, 'error' => 'Not allowed.'];
        }
        $items = json_decode((string) $app->getInput()->post->getRaw('items'), true);
        if (!is_array($items)) {
            return ['ok' => false, 'error' => 'No items.'];
        }
        $cats = self::hierarchCats();
        $valid = fn ($d) => is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        $clean = fn ($s, int $max = 400): string => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s))), 0, $max);
        $now = Factory::getDate()->toSql();
        $ids = [];
        $errors = [];
        foreach ($items as $n => $it) {
            $cat = (int) ($it['cat'] ?? 0);
            $d1 = (string) ($it['d1'] ?? '');
            $d2 = (string) ($it['d2'] ?? '');
            $t = array_map($clean, array_pad((array) ($it['t'] ?? []), 3, ''));
            $f = array_map($clean, array_pad((array) ($it['f'] ?? []), 3, ''));
            if (!isset($cats[$cat]) || !$valid($d1) || ($d2 !== '' && (!$valid($d2) || $d2 <= $d1)) || $t[0] === '') {
                $errors[] = $n;
                continue;
            }
            $type = isset(self::TYP[$it['type'] ?? '']) ? (string) $it['type'] : '';
            $pid = (int) ($it['pid'] ?? 0);
            $place = $clean($it['place'] ?? '', 250);
            $city = $clean($it['city'] ?? '', 250);
            $tm = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($it['tm'] ?? '')) ? (string) $it['tm'] : '';
            $where = $city !== '' ? $city : $place;
            $table = self::table();
            $alias = OutputFilter::stringURLSafe($d1 . ' ' . $t[1]);
            $table->bind([
                'title' => mb_substr($t[0] . ($where !== '' ? ' – ' . $where : '') . ' (' . $d1 . ')', 0, 250),
                'alias' => ($alias !== '' ? $alias : $d1) . '-' . substr(bin2hex(random_bytes(3)), 0, 5),
                'catid' => $cat, 'state' => 1, 'access' => 1, 'language' => '*', 'introtext' => '', 'fulltext' => '',
                'created' => $now, 'created_by' => (int) $user->id, 'publish_up' => $now, 'featured' => 0,
                'images' => '{}', 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}', 'metakey' => '', 'metadesc' => '', 'note' => 'import',
            ]);
            if (!$table->check() || !$table->store()) {
                $errors[] = $n;
                continue;
            }
            $newId = (int) $table->id;
            self::writeFields($newId, [
                'itinerary-date' => $d1 . ' 12:00:00', 'itinerary-end' => $d2 !== '' ? $d2 . ' 12:00:00' : '', 'itinerary-time' => $tm,
                'itinerary-title-ro' => $t[0], 'itinerary-title-en' => $t[1], 'itinerary-title-es' => $t[2],
                'itinerary-occasion-ro' => $f[0], 'itinerary-occasion-en' => $f[1], 'itinerary-occasion-es' => $f[2],
                'itinerary-type' => $type, 'itinerary-parish' => $pid ? (string) $pid : '', 'itinerary-place' => $pid ? '' : $place,
                'itinerary-city' => $pid ? '' : $city,
            ]);
            $ids[] = $newId;
        }
        self::ensureWorkflow(array_keys($cats));
        return ['ok' => true, 'created' => count($ids), 'first' => $ids[0] ?? 0, 'last' => end($ids) ?: 0, 'errors' => $errors];
    }

    /** Joomla lists articles through their workflow stage; give every visit one (the default stage) when it has none. */
    private static function ensureWorkflow(array $cats): void
    {
        try {
            $db = self::db();
            $stage = (int) $db->setQuery('SELECT s.id FROM #__workflow_stages s INNER JOIN #__workflows w ON w.id = s.workflow_id'
                . ' WHERE w.extension = ' . $db->quote('com_content.article') . ' AND w.default = 1 AND s.default = 1', 0, 1)->loadResult();
            if (!$stage || !$cats) {
                return;
            }
            $db->setQuery('INSERT INTO #__workflow_associations (item_id, stage_id, extension)'
                . ' SELECT a.id, ' . $stage . ', ' . $db->quote('com_content.article') . ' FROM #__content a'
                . ' LEFT JOIN #__workflow_associations wa ON wa.item_id = a.id AND wa.extension = ' . $db->quote('com_content.article')
                . ' WHERE wa.item_id IS NULL AND a.catid IN (' . implode(',', array_map('intval', $cats)) . ')')->execute();
        } catch (\Throwable $x) {
            Log::add('Itinerary workflow link: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
        }
    }

    private static function table()
    {
        return Factory::getApplication()->bootComponent('com_content')->getMVCFactory()->createTable('Article', 'Administrator');
    }

    private static function writeFields(int $item, array $values): void
    {
        $ids = self::fieldIds();
        $db = self::db();
        foreach ($values as $name => $value) {
            if (!isset($ids[$name])) {
                continue;
            }
            $fid = (int) $ids[$name];
            $db->setQuery($db->getQuery(true)->delete('#__fields_values')->where('field_id = ' . $fid)
                ->where('item_id = ' . $db->quote((string) $item)))->execute();
            if ((string) $value !== '') {
                $o = (object) ['field_id' => $fid, 'item_id' => (string) $item, 'value' => (string) $value];
                $db->insertObject('#__fields_values', $o);
            }
        }
    }
}
