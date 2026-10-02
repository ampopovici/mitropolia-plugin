<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * "Administrare": one gated Romanian page with three tabs (Itinerar, Știri, Galerii foto).
 *
 * News: one article per language (categories news-ro / news-en / news-es), linked as associations.
 * Romanian is required; English and Spanish are created only when they have a title.
 * Galleries: one "All languages" article in the galleries category, titles in fields.
 * Photos: one folder per item, images/news/YYYY/YYYY-MM-DD-slug/. A news item's cover is _cover.jpg
 * (left out of the gallery grid); gallery photos are 001.jpg, 002.jpg... Photos removed in the form
 * are moved to images/news/_trash/, never deleted.
 * Requests: POST ?mitadm=api with the form token. Joomla ACL decides who may create, edit and trash.
 */
final class Admin
{
    private const ROOT = 'images/news';
    private const LIMIT = 80;
    private const MAX_UPLOAD = 25 * 1024 * 1024;
    /** Romanian tag => English tag (the site's tags; Spanish has none yet). */
    private const TAGS = [
        8 => [22, 'Hram'], 9 => [23, 'Vizite pastorale'], 10 => [24, 'Tineri și copii'], 11 => [25, 'Hirotonii'],
        12 => [26, 'Târnosiri și sfințiri'], 15 => [29, 'Congrese și conferințe'], 13 => [27, 'Condoleanțe'],
        14 => [28, 'Olimpiada de religie'], 2 => [16, 'Sfintele Paști'], 3 => [17, 'Nașterea Domnului'],
        4 => [18, 'Postul Mare'], 5 => [19, 'Sfânta Cruce'], 6 => [20, 'Adormirea Maicii Domnului'], 7 => [21, 'Duminica Ortodoxiei'],
    ];
    private const LANGS = ['ro' => 'ro-RO', 'en' => 'en-US', 'es' => 'es-ES'];
    private const GAL_FIELDS = ['gallery-title-ro', 'gallery-title-en', 'gallery-title-es', 'gallery-date', 'gallery-folder', 'gallery-news'];

    /* ------------------------------------------------------------------ helpers */

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function catByAlias(string $alias): int
    {
        static $ids = [];
        if (!isset($ids[$alias])) {
            $db = self::db();
            $ids[$alias] = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__categories')
                ->where('extension = ' . $db->quote('com_content'))->where('alias = :a')->where('published = 1')
                ->bind(':a', $alias), 0, 1)->loadResult();
        }
        return $ids[$alias];
    }

    /** News categories: ['ro' => id, 'en' => id, 'es' => id]. */
    private static function newsCats(): array
    {
        return ['ro' => self::catByAlias('news-ro'), 'en' => self::catByAlias('news-en'), 'es' => self::catByAlias('news-es')];
    }

    private static function galCat(): int
    {
        return self::catByAlias('galleries');
    }

    private static function can(string $what): bool
    {
        $u = Factory::getApplication()->getIdentity();
        if (!$u || $u->guest) {
            return false;
        }
        $cat = $what === 'news' ? self::newsCats()['ro'] : self::galCat();
        return $cat > 0 && (bool) $u->authorise('core.create', 'com_content.category.' . $cat);
    }

    private static function fieldIds(array $names): array
    {
        static $all = null;
        if ($all === null) {
            $db = self::db();
            $all = [];
            foreach ($db->setQuery($db->getQuery(true)->select(['id', 'name'])->from('#__fields')
                ->where('context = ' . $db->quote('com_content.article')))->loadObjectList() as $f) {
                $all[(string) $f->name] = (int) $f->id;
            }
        }
        return array_intersect_key($all, array_flip($names));
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

    private static function writeFields(int $item, array $values): void
    {
        $ids = self::fieldIds(array_keys($values));
        $db = self::db();
        foreach ($values as $name => $value) {
            if (!isset($ids[$name])) {
                continue;
            }
            $db->setQuery($db->getQuery(true)->delete('#__fields_values')->where('field_id = ' . (int) $ids[$name])
                ->where('item_id = ' . $db->quote((string) $item)))->execute();
            if ((string) $value !== '') {
                $o = (object) ['field_id' => (int) $ids[$name], 'item_id' => (string) $item, 'value' => (string) $value];
                $db->insertObject('#__fields_values', $o);
            }
        }
    }

    private static function table()
    {
        return Factory::getApplication()->bootComponent('com_content')->getMVCFactory()->createTable('Article', 'Administrator');
    }

    /** Associated articles (language tag => id) of one article, itself included. */
    private static function assoc(int $id): array
    {
        $db = self::db();
        $key = $db->setQuery($db->getQuery(true)->select($db->quoteName('key'))->from('#__associations')
            ->where('context = ' . $db->quote('com_content.item'))->where('id = ' . $id), 0, 1)->loadResult();
        $out = [];
        $q = $db->getQuery(true)->select(['a.id', 'a.language'])->from($db->quoteName('#__content', 'a'))->where('a.state <> -2');
        if ($key) {
            $q->join('INNER', $db->quoteName('#__associations', 's') . ' ON s.id = a.id AND s.context = ' . $db->quote('com_content.item'))
                ->where('s.' . $db->quoteName('key') . ' = ' . $db->quote($key));
        } else {
            $q->where('a.id = ' . $id);
        }
        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[(string) $r->language] = (int) $r->id;
        }
        return $out;
    }

    private static function writeAssoc(array $ids, array $drop = []): void
    {
        $db = self::db();
        $all = array_values(array_filter(array_map('intval', array_merge(array_values($ids), $drop))));
        if ($all) {
            $db->setQuery($db->getQuery(true)->delete('#__associations')->where('context = ' . $db->quote('com_content.item'))
                ->where('id IN (' . implode(',', $all) . ')'))->execute();
        }
        $ids = array_filter($ids);
        if (count($ids) < 2) {
            return;
        }
        $key = md5(json_encode($ids));
        foreach ($ids as $id) {
            $o = (object) ['id' => (int) $id, 'context' => 'com_content.item', 'key' => $key, 'parent_id' => 0];
            $db->insertObject('#__associations', $o);
        }
    }

    private static function ensureWorkflow(array $cats): void
    {
        try {
            $db = self::db();
            $stage = (int) $db->setQuery('SELECT s.id FROM #__workflow_stages s INNER JOIN #__workflows w ON w.id = s.workflow_id'
                . ' WHERE w.extension = ' . $db->quote('com_content.article') . ' AND w.default = 1 AND s.default = 1', 0, 1)->loadResult();
            $cats = array_filter(array_map('intval', $cats));
            if (!$stage || !$cats) {
                return;
            }
            $db->setQuery('INSERT INTO #__workflow_associations (item_id, stage_id, extension)'
                . ' SELECT a.id, ' . $stage . ', ' . $db->quote('com_content.article') . ' FROM #__content a'
                . ' LEFT JOIN #__workflow_associations wa ON wa.item_id = a.id AND wa.extension = ' . $db->quote('com_content.article')
                . ' WHERE wa.item_id IS NULL AND a.catid IN (' . implode(',', $cats) . ')')->execute();
        } catch (\Throwable $x) {
            Log::add('Admin workflow link: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
        }
    }

    private static function uniqueAlias(int $cat, string $alias, int $self = 0): string
    {
        $db = self::db();
        $alias = $alias !== '' ? mb_substr($alias, 0, 180) : 'stire';
        $try = $alias;
        for ($n = 2; $n < 200; $n++) {
            $hit = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__content')->where('catid = ' . $cat)
                ->where('alias = :a')->where('id <> ' . $self)->bind(':a', $try), 0, 1)->loadResult();
            if (!$hit) {
                return $try;
            }
            $try = $alias . '-' . $n;
        }
        return $alias . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    }

    /** Romanian-safe slug (ș -> s, ț -> t, ă -> a, î/â -> i/a). */
    private static function slug(string $s, int $max = 60): string
    {
        $s = strtr($s, ['ș' => 's', 'ş' => 's', 'Ș' => 'S', 'Ş' => 'S', 'ț' => 't', 'ţ' => 't', 'Ț' => 'T', 'Ţ' => 'T', 'ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I', '„' => '', '”' => '', '“' => '']);
        $s = trim(preg_replace('/-{2,}/', '-', OutputFilter::stringURLSafe($s)), '-');
        if (strlen($s) > $max) {
            $s = rtrim(substr($s, 0, $max), '-');
            $s = preg_replace('/-[^-]*$/', '', $s) ?: $s;
        }
        return $s;
    }

    /** Keeps simple formatting only: paragraphs, line breaks, bold, italic, lists, links. */
    private static function cleanHtml(string $h): string
    {
        $h = preg_replace('#<(script|style|iframe|object|embed|svg|math)[^>]*>.*?</\1>#is', '', $h);
        $h = preg_replace('#<(/?)div\b[^>]*>#i', '<$1p>', $h);
        $h = preg_replace('#<(/?)b\b[^>]*>#i', '<$1strong>', $h);
        $h = preg_replace('#<(/?)i\b[^>]*>#i', '<$1em>', $h);
        $h = strip_tags($h, '<p><br><strong><em><ul><ol><li><a>');
        $h = preg_replace_callback('#<(/?)([a-z]+)([^>]*)>#i', function ($m) {
            $tag = strtolower($m[2]);
            if ($m[1] === '/') {
                return '</' . $tag . '>';
            }
            if ($tag === 'a') {
                if (preg_match('#href\s*=\s*"([^"]*)"|href\s*=\s*\'([^\']*)\'#i', $m[3], $x)) {
                    $href = html_entity_decode(trim($x[1] !== '' ? $x[1] : ($x[2] ?? '')), ENT_QUOTES, 'UTF-8');
                    if (preg_match('#^(https?://|mailto:|/)#i', $href)) {
                        $ext = preg_match('#^https?://#i', $href) && stripos($href, Uri::root()) !== 0;
                        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>';
                    }
                }
                return '<a>';
            }
            return $tag === 'br' ? '<br>' : '<' . $tag . '>';
        }, $h);
        $h = preg_replace('#<a>(.*?)</a>#is', '$1', $h);
        $h = preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#i', '', $h);
        $h = trim($h);
        if ($h !== '' && !preg_match('#^<(p|ul|ol)>#i', $h)) {
            $parts = preg_split('#(?:<br>\s*){2,}|\n{2,}#', $h);
            $h = implode('', array_map(fn ($p) => '<p>' . trim($p) . '</p>', array_filter($parts, fn ($p) => trim(strip_tags($p)) !== '')));
        }
        return $h;
    }

    private static function plainTitle(string $s, int $max = 250): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s))), 0, $max);
    }

    private static function validDate(string $d): bool
    {
        return (bool) (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]));
    }

    private static function relUrl(string $rel): string
    {
        return Uri::root(true) . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    /** A relative image path inside images/, safe to read. */
    private static function safeRel(string $p): string
    {
        $p = trim(explode('#', str_replace('\\', '/', $p), 2)[0], '/ ');
        if ($p === '' || strpos($p, '..') !== false || strpos($p, 'images/') !== 0 || !preg_match('#^[A-Za-z0-9._\-/ ]+$#', $p)) {
            return '';
        }
        return $p;
    }

    /* ------------------------------------------------------------------ page */

    public static function page(array $props): string
    {
        try {
            $user = Factory::getApplication()->getIdentity();
            $here = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);
            if (!$user || $user->guest) {
                return self::login($here) . Itinerary::formCss() . self::css();
            }
            $itin = Itinerary::allowedCats();
            $news = self::can('news');
            $gal = self::can('gal');
            if (!$itin && !$news && !$gal) {
                return '<div class="mif"><div class="mif-card mif-narrow"><h2>Administrare</h2><p>Contul dumneavoastră nu are acces la această pagină. Vă rugăm să luați legătura cu administratorul site-ului.</p>'
                    . self::logout($here) . '</div></div>' . Itinerary::formCss() . self::css();
            }
            $tabs = [];
            if ($itin) {
                $tabs['itin'] = ['Itinerar', 'Itinerarul pastoral', 'Adăugați vizitele. Apar imediat pe site, în română, engleză și spaniolă.'];
            }
            if ($news) {
                $tabs['news'] = ['Știri', 'Știri', 'Scrieți în română. Engleza și spaniola sunt opționale. Știrea apare pe site imediat ce apăsați „Publică”.'];
            }
            if ($gal) {
                $tabs['gal'] = ['Galerii foto', 'Galerii foto', 'O galerie fără text, doar cu titlu și fotografii. O puteți lega de o știre deja publicată.'];
            }
            $first = array_key_first($tabs);
            $out = '<div class="mif madm">'
                . '<div class="mif-hero"><div class="mif-hero-in"><div><h1 id="madm-h1">' . self::e($tabs[$first][1]) . '</h1><p id="madm-sub">' . self::e($tabs[$first][2]) . '</p></div>'
                . '<div class="mif-user"><span>Conectat: <b>' . self::e((string) $user->name) . '</b></span>' . self::logout($here) . '</div></div>'
                . (count($tabs) > 1 ? '<div class="madm-tabs" role="tablist" aria-label="Secțiuni">' . implode('', array_map(fn ($k, $t) => '<button type="button" role="tab" data-t="' . $k . '" aria-selected="' . ($k === $first ? 'true' : 'false') . '" data-h="' . self::e($t[1]) . '" data-s="' . self::e($t[2]) . '">' . self::e($t[0]) . '</button>', array_keys($tabs), $tabs)) . '</div>' : '')
                . '</div>';
            if ($itin) {
                $out .= '<div class="madm-panel" data-p="itin"' . ($first === 'itin' ? '' : ' hidden') . '>' . Itinerary::formHtml($itin, $user, $here, false) . '</div>';
            }
            if ($news || $gal) {
                $out .= '<div class="madm-panel" data-p="ng"' . (in_array($first, ['news', 'gal'], true) ? '' : ' hidden') . '>' . self::panel($news, $gal, $first === 'gal' ? 'gal' : 'news') . '</div>';
            }
            $out .= '</div>';
            return $out . Itinerary::formCss() . self::css() . self::tabsJs();
        } catch (\Throwable $x) {
            Log::add('Admin page: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    private static function login(string $here): string
    {
        $action = Route::_('index.php?option=com_users&task=user.login');
        return '<div class="mif"><form class="mif-card mif-narrow" method="post" action="' . self::e($action) . '">'
            . '<h2>Administrare</h2><p class="mif-hint">Intrați în cont pentru a adăuga sau modifica itinerarul, știrile și galeriile foto.</p>'
            . '<div class="f"><label for="mif-u">Utilizator</label><input type="text" id="mif-u" name="username" autocomplete="username" required></div>'
            . '<div class="f"><label for="mif-p">Parolă</label><input type="password" id="mif-p" name="password" autocomplete="current-password" required></div>'
            . '<input type="hidden" name="return" value="' . self::e(base64_encode($here)) . '">'
            . '<input type="hidden" name="' . Session::getFormToken() . '" value="1">'
            . '<button class="btn" type="submit">Intră în cont</button></form></div>';
    }

    private static function logout(string $here): string
    {
        $action = Route::_('index.php?option=com_users&task=user.logout');
        return '<form method="post" action="' . self::e($action) . '" class="mif-out">'
            . '<input type="hidden" name="return" value="' . self::e(base64_encode($here)) . '">'
            . '<input type="hidden" name="' . Session::getFormToken() . '" value="1">'
            . '<button type="submit">Ieșire</button></form>';
    }

    /* ------------------------------------------------------------------ lists */

    private static function newsList(int $only = 0): array
    {
        $cat = self::newsCats()['ro'];
        if (!$cat) {
            return [];
        }
        $db = self::db();
        $rows = $db->setQuery($db->getQuery(true)->select(['a.id', 'a.title', 'a.publish_up', 'a.images', 'a.created_by', 'u.name AS uname'])
            ->from($db->quoteName('#__content', 'a'))->join('LEFT', $db->quoteName('#__users', 'u') . ' ON u.id = a.created_by')
            ->where('a.catid = ' . $cat)->where('a.state IN (0,1)')->where($only ? 'a.id = ' . $only : '1 = 1')->order('a.publish_up DESC, a.id DESC'), 0, self::LIMIT)->loadObjectList();
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $langs = [];
        if ($ids) {
            $q = 'SELECT s.id AS ro, c.language FROM #__associations s INNER JOIN #__associations t ON t.' . $db->quoteName('key') . ' = s.' . $db->quoteName('key')
                . ' AND t.context = s.context INNER JOIN #__content c ON c.id = t.id AND c.state IN (0,1)'
                . ' WHERE s.context = ' . $db->quote('com_content.item') . ' AND s.id IN (' . implode(',', $ids) . ')';
            foreach ($db->setQuery($q)->loadObjectList() as $r) {
                $langs[(int) $r->ro][substr((string) $r->language, 0, 2)] = 1;
            }
        }
        $u = Factory::getApplication()->getIdentity();
        return array_map(function ($r) use ($langs, $u) {
            $im = json_decode((string) $r->images, true) ?: [];
            $src = self::safeRel((string) ($im['image_intro'] ?? ($im['image_fulltext'] ?? '')));
            $asset = 'com_content.article.' . (int) $r->id;
            $own = (int) $r->created_by === (int) $u->id;
            return [
                'id' => (int) $r->id, 'dt' => substr((string) $r->publish_up, 0, 10), 'title' => (string) $r->title,
                'th' => $src !== '' && is_file(JPATH_ROOT . '/' . $src) ? News::thumbUrl($src) : '',
                'langs' => array_keys(($langs[(int) $r->id] ?? []) + ['ro' => 1]), 'by' => (string) ($r->uname ?? ''),
                'edit' => $u->authorise('core.edit', $asset) || ($own && $u->authorise('core.edit.own', $asset)),
                'del' => (bool) $u->authorise('core.edit.state', $asset),
            ];
        }, $rows);
    }

    private static function galList(int $only = 0): array
    {
        $cat = self::galCat();
        if (!$cat) {
            return [];
        }
        $db = self::db();
        $rows = $db->setQuery($db->getQuery(true)->select(['a.id', 'a.title', 'a.publish_up', 'a.images', 'a.created_by', 'u.name AS uname'])
            ->from($db->quoteName('#__content', 'a'))->join('LEFT', $db->quoteName('#__users', 'u') . ' ON u.id = a.created_by')
            ->where('a.catid = ' . $cat)->where('a.state IN (0,1)')->where($only ? 'a.id = ' . $only : '1 = 1')->order('a.publish_up DESC, a.id DESC'), 0, self::LIMIT)->loadObjectList();
        $f = self::fieldValues(array_map(fn ($r) => (int) $r->id, $rows), self::GAL_FIELDS);
        $u = Factory::getApplication()->getIdentity();
        return array_map(function ($r) use ($f, $u) {
            $v = $f[(int) $r->id] ?? [];
            $im = json_decode((string) $r->images, true) ?: [];
            $src = self::safeRel((string) ($im['image_intro'] ?? ''));
            $folder = self::safeRel((string) ($v['gallery-folder'] ?? ''));
            $asset = 'com_content.article.' . (int) $r->id;
            $own = (int) $r->created_by === (int) $u->id;
            return [
                'id' => (int) $r->id, 'dt' => substr((string) ($v['gallery-date'] ?? $r->publish_up), 0, 10),
                'title' => (string) (($v['gallery-title-ro'] ?? '') !== '' ? $v['gallery-title-ro'] : $r->title),
                'th' => $src !== '' && is_file(JPATH_ROOT . '/' . $src) ? News::thumbUrl($src) : '',
                'n' => $folder !== '' ? count(News::folderPhotos($folder)) : 0,
                'langs' => array_values(array_filter(['ro', 'en', 'es'], fn ($l) => ($v['gallery-title-' . $l] ?? '') !== '' || $l === 'ro')),
                'by' => (string) ($r->uname ?? ''),
                'edit' => $u->authorise('core.edit', $asset) || ($own && $u->authorise('core.edit.own', $asset)),
                'del' => (bool) $u->authorise('core.edit.state', $asset),
            ];
        }, $rows);
    }

    /* ------------------------------------------------------------------ news + galleries panel */

    private static function panel(bool $news, bool $gal, string $mode): string
    {
        $data = [
            'api'   => Uri::getInstance()->toString(['scheme', 'host', 'port', 'path']) . '?mitadm=api',
            'token' => Session::getFormToken(),
            'today' => Factory::getDate('now', Factory::getApplication()->get('offset', 'UTC'))->format('Y-m-d', true),
            'can'   => ['news' => $news, 'gal' => $gal],
            'mode'  => $mode,
            'tags'  => array_map(fn ($k, $v) => [$k, $v[1]], array_keys(self::TAGS), self::TAGS),
            'news'  => $news || $gal ? self::newsList() : [],
            'gals'  => $gal ? self::galList() : [],
            'limit' => self::LIMIT,
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<div class="mif-wrap mng">' . <<<'HTML'
<form class="mif-card" id="mn-frm" novalidate>
 <div class="ok" id="mn-okmsg" hidden></div>
 <h2 id="mn-ftitle">Știre nouă</h2>
 <div class="row">
  <div class="f"><label for="mn-dt">Data</label><input type="date" id="mn-dt"></div>
  <div class="f" id="mn-lnkBox" hidden><label for="mn-lnk">Legată de știrea <span class="opt">(opțional)</span></label><select id="mn-lnk"></select></div>
 </div>
 <div class="f"><span class="lab" id="mn-txtLab">Titlul și textul</span>
  <div class="mng-ltabs" role="tablist" aria-label="Limba">
   <button type="button" role="tab" data-l="ro" aria-selected="true"><i class="dot" id="mn-dro"></i>Română</button>
   <button type="button" role="tab" data-l="en" aria-selected="false"><i class="dot" id="mn-den"></i>English <span class="o">(opț.)</span></button>
   <button type="button" role="tab" data-l="es" aria-selected="false"><i class="dot" id="mn-des"></i>Español <span class="o">(opț.)</span></button>
  </div>
  <div class="mng-lbox">
   <div class="f"><label for="mn-ti">Titlu</label><input type="text" id="mn-ti" maxlength="250"></div>
   <div class="f" id="mn-bodyBox" style="margin-bottom:0"><span class="lab">Text</span>
    <div class="mng-tb" role="toolbar" aria-label="Formatare"><button type="button" data-c="bold" title="Îngroșat"><b>B</b></button><button type="button" data-c="italic" title="Cursiv"><i>I</i></button><button type="button" data-c="insertUnorderedList" title="Listă">• —</button><button type="button" data-c="link" title="Legătură (selectați întâi textul)">Legătură</button></div>
    <div class="mng-ed" id="mn-ed" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Textul"></div>
    <div class="mng-link" id="mn-linkBox" hidden><input type="text" id="mn-linkUrl" placeholder="https://…" aria-label="Adresa legăturii"><button type="button" class="btn sec" id="mn-linkOk">Adaugă</button><button type="button" class="link" id="mn-linkNo">Renunță</button></div>
   </div>
  </div>
 </div>
 <div class="f"><span class="lab">Fotografii</span>
  <p class="hint" id="mn-phHint"></p>
  <label class="mng-drop" id="mn-drop"><input type="file" id="mn-files" accept="image/*" multiple><b>Alegeți fotografiile</b><span>sau trageți-le aici · se micșorează automat înainte de trimitere</span></label>
  <div class="mng-grid" id="mn-grid"></div>
  <div class="mng-stat" id="mn-stat"></div>
 </div>
 <div class="f" id="mn-tagBox"><span class="lab">Etichete <span class="opt">(opțional)</span></span><div class="chips" id="mn-tags"></div></div>
 <div class="preview">
  <div class="pvh"><span class="lab">Cum va apărea pe site</span>
   <div class="mng-pt" role="group" aria-label="Limba"><button type="button" data-l="ro" aria-pressed="true">RO</button><button type="button" data-l="en" aria-pressed="false">EN</button><button type="button" data-l="es" aria-pressed="false">ES</button></div></div>
  <div id="mn-pv"></div>
  <p class="note-pv" id="mn-fb"></p>
 </div>
 <div class="mng-prog" id="mn-prog" hidden><i id="mn-progBar"></i></div>
 <div class="actions"><button class="btn" type="submit" id="mn-save">Publică</button><button class="btn sec" type="button" id="mn-cancel" hidden>Renunță</button><span class="err" id="mn-err" role="alert"></span></div>
</form>
<aside class="mif-card side">
 <h2 id="mn-ltitle">Știri publicate</h2>
 <p class="hint">Ștergerea scoate știrea de pe site în toate limbile. Fotografiile rămân păstrate pe server.</p>
 <div class="list" id="mn-list"></div>
 <p class="hint" id="mn-more" hidden></p>
</aside>
</div>
HTML
            . '<script type="application/json" id="mn-data">' . $json . '</script>' . self::panelJs();
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
/* Administrare: tabs + news/galleries panel (Mitropolia plugin). Uses the itinerary manager's tokens (.mif). */
.madm .mif-hero-in{padding-bottom:20px}
.madm-tabs{display:flex;gap:4px;overflow-x:auto;margin-bottom:-1px}
.madm-tabs button{border:1px solid var(--line);border-bottom:0;background:#F7EEDC;font:inherit;font-weight:700;font-size:16px;color:var(--blue);padding:11px 22px;border-radius:6px 6px 0 0;cursor:pointer;min-height:46px;white-space:nowrap}
.madm-tabs button[aria-selected=true]{background:#fff;color:var(--navy)}
.madm-tabs button:focus-visible,.mng button:focus-visible{outline:2px solid #9DB0D6;outline-offset:1px}
.mng-ltabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.mng-ltabs button{display:flex;align-items:center;gap:8px;border:1px solid var(--line2);background:#fff;border-radius:6px;font:inherit;font-size:15px;font-weight:600;padding:8px 14px;color:var(--blue);cursor:pointer;min-height:42px}
.mng-ltabs button[aria-selected=true]{background:var(--navy);color:#fff;border-color:var(--navy)}
.mng-ltabs .o{font-weight:400;opacity:.75}
.mng .dot{width:9px;height:9px;border-radius:50%;background:var(--line2);flex:none}
.mng .dot.on{background:#3E8E54}
.mng-lbox{border:1px solid var(--line);border-radius:8px;padding:16px;background:#FCFAF5}
.mng-tb{display:flex;gap:4px;flex-wrap:wrap;border:1px solid var(--line2);border-bottom:0;border-radius:6px 6px 0 0;background:#fff;padding:4px}
.mng-tb button{border:0;background:none;font:inherit;font-size:15px;min-width:38px;min-height:36px;padding:0 8px;border-radius:4px;color:var(--navy);cursor:pointer}
.mng-tb button:hover{background:#EEF2F9}
.mng-ed{min-height:200px;max-height:60vh;overflow:auto;border:1px solid var(--line2);border-radius:0 0 6px 6px;padding:12px 14px;background:#fff;font-size:17px;line-height:1.55;overflow-wrap:anywhere}
.mng-ed:focus{outline:2px solid #9DB0D6;outline-offset:1px}
.mng-ed:empty::before{content:attr(data-ph);color:var(--soft)}
.mng-ed p{margin:0 0 10px}.mng-ed img{max-width:100%;height:auto}
.mng-link{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px}
.mng-link input{flex:1 1 220px}
.mng-link .btn{min-height:44px;padding:8px 16px;font-size:15px}
.mng-drop{position:relative;display:block;border:2px dashed var(--line2);border-radius:8px;background:#FCFAF5;padding:22px 16px;text-align:center;cursor:pointer}
.mng-drop:hover,.mng-drop.over{border-color:var(--blue);background:#EEF2F9}
.mng-drop b{display:block;color:var(--navy);font-size:17px}
.mng-drop span{font-size:14px;color:var(--soft)}
.mng-drop input{position:absolute;width:1px;height:1px;opacity:0;left:0;top:0}
.mng-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px;margin-top:12px}
.mng-ph{position:relative;border-radius:6px;overflow:hidden;background:var(--sand);aspect-ratio:4/3;max-width:100%;border:2px solid transparent}
.mng-ph.cover{border-color:var(--red)}
.mng-ph img{width:100%;height:100%;object-fit:cover;display:block}
.mng-ph .badge{position:absolute;left:6px;top:6px;background:var(--red);color:#fff;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:3px;padding:2px 6px}
.mng-ph .acts{position:absolute;inset:auto 0 0 0;display:flex;justify-content:space-between;gap:4px;background:linear-gradient(transparent,rgba(16,24,44,.75));padding:14px 4px 4px}
.mng-ph .acts button{border:0;background:rgba(255,255,255,.92);color:var(--navy);font:inherit;font-size:12px;font-weight:700;border-radius:3px;padding:3px 7px;cursor:pointer}
.mng-ph .acts button.x{color:var(--red)}
.mng-ph .busy{position:absolute;inset:0;display:grid;place-items:center;text-align:center;padding:6px;background:rgba(246,241,231,.85);font-size:13px;font-weight:700;color:var(--navy)}
.mng-ph .busy.bad{color:var(--red)}
.mng-stat{display:flex;gap:14px;flex-wrap:wrap;font-size:14px;color:var(--muted);margin-top:10px;font-variant-numeric:tabular-nums}
.mng-stat b{color:#24572F}
.mng-pt{display:flex;gap:6px;margin-bottom:10px}
.mng-pt button{border:1px solid var(--line2);background:#fff;border-radius:4px;font:inherit;font-size:13px;font-weight:700;letter-spacing:.08em;padding:5px 10px;color:var(--blue);cursor:pointer}
.mng-pt button[aria-pressed=true]{background:var(--navy);color:#fff;border-color:var(--navy)}
.mng-card{display:grid;grid-template-columns:200px minmax(0,1fr);gap:18px;border:1px solid var(--line);border-radius:6px;overflow:hidden;background:#fff}
.mng-card .im{background:var(--sand);min-height:140px}
.mng-card .im img{width:100%;height:100%;object-fit:cover;display:block}
.mng-card .tx{padding:14px 16px 14px 0;min-width:0}
.mng-card .dt{font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--red)}
.mng-card h3{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:22px;line-height:1.2;color:var(--navy);margin:4px 0 6px;overflow-wrap:anywhere}
.mng-card p{margin:0;color:var(--muted);font-size:15px}
.mng-card .gl{margin-top:8px;font-size:14px;font-weight:600;color:var(--blue)}
.mng-prog{height:8px;border-radius:4px;background:var(--line);overflow:hidden;margin-top:14px}
.mng-prog i{display:block;height:100%;width:0;background:var(--blue);transition:width .2s}
.mng .nit{background:#fff;border:1px solid var(--line);border-radius:6px;padding:10px;display:grid;grid-template-columns:72px minmax(0,1fr);gap:12px}
.mng .nit .th{border-radius:4px;overflow:hidden;aspect-ratio:1;background:var(--sand)}
.mng .nit .th img{width:100%;height:100%;object-fit:cover;display:block}
.mng .nit b{display:block;color:var(--navy);font-size:16px;line-height:1.3;overflow-wrap:anywhere}
.mng .nit .meta{font-size:13px;color:var(--muted);margin-top:2px}
.mng .langs{display:inline-flex;gap:4px;margin-left:6px;vertical-align:1px}
.mng .langs span{font-size:10px;font-weight:700;letter-spacing:.06em;border-radius:3px;padding:1px 5px;border:1px solid #C9D3E6;color:var(--blue);background:#EEF2F9}
.mng .langs span.no{color:var(--soft);background:none;border-style:dashed;text-decoration:line-through}
.mng .nit .ia{display:flex;gap:16px;margin-top:4px}
.mng .nit .ia button{border:0;background:none;font:inherit;font-size:14px;font-weight:700;color:var(--blue);padding:4px 0;cursor:pointer}
.mng .nit .ia button.del{color:var(--red)}
@media (prefers-reduced-motion:reduce){.mng-prog i{transition:none}}
@media (max-width:560px){.madm-tabs button{padding:11px 14px;font-size:15px;flex:1}.mng-card{grid-template-columns:minmax(0,1fr)}.mng-card .im{aspect-ratio:16/9;min-height:0}.mng-card .tx{padding:0 14px 14px}}
</style>
HTML;
    }

    private static function tabsJs(): string
    {
        return <<<'HTML'
<script>
(function(){var tabs=[].slice.call(document.querySelectorAll('.madm-tabs button'));if(!tabs.length)return;
function go(t){tabs.forEach(function(b){b.setAttribute('aria-selected',String(b.dataset.t===t));if(b.dataset.t===t){document.getElementById('madm-h1').textContent=b.dataset.h;document.getElementById('madm-sub').textContent=b.dataset.s;}});
 [].forEach.call(document.querySelectorAll('.madm-panel'),function(p){p.hidden=t==='itin'?p.dataset.p!=='itin':p.dataset.p!=='ng';});
 if(t!=='itin'&&window.mngMode)window.mngMode(t);try{localStorage.setItem('madm-tab',t);}catch(e){}}
tabs.forEach(function(b){b.addEventListener('click',function(){go(b.dataset.t);});});
var t=null;try{t=localStorage.getItem('madm-tab');}catch(e){}
if(location.hash==='#stiri')t='news';if(location.hash==='#galerii')t='gal';if(location.hash==='#itinerar')t='itin';
if(t&&tabs.some(function(b){return b.dataset.t===t;}))go(t);})();
</script>
HTML;
    }

    private static function panelJs(): string
    {
        return <<<'HTML'
<script>
(function(){
const D=JSON.parse(document.getElementById('mn-data').textContent);
const $=id=>document.getElementById('mn-'+id),esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const ROOT=$('frm').closest('.mng');
const MON={ro:["ianuarie","februarie","martie","aprilie","mai","iunie","iulie","august","septembrie","octombrie","noiembrie","decembrie"],en:["January","February","March","April","May","June","July","August","September","October","November","December"],es:["enero","febrero","marzo","abril","mayo","junio","julio","agosto","septiembre","octubre","noviembre","diciembre"]};
const PH={news:{ro:['De ex. Hramul Parohiei „Sfântul Nicolae” din Shrewsbury','Scrieți aici textul știrii…'],en:['e.g. Patronal feast of St. Nicholas Parish in Shrewsbury','Write the English text here (optional)…'],es:['p. ej. Fiesta patronal de la parroquia San Nicolás','Escriba aquí el texto en español (opcional)…']},
 gal:{ro:['De ex. Înălțarea Sfintei Cruci la Catedrala din Chicago',''],en:['e.g. Exaltation of the Holy Cross at the Chicago Cathedral (optional)',''],es:['p. ej. Exaltación de la Santa Cruz en Chicago (opcional)','']}};
let MODE=D.can.news?'news':'gal',LANG='ro',PVL='ro',EDIT=0,BATCH='',BUSY=false;
let NEWS=D.news.slice(),GALS=D.gals.slice();
let F=blank();
function blank(){return{dt:D.today,lnk:'',t:{ro:'',en:'',es:''},b:{ro:'',en:'',es:''},bchg:{ro:0,en:0,es:0},tags:[],photos:[],pchg:0};}
function fmtDate(iso,l){if(!iso)return'';const[y,m,d]=iso.split('-').map(Number);return l==='en'?`${MON.en[m-1]} ${d}, ${y}`:`${d} ${MON[l][m-1]} ${y}`;}
const plain=h=>{const x=document.createElement('div');x.innerHTML=h;return x.textContent.replace(/\s+/g,' ').trim();};
function rnd(){const a=new Uint8Array(8);crypto.getRandomValues(a);return [...a].map(x=>x.toString(16).padStart(2,'0')).join('');}
async function post(fields,file){const fd=new FormData();Object.entries(fields).forEach(([k,v])=>fd.append(k,v==null?'':v));if(file)fd.append('photo',file,'photo.jpg');fd.append(D.token,'1');
 const r=await fetch(D.api,{method:'POST',body:fd,credentials:'same-origin'});let j;try{j=await r.json();}catch(e){throw new Error('Răspuns neașteptat de la server ('+r.status+').');}if(!j.ok)throw new Error(j.error||'Eroare.');return j;}
/* ---------- mode ---------- */
function setMode(m){if(!D.can[m])return;MODE=m;
 $('bodyBox').hidden=m!=='news';$('tagBox').hidden=m!=='news';$('lnkBox').hidden=m!=='gal';$('txtLab').textContent=m==='news'?'Titlul și textul':'Titlul galeriei';
 $('phHint').textContent=m==='news'?'Prima fotografie este imaginea principală a știrii. Celelalte formează galeria de sub text. Le puteți alege direct din telefon.':'Prima fotografie apare pe coperta galeriei. Puteți încărca zeci de fotografii odată.';
 $('ltitle').textContent=m==='news'?'Știri publicate':'Galerii publicate';
 $('lnk').innerHTML='<option value="">Fără legătură</option>'+NEWS.map(n=>`<option value="${n.id}">${esc(fmtDate(n.dt,'ro'))} · ${esc(n.title)}</option>`).join('');
 reset();renderList();}
window.mngMode=t=>{if(t!==MODE&&!BUSY)setMode(t);};
/* ---------- languages ---------- */
ROOT.querySelectorAll('.mng-ltabs button').forEach(b=>b.onclick=()=>{store();LANG=b.dataset.l;ROOT.querySelectorAll('.mng-ltabs button').forEach(x=>x.setAttribute('aria-selected',String(x===b)));loadLang();setPv(LANG);});
function loadLang(){$('ti').value=F.t[LANG];$('ti').placeholder=PH[MODE][LANG][0];$('ed').innerHTML=F.b[LANG];$('ed').dataset.ph=PH[MODE][LANG][1];}
function store(){F.t[LANG]=$('ti').value.trim();if(MODE==='news')F.b[LANG]=plain($('ed').innerHTML)?$('ed').innerHTML:'';}
function dots(){['ro','en','es'].forEach(l=>$('d'+l).classList.toggle('on',!!F.t[l]));}
$('ti').oninput=()=>{store();dots();upd();};
$('ed').oninput=()=>{F.bchg[LANG]=1;store();upd();};
$('ed').addEventListener('paste',e=>{const t=(e.clipboardData||window.clipboardData).getData('text/plain');if(t){e.preventDefault();document.execCommand('insertText',false,t);}});
let savedRange=null;
ROOT.querySelectorAll('.mng-tb button').forEach(b=>b.onmousedown=e=>{e.preventDefault();const c=b.dataset.c;
 if(c==='link'){const s=window.getSelection();if(!s||s.isCollapsed||!$('ed').contains(s.anchorNode)){$('err').textContent='Selectați întâi cuvintele care devin legătură.';return;}$('err').textContent='';savedRange=s.getRangeAt(0).cloneRange();$('linkBox').hidden=false;$('linkUrl').value='https://';$('linkUrl').focus();return;}
 document.execCommand(c);F.bchg[LANG]=1;store();upd();});
$('linkOk').onclick=()=>{const u=$('linkUrl').value.trim();if(!/^https?:\/\/\S+\.\S+/.test(u)){$('linkUrl').focus();return;}const s=window.getSelection();s.removeAllRanges();if(savedRange)s.addRange(savedRange);document.execCommand('createLink',false,u);$('linkBox').hidden=true;F.bchg[LANG]=1;store();upd();};
$('linkNo').onclick=()=>{$('linkBox').hidden=true;};
$('dt').oninput=()=>{F.dt=$('dt').value;upd();};$('lnk').onchange=()=>{F.lnk=$('lnk').value;};
/* ---------- tags ---------- */
$('tags').innerHTML=D.tags.map(t=>`<button type="button" class="chip" data-i="${t[0]}" aria-pressed="false">${esc(t[1])}</button>`).join('');
$('tags').onclick=e=>{const b=e.target.closest('.chip');if(!b)return;const i=+b.dataset.i;F.tags=F.tags.includes(i)?F.tags.filter(x=>x!==i):[...F.tags,i];paintTags();};
function paintTags(){$('tags').querySelectorAll('.chip').forEach(b=>b.setAttribute('aria-pressed',String(F.tags.includes(+b.dataset.i))));}
/* ---------- photos: resized in the browser, sent one by one ---------- */
const MB=n=>(n/1048576).toFixed(1).replace('.',',')+' MB';
async function shrink(file){const url=URL.createObjectURL(file);try{const img=new Image();img.decoding='async';await new Promise((ok,no)=>{img.onload=ok;img.onerror=no;img.src=url;});
 const max=2000,s=Math.min(1,max/Math.max(img.naturalWidth,img.naturalHeight));const c=document.createElement('canvas');c.width=Math.max(1,Math.round(img.naturalWidth*s));c.height=Math.max(1,Math.round(img.naturalHeight*s));
 const g=c.getContext('2d');g.fillStyle='#fff';g.fillRect(0,0,c.width,c.height);g.drawImage(img,0,0,c.width,c.height);
 const blob=await new Promise(r=>c.toBlob(r,'image/jpeg',.84));if(!blob)throw 0;return{blob,src:URL.createObjectURL(blob),before:file.size,after:blob.size};}finally{URL.revokeObjectURL(url);}}
async function addFiles(list){const files=[...list].filter(f=>/^image\//.test(f.type)||/\.(jpe?g|png|webp|heic|heif)$/i.test(f.name));if(!files.length)return;
 const items=files.map(f=>({busy:true,name:f.name}));F.photos.push(...items);F.pchg=1;paintGrid();
 for(let i=0;i<files.length;i++){const it=items[i];try{Object.assign(it,await shrink(files[i]));it.busy=false;}catch(e){it.busy=false;it.bad=/\.(heic|heif)$/i.test(files[i].name)?'Format HEIC: alegeți „Cel mai compatibil” în setările camerei':'Fișierul nu poate fi citit';}paintGrid();}upd();}
$('files').onchange=e=>{addFiles(e.target.files);e.target.value='';};
['dragenter','dragover'].forEach(ev=>$('drop').addEventListener(ev,e=>{e.preventDefault();$('drop').classList.add('over');}));
['dragleave','drop'].forEach(ev=>$('drop').addEventListener(ev,e=>{e.preventDefault();$('drop').classList.remove('over');}));
$('drop').addEventListener('drop',e=>addFiles(e.dataTransfer.files));
function paintGrid(){const cover=MODE==='news'?'Principală':'Copertă';
 $('grid').innerHTML=F.photos.map((p,i)=>`<div class="mng-ph${i===0?' cover':''}" data-i="${i}">${p.src?`<img src="${esc(p.src)}" alt="">`:''}${p.busy?'<div class="busy">Se micșorează…</div>':p.bad?`<div class="busy bad">${esc(p.bad)}</div>`:''}${i===0&&!p.bad?`<span class="badge">${cover}</span>`:''}${p.busy?'':`<div class="acts">${i===0||p.bad?'<span></span>':'<button type="button" class="cv">Fă principală</button>'}<button type="button" class="x" aria-label="Scoate fotografia">✕</button></div>`}</div>`).join('');
 const nw=F.photos.filter(p=>p.blob),b=nw.reduce((s,p)=>s+p.before,0),a=nw.reduce((s,p)=>s+p.after,0),ok=F.photos.filter(p=>!p.bad).length;
 $('stat').innerHTML=ok?`<span>${ok} ${ok===1?'fotografie':'fotografii'}</span>${nw.length?`<span>${nw.length} noi: ${MB(b)} → <b>${MB(a)}</b> după micșorare</span>`:''}`:'';}
$('grid').onclick=e=>{const ph=e.target.closest('.mng-ph');if(!ph||BUSY)return;const i=+ph.dataset.i;
 if(e.target.classList.contains('x'))F.photos.splice(i,1);else if(e.target.classList.contains('cv')){const[p]=F.photos.splice(i,1);F.photos.unshift(p);}else return;F.pchg=1;paintGrid();upd();};
/* ---------- preview ---------- */
ROOT.querySelectorAll('.mng-pt button').forEach(b=>b.onclick=()=>setPv(b.dataset.l));
function setPv(l){PVL=l;ROOT.querySelectorAll('.mng-pt button').forEach(x=>x.setAttribute('aria-pressed',String(x.dataset.l===l)));upd();}
const GL={ro:n=>`Galerie foto · ${n} ${n===1?'fotografie':(n%100>=20||n%100===0?'de fotografii':'fotografii')}`,en:n=>`Photo gallery · ${n} photo${n===1?'':'s'}`,es:n=>`Galería de fotos · ${n} foto${n===1?'':'s'}`};
function upd(){const l=PVL;let use=l,note='';
 if(!F.t[l]&&l!=='ro'){if(MODE==='news'&&l==='en'){$('pv').innerHTML='';$('fb').textContent='Fără titlu în engleză, știrea nu apare pe site-ul în engleză.';return;}
  use=F.t.en&&l==='es'?'en':'ro';note=l==='es'?(MODE==='news'?(F.t.en?'Fără text în spaniolă, pagina în spaniolă arată versiunea în engleză.':'Fără text în spaniolă sau engleză, pagina în spaniolă arată versiunea în română.'):'Fără titlu în spaniolă, se folosește titlul în '+(use==='en'?'engleză.':'română.')):'Fără titlu în engleză, se folosește titlul în română.';}
 const ok=F.photos.filter(p=>!p.bad&&!p.busy),cov=ok[0],n=MODE==='news'?Math.max(ok.length-1,0):ok.length,t=F.t[use],bt=MODE==='news'?plain(F.b[use]||''):'';
 $('pv').innerHTML=`<div class="mng-card"><div class="im">${cov&&cov.src?`<img src="${esc(cov.src)}" alt="">`:''}</div><div class="tx"><div class="dt">${esc(fmtDate(F.dt,use))}</div><h3>${t?esc(t):'<span style="opacity:.45">'+(MODE==='news'?'Titlul știrii':'Titlul galeriei')+'</span>'}</h3>${bt?`<p>${esc(bt.slice(0,160))}${bt.length>160?'…':''}</p>`:''}${n?`<div class="gl">${GL[use](n)}</div>`:''}</div></div>`;
 $('fb').textContent=note;}
/* ---------- form ---------- */
function reset(){F=blank();EDIT=0;BATCH='';$('dt').value=F.dt;$('lnk').value='';
 LANG='ro';ROOT.querySelectorAll('.mng-ltabs button').forEach(x=>x.setAttribute('aria-selected',String(x.dataset.l==='ro')));loadLang();paintTags();paintGrid();dots();
 $('ftitle').textContent=MODE==='news'?'Știre nouă':'Galerie nouă';$('save').textContent='Publică';$('cancel').hidden=true;$('err').textContent='';$('linkBox').hidden=true;setPv('ro');}
$('cancel').onclick=()=>{if(!BUSY)reset();};
function check(){const m=[];if(!F.dt)m.push('data');if(!F.t.ro)m.push('titlul în română');
 if(MODE==='news'){if(!plain(F.b.ro))m.push('textul în română');['en','es'].forEach(l=>{if(F.t[l]&&!plain(F.b[l]))m.push('textul în '+(l==='en'?'engleză':'spaniolă')+' (sau ștergeți titlul)');if(!F.t[l]&&plain(F.b[l]))m.push('titlul în '+(l==='en'?'engleză':'spaniolă'));});}
 if(MODE==='gal'&&!F.photos.some(p=>!p.bad))m.push('fotografiile');if(F.photos.some(p=>p.busy))m.push('așteptați să se termine micșorarea fotografiilor');return m;}
$('frm').onsubmit=async e=>{e.preventDefault();if(BUSY)return;store();const miss=check();if(miss.length){$('err').textContent='Lipsește: '+miss.join(', ')+'.';return;}
 $('err').textContent='';BUSY=true;$('save').disabled=true;$('cancel').disabled=true;
 const ph=F.photos.filter(p=>!p.bad),up=ph.filter(p=>p.blob&&!p.up);if(!BATCH)BATCH=rnd();
 try{$('prog').hidden=false;let done=0;const tot=up.length+1;const bar=()=>$('progBar').style.width=(done/tot*100)+'%';bar();
  for(const p of up){$('save').textContent=`Se trimit fotografiile… ${done+1} din ${up.length}`;
   let tries=0;for(;;){try{const j=await post({action:'upload',batch:BATCH},p.blob);p.up=j.name;break;}catch(x){if(++tries>=3)throw x;await new Promise(r=>setTimeout(r,1500*tries));}}done++;bar();}
  $('save').textContent='Se publică…';
  const photos=ph.map(p=>p.up?{k:'u',n:p.up}:{k:p.k,n:p.n});
  const f={action:MODE==='news'?'news_save':'gal_save',id:EDIT||'',dt:F.dt,batch:BATCH,pchg:F.pchg?1:0,photos:JSON.stringify(photos),lnk:F.lnk||''};
  ['ro','en','es'].forEach(l=>{f['t_'+l]=F.t[l];f['b_'+l]=F.b[l];f['bchg_'+l]=F.bchg[l]||!EDIT?1:0;});f.tags=F.tags.join(',');
  const j=await post(f);done=tot;bar();
  const arr=MODE==='news'?NEWS:GALS,was=EDIT;const k=arr.findIndex(x=>x.id===j.item.id);if(k>=0)arr.splice(k,1);arr.unshift(j.item);arr.sort((a,b)=>b.dt.localeCompare(a.dt)||b.id-a.id);
  F.photos.forEach(p=>{if(p.src&&p.blob)URL.revokeObjectURL(p.src);});reset();renderList();
  $('okmsg').innerHTML=esc((was?'Modificarea a fost salvată':(MODE==='news'?'Știrea a fost publicată':'Galeria a fost publicată'))+' ('+j.item.langs.map(x=>x.toUpperCase()).join(', ')+').')+(j.url?` <a href="${esc(j.url)}" target="_blank" rel="noopener">Vedeți pe site</a>`:'');
  $('okmsg').hidden=false;setTimeout(()=>$('okmsg').hidden=true,8000);$('frm').scrollIntoView({behavior:'smooth'});
 }catch(x){$('err').textContent=x.message+' Fotografiile deja trimise nu se pierd; apăsați din nou „'+(EDIT?'Salvează modificările':'Publică')+'”.';}
 finally{BUSY=false;$('save').disabled=false;$('cancel').disabled=false;$('prog').hidden=true;$('progBar').style.width='0';if($('save').textContent.indexOf('Se ')===0)$('save').textContent=EDIT?'Salvează modificările':'Publică';}};
/* ---------- list ---------- */
function renderList(){const arr=MODE==='news'?NEWS:GALS;
 $('list').innerHTML=arr.length?arr.map(n=>`<div class="nit" data-id="${n.id}"><div class="th">${n.th?`<img src="${esc(n.th)}" alt="" loading="lazy">`:''}</div><div><b>${esc(n.title)}</b><div class="meta">${esc(fmtDate(n.dt,'ro'))}<span class="langs">${['ro','en','es'].map(l=>`<span class="${n.langs.includes(l)?'':'no'}">${l.toUpperCase()}</span>`).join('')}</span></div><div class="meta">${MODE==='gal'?n.n+' fotografii · ':''}${n.by?'Adăugat de '+esc(n.by):''}</div><div class="ia">${n.edit?'<button type="button" class="ed">Editează</button>':''}${n.del?'<button type="button" class="del">Șterge</button>':''}</div></div></div>`).join(''):'<p class="empty">Nimic publicat încă.</p>';
 $('more').hidden=arr.length<D.limit;$('more').textContent='Se arată cele mai recente '+D.limit+'. Pe cele mai vechi le găsiți în administrarea site-ului.';}
$('list').onclick=async e=>{const it=e.target.closest('.nit');if(!it||BUSY)return;const id=+it.dataset.id,arr=MODE==='news'?NEWS:GALS;
 if(e.target.classList.contains('ed')){e.target.disabled=true;e.target.textContent='Se încarcă…';
  try{const j=await post({action:MODE==='news'?'news_get':'gal_get',id});reset();EDIT=id;const d=j.item;
   F={dt:d.dt,lnk:d.lnk?String(d.lnk):'',t:d.t,b:d.b||{ro:'',en:'',es:''},bchg:{ro:0,en:0,es:0},tags:d.tags||[],photos:d.photos.map(p=>({k:p.k,n:p.n,src:p.src})),pchg:0};
   $('dt').value=F.dt;$('lnk').value=F.lnk;loadLang();paintTags();paintGrid();dots();upd();
   $('ftitle').textContent=MODE==='news'?'Modificați știrea':'Modificați galeria';$('save').textContent='Salvează modificările';$('cancel').hidden=false;$('frm').scrollIntoView({behavior:'smooth'});
  }catch(x){$('err').textContent=x.message;}finally{e.target.disabled=false;e.target.textContent='Editează';}}
 else if(e.target.classList.contains('del')){if(it.querySelector('.confirm'))return;const c=document.createElement('div');c.className='confirm';c.innerHTML='<span>Ștergeți de pe site, în toate limbile?</span><button type="button" class="yes">Da, șterge</button><button type="button" class="no">Renunță</button>';it.appendChild(c);}
 else if(e.target.classList.contains('yes')){e.target.disabled=true;try{await post({action:MODE==='news'?'news_trash':'gal_trash',id});const k=arr.findIndex(x=>x.id===id);if(k>=0)arr.splice(k,1);if(EDIT===id)reset();renderList();}catch(x){e.target.disabled=false;e.target.closest('.confirm').querySelector('span').textContent=x.message;}}
 else if(e.target.classList.contains('no'))e.target.closest('.confirm').remove();};
window.addEventListener('beforeunload',e=>{if(BUSY||(!EDIT&&(F.t.ro||F.photos.length))){e.preventDefault();e.returnValue='';}});
setMode(D.mode&&D.can[D.mode]?D.mode:MODE);
})();
</script>
HTML;
    }

    /* ------------------------------------------------------------------ API */

    public static function api(): void
    {
        $app = Factory::getApplication();
        try {
            $out = self::apiRun();
        } catch (\Throwable $x) {
            Log::add('Admin API: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
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
        switch ($action) {
            case 'upload':
                return self::upload();
            case 'news_get':
                return self::newsGet($in->post->getInt('id'));
            case 'news_save':
                return self::newsSave();
            case 'news_trash':
                return self::newsTrash($in->post->getInt('id'));
            case 'gal_get':
                return self::galGet($in->post->getInt('id'));
            case 'gal_save':
                return self::galSave();
            case 'gal_trash':
                return self::galTrash($in->post->getInt('id'));
        }
        return ['ok' => false, 'error' => 'Cerere invalidă.'];
    }

    private static function batchDir(string $batch): string
    {
        $uid = (int) Factory::getApplication()->getIdentity()->id;
        return JPATH_ROOT . '/' . self::ROOT . '/_upload/u' . $uid . '/' . $batch;
    }

    private static function upload(): array
    {
        if (!self::can('news') && !self::can('gal')) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să încărcați fotografii.'];
        }
        $in = Factory::getApplication()->getInput();
        $batch = $in->post->getAlnum('batch');
        if (!preg_match('/^[a-f0-9]{8,40}$/', $batch)) {
            return ['ok' => false, 'error' => 'Cerere invalidă.'];
        }
        $f = $in->files->get('photo', null, 'raw');
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) {
            return ['ok' => false, 'error' => 'Fotografia nu a ajuns la server.'];
        }
        if ((int) $f['size'] > self::MAX_UPLOAD) {
            return ['ok' => false, 'error' => 'Fotografia este prea mare.'];
        }
        $info = @getimagesize($f['tmp_name']);
        $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? '') : '';
        if ($ext === '') {
            return ['ok' => false, 'error' => 'Fișierul nu este o fotografie JPG, PNG sau WEBP.'];
        }
        $dir = self::batchDir($batch);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Dosarul pentru fotografii nu poate fi creat.'];
        }
        $name = bin2hex(random_bytes(6)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            return ['ok' => false, 'error' => 'Fotografia nu a putut fi salvată.'];
        }
        @chmod($dir . '/' . $name, 0644);
        return ['ok' => true, 'name' => $name];
    }

    /** images/news/YYYY/YYYY-MM-DD-slug (a new, unused folder). */
    private static function newFolder(string $date, string $title): string
    {
        $base = self::ROOT . '/' . substr($date, 0, 4) . '/' . $date . '-' . (self::slug($title, 50) ?: 'stire');
        $try = $base;
        for ($n = 2; is_dir(JPATH_ROOT . '/' . $try) && $n < 100; $n++) {
            $try = $base . '-' . $n;
        }
        return $try;
    }

    /**
     * Puts the photos of an item into its folder in the order given.
     * $list: [{k:'u'|'e'|'x', n:name}] (u = uploaded in this batch, e = already in the folder, x = elsewhere under images/, copied).
     * News: first photo -> _cover.jpg, others 001.jpg...; galleries: 001.jpg...
     * Returns [cover relative path, number of photos] or throws.
     */
    private static function arrange(string $folder, array $list, string $batch, bool $news): array
    {
        $abs = JPATH_ROOT . '/' . $folder;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true)) {
            throw new \RuntimeException('mkdir ' . $folder);
        }
        $bdir = $batch !== '' ? self::batchDir($batch) : '';
        $tmp = [];
        foreach (array_values($list) as $i => $p) {
            $k = (string) ($p['k'] ?? '');
            $n = (string) ($p['n'] ?? '');
            if ($k === 'x') {
                $src = self::safeRel($n);
                if ($src === '' || !is_file(JPATH_ROOT . '/' . $src)) {
                    continue;
                }
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) === 'jpeg' ? 'jpg' : strtolower(pathinfo($src, PATHINFO_EXTENSION));
                $to = $abs . '/.tmp-' . $i . '.' . $ext;
                if (@copy(JPATH_ROOT . '/' . $src, $to)) {
                    $tmp[] = $to;
                }
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_\-]+\.(jpe?g|png|webp)$/i', $n)) {
                continue;
            }
            $from = $k === 'u' ? ($bdir !== '' ? $bdir . '/' . $n : '') : ($k === 'e' ? $abs . '/' . $n : '');
            if ($from === '' || !is_file($from)) {
                continue;
            }
            $to = $abs . '/.tmp-' . $i . '.' . strtolower(pathinfo($n, PATHINFO_EXTENSION));
            if (@rename($from, $to)) {
                $tmp[] = $to;
            }
        }
        // photos left in the folder were removed in the form: keep them aside, never delete
        $left = array_filter(scandir($abs) ?: [], fn ($f) => $f[0] !== '.' && is_file($abs . '/' . $f));
        if ($left) {
            $trash = JPATH_ROOT . '/' . self::ROOT . '/_trash/' . basename($folder) . '-' . date('Ymd-His');
            @mkdir($trash, 0755, true);
            foreach ($left as $f) {
                @rename($abs . '/' . $f, $trash . '/' . $f);
            }
        }
        $cover = '';
        $n = 0;
        foreach ($tmp as $i => $t) {
            $ext = pathinfo($t, PATHINFO_EXTENSION);
            $name = $news && $i === 0 ? '_cover.' . $ext : sprintf('%03d', $news ? $i : $i + 1) . '.' . $ext;
            @rename($t, $abs . '/' . $name);
            @touch($abs . '/' . $name);
            if ($i === 0) {
                $cover = $folder . '/' . $name;
            }
            $n++;
        }
        if ($bdir !== '' && is_dir($bdir) && count(scandir($bdir) ?: []) <= 2) {
            @rmdir($bdir);
        }
        return [$cover, $n];
    }

    /** The photo list of an item for the form: cover first. */
    private static function photosOf(string $folder, string $cover): array
    {
        $out = [];
        $folder = self::safeRel($folder);
        $cover = self::safeRel($cover);
        $inFolder = $folder !== '' && $cover !== '' && strpos($cover, $folder . '/') === 0;
        if ($cover !== '' && is_file(JPATH_ROOT . '/' . $cover)) {
            $out[] = $inFolder ? ['k' => 'e', 'n' => basename($cover), 'src' => self::relUrl($cover)] : ['k' => 'x', 'n' => $cover, 'src' => self::relUrl($cover)];
        }
        if ($folder !== '') {
            foreach (News::folderPhotos($folder) as $p) {
                if ($p === $cover) {
                    continue;
                }
                $out[] = strpos($folder, self::ROOT . '/') === 0
                    ? ['k' => 'e', 'n' => basename($p), 'src' => News::thumbUrl($p)]
                    : ['k' => 'x', 'n' => $p, 'src' => News::thumbUrl($p)];
            }
        }
        return $out;
    }

    private static function articleUrl(int $id): string
    {
        $db = self::db();
        $a = $db->setQuery($db->getQuery(true)->select(['id', 'alias', 'catid', 'language'])->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$a) {
            return '';
        }
        $lang = (string) $a->language === '*' ? 'ro-RO' : (string) $a->language;
        $rel = \Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute($a->id . ':' . $a->alias, (int) $a->catid, $lang);
        try {
            return Route::link('site', $rel, false, Route::TLS_IGNORE, true);
        } catch (\Throwable $x) {
            return Uri::root() . ltrim(Route::_($rel), '/');
        }
    }

    /* ---------- news ---------- */

    private static function newsRow(int $roId): ?array
    {
        foreach (self::newsList($roId) as $r) {
            if ($r['id'] === $roId) {
                return $r;
            }
        }
        return null;
    }

    private static function newsGet(int $id): array
    {
        $cats = self::newsCats();
        $db = self::db();
        $ro = $db->setQuery($db->getQuery(true)->select(['id', 'catid', 'publish_up', 'images', 'introtext', $db->quoteName('fulltext'), 'title', 'state'])->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$ro || (int) $ro->catid !== $cats['ro'] || (int) $ro->state === -2) {
            return ['ok' => false, 'error' => 'Știrea nu mai există.'];
        }
        $as = self::assoc($id);
        $t = ['ro' => '', 'en' => '', 'es' => ''];
        $b = $t;
        foreach (self::LANGS as $l => $tag) {
            $aid = $l === 'ro' ? $id : ($as[$tag] ?? 0);
            if (!$aid) {
                continue;
            }
            $a = $l === 'ro' ? $ro : $db->setQuery($db->getQuery(true)->select(['title', 'introtext', $db->quoteName('fulltext')])->from('#__content')->where('id = ' . (int) $aid))->loadObject();
            if ($a) {
                $t[$l] = (string) $a->title;
                $b[$l] = (string) $a->introtext . (trim((string) $a->fulltext) !== '' ? (string) $a->fulltext : '');
            }
        }
        $im = json_decode((string) $ro->images, true) ?: [];
        $cover = (string) ($im['image_intro'] ?? '') ?: (string) ($im['image_fulltext'] ?? '');
        $folder = self::fieldValues([$id], ['news-gallery-folder'])[$id]['news-gallery-folder'] ?? '';
        $tags = array_map('intval', $db->setQuery($db->getQuery(true)->select('tag_id')->from('#__contentitem_tag_map')
            ->where('type_alias = ' . $db->quote('com_content.article'))->where('content_item_id = ' . $id))->loadColumn());
        return ['ok' => true, 'item' => [
            'dt' => substr((string) $ro->publish_up, 0, 10), 't' => $t, 'b' => $b,
            'tags' => array_values(array_filter($tags, fn ($x) => isset(self::TAGS[$x]))),
            'photos' => self::photosOf((string) $folder, $cover),
        ]];
    }

    private static function newsSave(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $p = $app->getInput()->post;
        $cats = self::newsCats();
        if (!$cats['ro']) {
            return ['ok' => false, 'error' => 'Categoria știrilor nu a fost găsită.'];
        }
        $id = $p->getInt('id');
        $db = self::db();
        $as = [];
        $ro = null;
        if ($id) {
            $ro = $db->setQuery($db->getQuery(true)->select(['id', 'catid', 'created_by', 'state', 'publish_up', 'images'])->from('#__content')->where('id = ' . $id))->loadObject();
            if (!$ro || (int) $ro->catid !== $cats['ro'] || (int) $ro->state === -2) {
                return ['ok' => false, 'error' => 'Știrea nu mai există.'];
            }
            $asset = 'com_content.article.' . $id;
            if (!($user->authorise('core.edit', $asset) || ((int) $ro->created_by === (int) $user->id && $user->authorise('core.edit.own', $asset)))) {
                return ['ok' => false, 'error' => 'Nu aveți dreptul să modificați această știre.'];
            }
            $as = self::assoc($id);
        } elseif (!self::can('news')) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să publicați știri.'];
        }
        $dt = (string) $p->getString('dt');
        if (!self::validDate($dt)) {
            return ['ok' => false, 'error' => 'Data nu este corectă.'];
        }
        $t = [];
        $b = [];
        $chg = [];
        foreach (array_keys(self::LANGS) as $l) {
            $t[$l] = self::plainTitle((string) $p->getString('t_' . $l));
            $b[$l] = self::cleanHtml((string) $p->getRaw('b_' . $l));
            $chg[$l] = !$id || $p->getInt('bchg_' . $l) === 1;
        }
        if ($t['ro'] === '') {
            return ['ok' => false, 'error' => 'Scrieți titlul în română.'];
        }
        foreach (array_keys(self::LANGS) as $l) {
            if ($t[$l] !== '' && $chg[$l] && trim(strip_tags($b[$l])) === '') {
                return ['ok' => false, 'error' => 'Lipsește textul în ' . ['ro' => 'română', 'en' => 'engleză', 'es' => 'spaniolă'][$l] . '.'];
            }
        }
        $tags = array_values(array_filter(array_map('intval', explode(',', (string) $p->getString('tags'))), fn ($x) => isset(self::TAGS[$x])));

        // photos
        $folder = $id ? self::safeRel(self::fieldValues([$id], ['news-gallery-folder'])[$id]['news-gallery-folder'] ?? '') : '';
        $oldIm = $ro ? (json_decode((string) $ro->images, true) ?: []) : [];
        $cover = $ro ? (string) ($oldIm['image_intro'] ?? '') : '';
        $nPhotos = null;
        if (!$id || $p->getInt('pchg') === 1) {
            $list = json_decode((string) $p->getRaw('photos'), true);
            $list = is_array($list) ? array_slice($list, 0, 300) : [];
            if ($list) {
                if ($folder === '' || strpos($folder, self::ROOT . '/') !== 0) {
                    $folder = self::newFolder($dt, $t['ro']);
                }
                $batch = $p->getAlnum('batch');
                [$cover, $nPhotos] = self::arrange($folder, $list, preg_match('/^[a-f0-9]{8,40}$/', $batch) ? $batch : '', true);
            } else {
                $cover = '';
                if ($folder !== '' && strpos($folder, self::ROOT . '/') === 0) {
                    self::arrange($folder, [], '', true);
                }
                $folder = '';
            }
        }

        // publish date: keep the time of an existing article when the day did not change
        $now = Factory::getDate()->toSql();
        $pub = $ro && substr((string) $ro->publish_up, 0, 10) === $dt ? (string) $ro->publish_up
            : ($dt === Factory::getDate('now', $app->get('offset', 'UTC'))->format('Y-m-d', true) ? $now : $dt . ' 12:00:00');

        $ids = [];
        $drop = [];
        foreach (self::LANGS as $l => $tag) {
            $aid = $l === 'ro' ? $id : (int) ($as[$tag] ?? 0);
            if ($l !== 'ro' && $t[$l] === '') {
                if ($aid) {
                    self::table()->publish([$aid], -2, (int) $user->id);
                    $drop[] = $aid;
                }
                continue;
            }
            $table = self::table();
            $images = $aid ? (json_decode((string) $db->setQuery($db->getQuery(true)->select('images')->from('#__content')->where('id = ' . $aid))->loadResult(), true) ?: []) : [];
            $images['image_intro'] = $cover;
            $images['image_fulltext'] = '';
            $images += ['image_intro_alt' => '', 'image_intro_caption' => '', 'float_intro' => '', 'image_fulltext_alt' => '', 'image_fulltext_caption' => '', 'float_fulltext' => ''];
            if ($aid) {
                $table->load($aid);
                $table->title = $t[$l];
                if ($chg[$l]) {
                    $table->introtext = $b[$l];
                    $table->fulltext = '';
                }
                $table->images = json_encode($images);
                $table->publish_up = $pub;
                $table->modified = $now;
                $table->modified_by = (int) $user->id;
                if ((int) $table->state !== 1) {
                    $table->state = 1;
                }
            } else {
                $table->bind([
                    'title' => $t[$l], 'alias' => self::uniqueAlias($cats[$l], self::slug($t[$l], 120)),
                    'catid' => $cats[$l], 'state' => 1, 'access' => 1, 'language' => $tag,
                    'introtext' => $b[$l], 'fulltext' => '', 'created' => $now, 'created_by' => (int) $user->id,
                    'publish_up' => $pub, 'featured' => 0, 'images' => json_encode($images), 'urls' => '{}', 'attribs' => '{}',
                    'metadata' => '{}', 'metakey' => '', 'metadesc' => '', 'note' => 'admin-form',
                ]);
            }
            $mapped = $l === 'ro' ? $tags : ($l === 'en' ? array_map(fn ($x) => self::TAGS[$x][0], $tags) : []);
            $table->newTags = $mapped;
            if (!$table->check() || !$table->store()) {
                Log::add('News save failed (' . $l . ')' . (method_exists($table, 'getError') ? ': ' . $table->getError() : ''), Log::WARNING, 'mitropolia');
                return ['ok' => false, 'error' => 'Știrea nu a putut fi salvată (' . strtoupper($l) . ').'];
            }
            $ids[$tag] = (int) $table->id;
            self::writeFields((int) $table->id, ['news-gallery-folder' => $folder]);
        }
        self::writeAssoc($ids, $drop);
        self::ensureWorkflow(array_values($cats));
        $row = self::newsRow($ids['ro-RO']);
        return $row ? ['ok' => true, 'item' => $row, 'url' => self::articleUrl($ids['ro-RO'])] : ['ok' => false, 'error' => 'Știrea a fost salvată, dar nu poate fi citită. Reîncărcați pagina.'];
    }

    private static function newsTrash(int $id): array
    {
        $user = Factory::getApplication()->getIdentity();
        $cats = self::newsCats();
        $db = self::db();
        $ro = $db->setQuery($db->getQuery(true)->select(['id', 'catid', 'state'])->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$ro || (int) $ro->catid !== $cats['ro'] || (int) $ro->state === -2) {
            return ['ok' => false, 'error' => 'Știrea nu mai există.'];
        }
        if (!$user->authorise('core.edit.state', 'com_content.article.' . $id)) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să ștergeți această știre.'];
        }
        $ids = array_values(self::assoc($id));
        if (!self::table()->publish($ids ?: [$id], -2, (int) $user->id)) {
            return ['ok' => false, 'error' => 'Știrea nu a putut fi ștearsă.'];
        }
        return ['ok' => true];
    }

    /* ---------- galleries ---------- */

    private static function galRow(int $id): ?array
    {
        foreach (self::galList($id) as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    private static function galGet(int $id): array
    {
        $db = self::db();
        $a = $db->setQuery($db->getQuery(true)->select(['id', 'catid', 'publish_up', 'images', 'title', 'state'])->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$a || (int) $a->catid !== self::galCat() || (int) $a->state === -2) {
            return ['ok' => false, 'error' => 'Galeria nu mai există.'];
        }
        $v = self::fieldValues([$id], self::GAL_FIELDS)[$id] ?? [];
        $im = json_decode((string) $a->images, true) ?: [];
        $folder = (string) ($v['gallery-folder'] ?? '');
        $photos = [];
        foreach (News::folderPhotos($folder) as $p) {
            $photos[] = strpos($folder, self::ROOT . '/') === 0 ? ['k' => 'e', 'n' => basename($p), 'src' => News::thumbUrl($p)] : ['k' => 'x', 'n' => $p, 'src' => News::thumbUrl($p)];
        }
        return ['ok' => true, 'item' => [
            'dt' => substr((string) ($v['gallery-date'] ?? $a->publish_up), 0, 10),
            't' => ['ro' => (string) ($v['gallery-title-ro'] ?? $a->title), 'en' => (string) ($v['gallery-title-en'] ?? ''), 'es' => (string) ($v['gallery-title-es'] ?? '')],
            'lnk' => (int) ($v['gallery-news'] ?? 0) ?: '', 'photos' => $photos,
        ]];
    }

    private static function galSave(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $p = $app->getInput()->post;
        $cat = self::galCat();
        if (!$cat) {
            return ['ok' => false, 'error' => 'Categoria galeriilor nu a fost găsită.'];
        }
        $id = $p->getInt('id');
        $db = self::db();
        $a = null;
        if ($id) {
            $a = $db->setQuery($db->getQuery(true)->select(['id', 'catid', 'created_by', 'state', 'publish_up'])->from('#__content')->where('id = ' . $id))->loadObject();
            if (!$a || (int) $a->catid !== $cat || (int) $a->state === -2) {
                return ['ok' => false, 'error' => 'Galeria nu mai există.'];
            }
            $asset = 'com_content.article.' . $id;
            if (!($user->authorise('core.edit', $asset) || ((int) $a->created_by === (int) $user->id && $user->authorise('core.edit.own', $asset)))) {
                return ['ok' => false, 'error' => 'Nu aveți dreptul să modificați această galerie.'];
            }
        } elseif (!self::can('gal')) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să publicați galerii.'];
        }
        $dt = (string) $p->getString('dt');
        if (!self::validDate($dt)) {
            return ['ok' => false, 'error' => 'Data nu este corectă.'];
        }
        $t = ['ro' => self::plainTitle((string) $p->getString('t_ro')), 'en' => self::plainTitle((string) $p->getString('t_en')), 'es' => self::plainTitle((string) $p->getString('t_es'))];
        if ($t['ro'] === '') {
            return ['ok' => false, 'error' => 'Scrieți titlul în română.'];
        }
        $lnk = $p->getInt('lnk');
        if ($lnk) {
            $ok = (int) $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from('#__content')->where('id = ' . $lnk)->where('catid = ' . self::newsCats()['ro']))->loadResult();
            $lnk = $ok ? $lnk : 0;
        }
        $folder = $id ? self::safeRel(self::fieldValues([$id], ['gallery-folder'])[$id]['gallery-folder'] ?? '') : '';
        $cover = '';
        if (!$id || $p->getInt('pchg') === 1) {
            $list = json_decode((string) $p->getRaw('photos'), true);
            $list = is_array($list) ? array_slice($list, 0, 500) : [];
            if (!$list) {
                return ['ok' => false, 'error' => 'Adăugați fotografiile.'];
            }
            if ($folder === '' || strpos($folder, self::ROOT . '/') !== 0) {
                $folder = self::newFolder($dt, $t['ro']);
            }
            $batch = $p->getAlnum('batch');
            [$cover] = self::arrange($folder, $list, preg_match('/^[a-f0-9]{8,40}$/', $batch) ? $batch : '', false);
        } else {
            $ph = News::folderPhotos($folder);
            $cover = $ph[0] ?? '';
        }
        $now = Factory::getDate()->toSql();
        $images = ['image_intro' => $cover, 'image_intro_alt' => '', 'image_intro_caption' => '', 'float_intro' => '', 'image_fulltext' => '', 'image_fulltext_alt' => '', 'image_fulltext_caption' => '', 'float_fulltext' => ''];
        $pub = $a && substr((string) $a->publish_up, 0, 10) === $dt ? (string) $a->publish_up : $dt . ' 12:00:00';
        $table = self::table();
        if ($a) {
            $table->load($id);
            $table->title = $t['ro'];
            $table->images = json_encode($images);
            $table->publish_up = $pub;
            $table->modified = $now;
            $table->modified_by = (int) $user->id;
        } else {
            $table->bind([
                'title' => $t['ro'], 'alias' => self::uniqueAlias($cat, self::slug($dt . ' ' . $t['ro'], 120)),
                'catid' => $cat, 'state' => 1, 'access' => 1, 'language' => '*', 'introtext' => '', 'fulltext' => '',
                'created' => $now, 'created_by' => (int) $user->id, 'publish_up' => $pub, 'featured' => 0,
                'images' => json_encode($images), 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}', 'metakey' => '', 'metadesc' => '', 'note' => 'admin-form',
            ]);
        }
        if (!$table->check() || !$table->store()) {
            Log::add('Gallery save failed' . (method_exists($table, 'getError') ? ': ' . $table->getError() : ''), Log::WARNING, 'mitropolia');
            return ['ok' => false, 'error' => 'Galeria nu a putut fi salvată.'];
        }
        $gid = (int) $table->id;
        self::writeFields($gid, [
            'gallery-title-ro' => $t['ro'], 'gallery-title-en' => $t['en'], 'gallery-title-es' => $t['es'],
            'gallery-date' => $dt . ' 12:00:00', 'gallery-folder' => $folder, 'gallery-news' => $lnk ? (string) $lnk : '',
        ]);
        self::ensureWorkflow([$cat]);
        $row = self::galRow($gid);
        return $row ? ['ok' => true, 'item' => $row, 'url' => self::articleUrl($gid)] : ['ok' => false, 'error' => 'Galeria a fost salvată, dar nu poate fi citită. Reîncărcați pagina.'];
    }

    private static function galTrash(int $id): array
    {
        $user = Factory::getApplication()->getIdentity();
        $db = self::db();
        $a = $db->setQuery($db->getQuery(true)->select(['id', 'catid', 'state'])->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$a || (int) $a->catid !== self::galCat() || (int) $a->state === -2) {
            return ['ok' => false, 'error' => 'Galeria nu mai există.'];
        }
        if (!$user->authorise('core.edit.state', 'com_content.article.' . $id)) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să ștergeți această galerie.'];
        }
        if (!self::table()->publish([$id], -2, (int) $user->id)) {
            return ['ok' => false, 'error' => 'Galeria nu a putut fi ștearsă.'];
        }
        return ['ok' => true];
    }
}
