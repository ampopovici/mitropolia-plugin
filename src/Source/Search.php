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
 * Search results ("Search" builder element, on the Smart Search template). Searches the site's own
 * articles directly (titles, text and custom fields), so nothing needs indexing. Diacritics do not matter
 * (the database collation ignores them), every word must match, and words that mean the same thing in the
 * three languages are searched together (Paști / Pascha / Pascua...). Results are grouped by section in
 * tabs, in the page language plus "All languages" items; "all languages" widens the search.
 */
final class Search
{
    private const PER_PAGE = 20;
    private const MAX_ROWS = 600;
    /** Section order; top-category alias without the -ro/-en/-es ending. */
    private const GROUPS = ['news', 'pastoral-letters', 'words-of-wisdom', 'events', 'documents', 'publications', 'media', 'galleries', 'hierarchs', 'parishes', 'pages'];
    private const ALIAS_GROUP = ['revista-credinta' => 'publications', 'almanahul-credinta' => 'publications'];
    private const SKIP = ['clergy', 'static', 'itinerary'];
    /** Words searched together (compared without diacritics). */
    private const SYNONYMS = [
        ['paști', 'pascha', 'pascua', 'învierea', 'înviere', 'resurrection', 'resurrección', 'easter'],
        ['crăciun', 'nașterea domnului', 'nativity', 'christmas', 'navidad', 'natividad'],
        ['boboteaza', 'botezul domnului', 'theophany', 'epiphany', 'teofanía', 'epifanía'],
        ['rusalii', 'cincizecimea', 'pentecost', 'pentecostés'],
        ['postul mare', 'great lent', 'gran cuaresma', 'cuaresma'],
        ['adormirea', 'dormition', 'dormición'],
        ['schimbarea la față', 'transfiguration', 'transfiguración'],
        ['înălțarea domnului', 'ascension', 'ascensión del señor'],
        ['botez', 'baptism', 'bautismo'],
        ['cununie', 'cununia', 'wedding', 'marriage', 'matrimonio', 'boda'],
        ['spovedanie', 'spovedania', 'confession', 'confesión'],
        ['liturghie', 'liturghia', 'liturgy', 'liturgia'],
        ['mitropolit', 'mitropolitul', 'metropolitan', 'metropolitano', 'metropolita'],
        ['episcop', 'episcopul', 'bishop', 'obispo'],
        ['preot', 'preotul', 'priest', 'sacerdote'],
        ['parohie', 'parohia', 'parish', 'parroquia'],
        ['hram', 'hramul', 'feast day', 'patronal', 'fiesta patronal'],
        ['tabără', 'camp', 'campamento'],
        ['tineri', 'tineret', 'youth', 'jóvenes', 'juventud'],
        ['hirotonie', 'hirotonia', 'ordination', 'ordenación'],
        ['târnosire', 'sfințire', 'consecration', 'consagración'],
        ['congres', 'congresul', 'congress', 'congreso'],
        ['pastorală', 'scrisoare pastorală', 'pastoral letter', 'carta pastoral'],
    ];

    private const UI = [
        'title'    => ['Căutare', 'Search', 'Búsqueda'],
        'intro'    => ['Căutați în știri, pastorale, evenimente, documente, publicații și parohii.', 'Search news, pastoral letters, events, documents, publications and parishes.', 'Busque en noticias, cartas pastorales, eventos, documentos, publicaciones y parroquias.'],
        'ph'       => ['Ce căutați?', 'What are you looking for?', '¿Qué busca?'],
        'go'       => ['Caută', 'Search', 'Buscar'],
        'all'      => ['Toate', 'All', 'Todo'],
        'alllang'  => ['Caută în toate limbile', 'Search all languages', 'Buscar en todos los idiomas'],
        'for'      => ['pentru', 'for', 'para'],
        'r1'       => ['rezultat', 'result', 'resultado'],
        'rn'       => ['rezultate', 'results', 'resultados'],
        'rde'      => ['de rezultate', 'results', 'resultados'],
        'none'     => ['Nu am găsit nimic pentru', 'Nothing found for', 'No se encontró nada para'],
        'tips'     => ['Încercați un cuvânt mai scurt sau mai general, verificați ortografia ori căutați în toate limbile.', 'Try a shorter or more general word, check the spelling, or search all languages.', 'Pruebe una palabra más corta o más general, revise la ortografía o busque en todos los idiomas.'],
        'also'     => ['Am căutat și', 'Also searched for', 'También se buscó'],
        'short'    => ['Scrieți cel puțin două litere.', 'Type at least two letters.', 'Escriba al menos dos letras.'],
        'pages'    => ['Pagini', 'Pages', 'Páginas'],
        'home'     => ['Acasă', 'Home', 'Inicio'],
        'try'      => ['Căutări frecvente', 'Common searches', 'Búsquedas frecuentes'],
    ];
    private const TRY = [
        'ro' => ['Paști', 'Liturghie', 'Tabăra ROYA', 'Hram', 'Statut', 'Botez'],
        'en' => ['Pascha', 'Liturgy', 'ROYA camp', 'Feast day', 'Statutes', 'Baptism'],
        'es' => ['Pascua', 'Liturgia', 'Campamento', 'Fiesta patronal', 'Estatutos', 'Bautismo'],
    ];
    private const GROUP_KEY = [
        'news' => 'MIT_TAG_G_NEWS', 'pastoral-letters' => 'MIT_TAG_G_PASTORAL_LETTERS', 'words-of-wisdom' => 'MIT_TAG_G_WORDS_OF_WISDOM',
        'events' => 'MIT_TAG_G_EVENTS', 'documents' => 'MIT_TAG_G_DOCUMENTS', 'publications' => 'MIT_TAG_G_PUBLICATIONS', 'media' => 'MIT_TAG_G_MEDIA',
        'galleries' => 'MIT_TAG_G_GALLERIES', 'hierarchs' => 'MIT_TAG_G_HIERARCHS', 'parishes' => 'MIT_TAG_G_PARISHES', 'pages' => 'MIT_TAG_G_PAGES',
    ];
    private const MON = [
        'ro' => ['ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.', 'iul.', 'aug.', 'sept.', 'oct.', 'nov.', 'dec.'],
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'es' => ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sept.', 'oct.', 'nov.', 'dic.'],
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

    /** The query in the quotation marks of the page language. */
    private static function quoted(string $q): string
    {
        [$a, $b] = ['ro' => ['„', '”'], 'en' => ['“', '”'], 'es' => ['«', '»']][self::lang()];
        return $a . self::e($q) . $b;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Lower case without diacritics, one character for one character (so positions match the original). */
    private static function fold(string $s): string
    {
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $d = class_exists('Normalizer') ? \Normalizer::normalize($ch, \Normalizer::FORM_D) : $ch;
            $b = preg_replace('/\p{Mn}+/u', '', (string) $d);
            $b = mb_strtolower($b === '' ? $ch : $b);
            $out .= mb_strlen($b) === 1 ? $b : mb_strtolower($ch);
        }
        return $out;
    }

    /** Search terms: phrases from the synonym list first, then single words; each with its alternatives. */
    private static function terms(string $q): array
    {
        $f = ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::fold($q))) . ' ';
        $sets = [];
        foreach (self::SYNONYMS as $set) {
            $sets[] = array_combine(array_map([self::class, 'fold'], $set), $set);
        }
        $terms = [];
        $phrases = [];
        foreach ($sets as $i => $set) {
            foreach (array_keys($set) as $w) {
                if (str_contains($w, ' ')) {
                    $phrases[$w] = $i;
                }
            }
        }
        uksort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($phrases as $p => $i) {
            if (str_contains($f, ' ' . $p . ' ')) {
                $terms[] = ['w' => $p, 'alt' => array_keys($sets[$i]), 'show' => array_values($sets[$i])];
                $f = str_replace(' ' . $p . ' ', ' ', $f);
            }
        }
        foreach (array_filter(explode(' ', trim($f)), fn ($w) => mb_strlen($w) >= 2) as $w) {
            $t = ['w' => $w, 'alt' => [$w], 'show' => [$w]];
            foreach ($sets as $set) {
                if (isset($set[$w])) {
                    $t = ['w' => $w, 'alt' => array_keys($set), 'show' => array_values($set)];
                    break;
                }
            }
            $terms[] = $t;
        }
        return array_slice($terms, 0, 6);
    }

    private static function group(string $path): string
    {
        $root = explode('/', $path, 2)[0];
        return self::ALIAS_GROUP[$root] ?? preg_replace('/-(ro|en|es)$/', '', $root);
    }

    private static function groupLabel(string $g): string
    {
        $k = self::GROUP_KEY[$g] ?? '';
        $t = $k !== '' ? \Joomla\CMS\Language\Text::_($k) : '';
        return $t !== '' && $t !== $k ? $t : ucfirst(str_replace('-', ' ', $g));
    }

    private static function date(string $sql): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $sql, $m) || $m[1] === '0000') {
            return '';
        }
        $l = self::lang();
        $mon = self::MON[$l][(int) $m[2] - 1];
        return $l === 'en' ? $mon . ' ' . (int) $m[3] . ', ' . $m[1] : (int) $m[3] . ' ' . $mon . ' ' . $m[1];
    }

    /* ------------------------------------------------------------------ query */

    /** Categories searched: everything except the sections in SKIP. */
    private static function catIds(): array
    {
        $db = self::db();
        $rows = $db->setQuery($db->getQuery(true)->select(['id', 'path'])->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))->where('published = 1'))->loadObjectList();
        $ids = [];
        foreach ($rows as $r) {
            if (!in_array(self::group((string) $r->path), self::SKIP, true)) {
                $ids[] = (int) $r->id;
            }
        }
        return $ids ?: [0];
    }

    private static function find(array $terms, bool $allLang): array
    {
        $db = self::db();
        $app = Factory::getApplication();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', $app->getIdentity()->getAuthorisedViewLevels()) ?: [1];
        $cats = self::catIds();
        $q = $db->getQuery(true)
            ->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.images', 'a.publish_up', 'a.introtext', $db->quoteName('a.fulltext'), 'c.path'])
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid')
            ->whereIn('a.catid', $cats)
            ->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels);
        if (!$allLang) {
            $q->whereIn('a.language', [$app->getLanguage()->getTag(), '*'], ParameterType::STRING);
        }
        foreach ($terms as $t) {
            $or = [];
            $fv = [];
            foreach ($t['alt'] as $alt) {
                $like = $db->quote('%' . $db->escape($alt, true) . '%', false);
                $or[] = 'a.title LIKE ' . $like;
                $or[] = 'a.introtext LIKE ' . $like;
                $or[] = $db->quoteName('a.fulltext') . ' LIKE ' . $like;
                $fv[] = 'value LIKE ' . $like;
            }
            // custom fields (parish address, gallery and video titles...): one pass over the field values
            $ids = $db->setQuery('SELECT DISTINCT item_id FROM #__fields_values WHERE ' . implode(' OR ', $fv), 0, 3000)->loadColumn();
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if ($ids) {
                $or[] = 'a.id IN (' . implode(',', $ids) . ')';
            }
            $q->where('(' . implode(' OR ', $or) . ')');
        }
        $q->order('a.publish_up DESC');
        return $db->setQuery($q, 0, self::MAX_ROWS)->loadObjectList();
    }

    /** Field values that serve as titles (galleries and media are "All languages" with a title per language). */
    private static function fieldTitles(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $db = self::db();
        $l = self::lang();
        $names = [];
        foreach (['gallery-title-', 'video-title-'] as $p) {
            foreach (['ro', 'en', 'es'] as $x) {
                $names[] = $p . $x;
            }
        }
        $rows = $db->setQuery($db->getQuery(true)->select(['v.item_id', 'f.name', 'v.value'])->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON f.id = v.field_id')->whereIn('f.name', $names, ParameterType::STRING)
            ->whereIn('v.item_id', array_map('strval', $ids), ParameterType::STRING))->loadObjectList();
        $by = [];
        foreach ($rows as $r) {
            if (trim((string) $r->value) !== '') {
                $by[(int) $r->item_id][substr($r->name, -2)] = trim((string) $r->value);
            }
        }
        $out = [];
        foreach ($by as $id => $t) {
            foreach (array_unique([$l, $l === 'es' ? 'en' : 'ro', 'ro', 'en']) as $x) {
                if (isset($t[$x])) {
                    $out[$id] = $t[$x];
                    break;
                }
            }
        }
        return $out;
    }

    private static function score(object $r, array $terms, string $foldQ): int
    {
        $ft = self::fold((string) $r->title);
        $fx = self::fold(strip_tags((string) $r->introtext . ' ' . (string) $r->fulltext));
        $s = 0;
        if ($foldQ !== '' && str_contains($ft, $foldQ)) {
            $s += 40;
        }
        foreach ($terms as $t) {
            foreach ($t['alt'] as $alt) {
                if (str_contains($ft, $alt)) {
                    $s += 15;
                    break;
                }
            }
            foreach ($t['alt'] as $alt) {
                $s += min(5, substr_count($fx, $alt));
            }
        }
        // a little for recent items
        $y = (int) substr((string) $r->publish_up, 0, 4);
        return $s * 10 + max(0, min(9, $y - 2017));
    }

    /** Marks every alternative in the text (case and diacritics ignored). */
    private static function mark(string $text, array $terms): string
    {
        $f = self::fold($text);
        $hits = [];
        foreach ($terms as $t) {
            foreach ($t['alt'] as $alt) {
                $off = 0;
                while ($alt !== '' && ($p = mb_strpos($f, $alt, $off)) !== false) {
                    $hits[] = [$p, $p + mb_strlen($alt)];
                    $off = $p + 1;
                }
            }
        }
        if (!$hits) {
            return self::e($text);
        }
        usort($hits, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = '';
        $pos = 0;
        foreach ($hits as [$s, $end]) {
            if ($s < $pos) {
                continue;
            }
            $out .= self::e(mb_substr($text, $pos, $s - $pos)) . '<mark>' . self::e(mb_substr($text, $s, $end - $s)) . '</mark>';
            $pos = $end;
        }
        return $out . self::e(mb_substr($text, $pos));
    }

    /** About 220 characters of text around the first match. */
    private static function snippet(object $r, array $terms): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/h\d)[^>]*>/i', ' ', (string) $r->introtext . ' ' . (string) $r->fulltext)), ENT_QUOTES, 'UTF-8')));
        $plain = preg_replace('/\{[a-z]+[^}]*\}/i', '', $plain);
        if ($plain === '') {
            return '';
        }
        $f = self::fold($plain);
        $first = null;
        foreach ($terms as $t) {
            foreach ($t['alt'] as $alt) {
                $p = mb_strpos($f, $alt);
                if ($p !== false && ($first === null || $p < $first)) {
                    $first = $p;
                }
            }
        }
        $len = mb_strlen($plain);
        $start = $first === null ? 0 : max(0, $first - 80);
        if ($start > 0) {
            $sp = mb_strpos($plain, ' ', $start);
            $start = $sp !== false && $sp < $start + 20 ? $sp + 1 : $start;
        }
        $cut = mb_substr($plain, $start, 230);
        if ($start + 230 < $len) {
            $sp = mb_strrpos($cut, ' ');
            $cut = $sp !== false && $sp > 150 ? mb_substr($cut, 0, $sp) : $cut;
        }
        return ($start > 0 ? '… ' : '') . self::mark($cut, $terms) . ($start + mb_strlen($cut) < $len ? ' …' : '');
    }

    private static function image(object $r): string
    {
        $im = json_decode((string) $r->images, true) ?: [];
        $p = trim(explode('#', (string) ($im['image_intro'] ?? ''), 2)[0]) ?: trim(explode('#', (string) ($im['image_fulltext'] ?? ''), 2)[0]);
        if ($p === '' || preg_match('#^https?://#', $p) || !is_file(JPATH_ROOT . '/' . $p)) {
            return '';
        }
        try {
            return News::thumbUrl($p);
        } catch (\Throwable $x) {
            return Uri::root(true) . '/' . $p;
        }
    }

    /* ------------------------------------------------------------------ render */

    public static function render(array $props): string
    {
        try {
            return self::html($props) . News::sharedCss() . self::css();
        } catch (\Throwable $x) {
            Log::add('Search: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
            $u = Factory::getApplication()->getIdentity();
            return $u && $u->authorise('core.admin') ? '<!-- search error: ' . htmlspecialchars($x->getMessage() . ' @' . $x->getLine()) . ' -->' : '';
        }
    }

    public static function text(array $props): string
    {
        return '';
    }

    private static function html(array $props): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $in = $app->getInput();
        $q = trim(mb_substr((string) $in->getString('q', ''), 0, 120));
        $allLang = $in->getInt('al', 0) === 1;
        $gSel = (string) $in->getCmd('g');
        $page = max(1, $in->getInt('p', 1));
        $base = strtok(Uri::getInstance()->toString(['path']), '?');
        $link = function (array $over) use ($base, $q, $allLang, $gSel) {
            $p = array_merge(['q' => $q, 'al' => $allLang ? 1 : '', 'g' => $gSel, 'p' => ''], $over);
            $p = array_filter($p, fn ($v) => $v !== '' && $v !== null);
            return $base . ($p ? '?' . http_build_query($p) : '');
        };
        $title = trim((string) ($props['title'] ?? '')) ?: self::ui('title');
        try {
            $site = (string) $app->get('sitename');
            $app->getDocument()->setTitle(($q !== '' ? $q . ' – ' : '') . $title . ($site !== '' ? ' – ' . $site : ''));
        } catch (\Throwable $x) {
        }

        $home = $app->getMenu()->getDefault($app->getLanguage()->getTag());
        $crumbs = '<nav class="mpx-crumbs mnx-crumbs" aria-label="Breadcrumb"><a href="' . $e($home ? Route::_('index.php?Itemid=' . (int) $home->id) : Uri::root()) . '">'
            . $e($home ? (string) $home->title : self::ui('home')) . '</a><span aria-hidden="true">›</span><span aria-current="page">' . $e($title) . '</span></nav>';
        $form = '<form class="msr-form" method="get" action="' . $e($base) . '" role="search">'
            . '<label class="msr-box"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
            . '<input type="search" name="q" value="' . $e($q) . '" placeholder="' . $e(self::ui('ph')) . '" aria-label="' . $e(self::ui('ph')) . '" autocomplete="off"' . ($q === '' ? ' autofocus' : '') . '></label>'
            . '<button type="submit" class="msr-go">' . $e(self::ui('go')) . '</button>'
            . '<label class="msr-al"><input type="checkbox" name="al" value="1"' . ($allLang ? ' checked' : '') . ' onchange="if(this.form.q.value)this.form.submit()"> ' . $e(self::ui('alllang')) . '</label></form>';

        $terms = mb_strlen($q) >= 2 ? self::terms($q) : [];
        $body = '';
        $chips = '';
        $status = '';
        if ($q === '') {
            $try = '';
            foreach (self::TRY[self::lang()] as $w) {
                $try .= '<a class="mpx-chip" href="' . $e($link(['q' => $w, 'g' => ''])) . '">' . $e($w) . '</a>';
            }
            $body = '<div class="msr-try"><h2 class="msr-h">' . $e(self::ui('try')) . '</h2><div class="mnx-chips">' . $try . '</div></div>';
        } elseif (!$terms) {
            $body = '<p class="mnx-empty">' . $e(self::ui('short')) . '</p>';
        } else {
            $rows = self::find($terms, $allLang);
            $foldQ = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::fold($q)));
            $groups = [];
            foreach ($rows as $r) {
                $g = self::group((string) $r->path);
                if (in_array($g, self::SKIP, true)) {
                    continue;
                }
                $r->g = $g;
                $r->score = self::score($r, $terms, $foldQ);
                $groups[$g][] = $r;
            }
            uksort($groups, function ($a, $b) {
                $ia = array_search($a, self::GROUPS, true);
                $ib = array_search($b, self::GROUPS, true);
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            });
            $total = array_sum(array_map('count', $groups));
            if ($gSel !== '' && !isset($groups[$gSel])) {
                $gSel = '';
            }
            $list = $gSel !== '' ? $groups[$gSel] : array_merge(...array_values($groups ?: [[]]));
            usort($list, fn ($a, $b) => [$b->score, (string) $b->publish_up] <=> [$a->score, (string) $a->publish_up]);
            $shown = count($list);
            $pages = max(1, (int) ceil($shown / self::PER_PAGE));
            $page = min($page, $pages);
            $slice = array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

            $word = $total === 1 ? self::ui('r1') : (self::lang() === 'ro' && ($total % 100 === 0 || $total % 100 >= 20) ? self::ui('rde') : self::ui('rn'));
            $alts = [];
            foreach ($terms as $t) {
                foreach ($t['show'] as $k => $a) {
                    if ($t['alt'][$k] !== $t['w'] && count($alts) < 6) {
                        $alts[] = $a;
                    }
                }
            }
            $status = $total
                ? '<p class="msr-status"><b>' . $total . '</b> ' . $e($word) . ' ' . $e(self::ui('for')) . ' ' . self::quoted($q)
                    . ($alts ? '<span class="msr-also">' . $e(self::ui('also')) . ': ' . $e(implode(', ', $alts)) . '</span>' : '') . '</p>'
                : '';
            if (count($groups) > 1) {
                $chips = '<div class="mnx-chips msr-tabs"><a class="mpx-chip' . ($gSel === '' ? ' on' : '') . '" href="' . $e($link(['g' => '', 'p' => ''])) . '">' . $e(self::ui('all')) . ' <span class="msr-n">' . $total . '</span></a>';
                foreach ($groups as $g => $gl) {
                    $chips .= '<a class="mpx-chip' . ($gSel === $g ? ' on' : '') . '" href="' . $e($link(['g' => $g, 'p' => ''])) . '">' . $e(self::groupLabel($g)) . ' <span class="msr-n">' . count($gl) . '</span></a>';
                }
                $chips .= '</div>';
            }
            if (!$total) {
                $body = '<div class="msr-none"><p class="msr-none-h">' . $e(self::ui('none')) . ' ' . self::quoted($q) . '</p><p>' . $e(self::ui('tips')) . '</p>'
                    . (!$allLang ? '<a class="mpx-chip" href="' . $e($link(['al' => 1, 'g' => ''])) . '">' . $e(self::ui('alllang')) . ' →</a>' : '') . '</div>';
            } else {
                $titles = self::fieldTitles(array_map(fn ($r) => (int) $r->id, $slice));
                $tag = $app->getLanguage()->getTag();
                $items = '';
                foreach ($slice as $r) {
                    $t = $titles[(int) $r->id] ?? (string) $r->title;
                    $lang = (string) $r->language;
                    $url = Route::_(RouteHelper::getArticleRoute($r->id . ':' . $r->alias, (int) $r->catid, $lang === '*' ? $tag : $lang));
                    $img = self::image($r);
                    $other = $lang !== '*' && $lang !== $tag ? '<span class="msr-lang">' . $e(strtoupper(substr($lang, 0, 2))) . '</span>' : '';
                    $d = in_array($r->g, ['news', 'pastoral-letters', 'words-of-wisdom', 'events'], true) ? self::date((string) $r->publish_up) : '';
                    $items .= '<li class="msr-item"><div class="msr-t"><p class="msr-k"><span>' . $e(self::groupLabel($r->g)) . '</span>' . ($d !== '' ? '<span class="dot" aria-hidden="true"></span><time>' . $e($d) . '</time>' : '') . $other . '</p>'
                        . '<h3><a href="' . $e($url) . '">' . self::mark($t, $terms) . '</a></h3>'
                        . (($sn = self::snippet($r, $terms)) !== '' ? '<p class="msr-x">' . $sn . '</p>' : '') . '</div>'
                        . ($img !== '' ? '<a class="msr-img" href="' . $e($url) . '" tabindex="-1" aria-hidden="true"><img src="' . $e($img) . '" alt="" loading="lazy"></a>' : '') . '</li>';
                }
                $body = '<ol class="msr-list">' . $items . '</ol>';
                if ($pages > 1) {
                    $body .= '<nav class="mnx-pg" aria-label="' . $e(self::ui('pages')) . '">' . ($page > 1 ? '<a href="' . $e($link(['p' => $page - 1 > 1 ? $page - 1 : ''])) . '">‹</a>' : '');
                    for ($n = 1; $n <= $pages; $n++) {
                        if ($pages > 9 && $n > 2 && $n < $pages - 1 && abs($n - $page) > 2) {
                            if ($n === 3 || $n === $pages - 2) {
                                $body .= '<span class="gap">…</span>';
                            }
                            continue;
                        }
                        $body .= $n === $page ? '<span class="on" aria-current="page">' . $n . '</span>' : '<a href="' . $e($link(['p' => $n > 1 ? $n : ''])) . '">' . $n . '</a>';
                    }
                    $body .= ($page < $pages ? '<a href="' . $e($link(['p' => $page + 1])) . '">›</a>' : '') . '</nav>';
                }
            }
        }

        $cls = trim((string) ($props['class'] ?? ''));
        return '<div class="mnx msr' . ($cls !== '' ? ' ' . $e($cls) : '') . '"><div class="mnx-hero"><div class="mnx-hero-in">' . $crumbs
            . '<h1 class="mpx-h1 msr-h1">' . $e($title) . '</h1>' . ($q === '' ? '<p class="mnx-intro">' . $e(self::ui('intro')) . '</p>' : '')
            . $form . $chips . '</div></div><div class="msr-body">' . $status . $body . '</div></div>';
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
/* Search results (Mitropolia plugin) on top of the news styles */
.msr .msr-h1{margin-top:28px}
.msr-form{display:flex;flex-wrap:wrap;gap:10px 12px;align-items:center;margin-top:26px;max-width:860px}
.msr-box{position:relative;flex:1 1 420px;display:flex;min-width:0}
.msr-box svg{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:#B99755}
.msr .msr-box input{width:100%;height:58px;border:1px solid #D9CBAA;border-radius:4px;padding:0 18px 0 54px;font:inherit;font-size:19px;background:#fff;color:#1B2A4A;box-sizing:border-box;margin:0}
.msr .msr-box input:focus{outline:2px solid #203D78;outline-offset:1px}
.msr .msr-go{height:58px;padding:0 28px;border:0;border-radius:4px;background:#A32D36;color:#fff;font:inherit;font-weight:600;font-size:16px;cursor:pointer}
.msr .msr-go:hover{background:#8a242c}
.msr-al{flex-basis:100%;display:flex;align-items:center;gap:8px;font-size:15px;color:#3C4A66;cursor:pointer}
.msr-al input{width:18px;height:18px;accent-color:#203D78;margin:0}
.msr .msr-tabs{margin-top:26px}
.msr-n{font-weight:400;opacity:.7;margin-left:4px;font-variant-numeric:tabular-nums}
.msr-body{padding:36px 0 96px;max-width:960px}
.msr-status{margin:0 0 8px;color:#6B6F7B;font-size:16px}.msr-status b{color:#172E5C}
.msr-also{display:block;font-size:14px;margin-top:4px}
.msr-list{list-style:none;margin:0;padding:0}
.msr-item{display:flex;gap:28px;align-items:flex-start;padding:26px 0;border-bottom:1px solid #E4D8BE;margin:0}
.msr-t{flex:1;min-width:0}
.msr-k{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 6px;font-size:13px;color:#6B6F7B}
.msr-k span:first-child{font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#A32D36;font-size:12px}
.msr-k .dot{width:3px;height:3px;border-radius:50%;background:#B99755}
.msr-lang{font-size:11px;font-weight:700;letter-spacing:.06em;color:#203D78;border:1px solid #D9CBAA;border-radius:3px;padding:0 5px}
.msr-t h3{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:24px;line-height:1.25;margin:0}
.msr-t h3 a{color:#172E5C;text-decoration:none}.msr-t h3 a:hover{color:#A32D36}
.msr-x{font-family:'Source Serif 4',Georgia,serif;font-size:16px;line-height:1.6;color:#3C4A66;margin:8px 0 0}
.msr mark{background:#F6E7B8;color:inherit;border-radius:2px;padding:0 1px}
.msr-img{flex:none;width:168px;aspect-ratio:3/2;border-radius:4px;overflow:hidden;background:#EFE3CB}
.msr-img img{width:100%;height:100%;object-fit:cover;display:block}
.msr-none{padding:24px 0}.msr-none-h{font-family:'Baskervville',Georgia,serif;font-size:28px;color:#172E5C;margin:0 0 8px}.msr-none p{color:#3C4A66;font-size:17px}
.msr-try .msr-h{font-family:'Source Sans 3',sans-serif;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;margin:0 0 12px}
.msr .mnx-pg{justify-content:flex-start}
@media (max-width:640px){.msr .msr-box input,.msr .msr-go{height:52px}.msr .msr-go{flex:1}.msr-item{gap:16px}.msr-img{width:96px}.msr-t h3{font-size:20px}.msr-x{font-size:15px}}
</style>
HTML;
    }
}
