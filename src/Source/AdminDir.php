<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;

/**
 * "Clerici" and "Parohii" tabs of the Administrare page.
 *
 * Parishes: one "All languages" article each in the parishes category; names, address, schedule, feasts,
 * council and the about text are custom fields (subforms stored as JSON keyed row0, row1… / field<id>).
 * Clergy: one "All languages" article each, in the child category of its diocese; the title is the full name.
 * A cleric's parishes live only on the cleric (subform clergy-parishes: parish + role). The parish form edits
 * those same rows, so both sides always show the same assignments.
 * Photos go to images/parishes/ and images/clergy/ (image_intro); an old photo is never deleted.
 * Removing means moving to the trash.
 */
final class AdminDir
{
    public const PAR_FIELDS = ['parish-name-ro', 'parish-name-en', 'parish-name-es', 'parish-type', 'parish-diocese', 'parish-deanery',
        'parish-street', 'parish-city', 'parish-state', 'parish-postal', 'parish-country', 'parish-location', 'parish-services-at',
        'parish-mailing', 'parish-phone', 'parish-email', 'parish-website', 'parish-facebook', 'parish-about-ro', 'parish-about-en',
        'parish-about-es', 'parish-feasts', 'parish-services', 'parish-lay-leaders'];
    public const CL_FIELDS = ['last-name', 'clergy-group', 'clergy-title', 'clergy-title-ro', 'clergy-title-en', 'clergy-title-es',
        'clergy-status', 'clergy-email', 'clergy-phone', 'clergy-parishes'];
    private const SUB_LAY = ['lay-name', 'lay-roles', 'lay-phone', 'lay-email'];
    private const SUB_FEAST = ['feast-ro', 'feast-en', 'feast-es'];
    private const SUB_SCHED = ['sched-day-ro', 'sched-day-en', 'sched-day-es', 'sched-service-ro', 'sched-service-en', 'sched-service-es', 'sched-time'];
    private const SUB_ASG = ['parish', 'role'];

    private const TYPES = ['cathedral', 'parish', 'mission', 'monastery', 'chapel'];
    private const DIOS = ['archdiocese', 'canada', 'south-america'];
    private const DEANERIES = ['archdiocese' => ['us-east', 'us-south', 'us-central', 'us-west'], 'canada' => ['ca-east', 'ca-central', 'ca-west'], 'south-america' => ['south-america']];
    private const LAY_ROLES = ['council-president', 'arola-director', 'roya-director', 'religious-education', 'chanter', 'choir-director', 'contact'];
    private const ROLES = ['metropolitan', 'bishop', 'abbot', 'parish-priest', 'administrator-priest', 'assigned-priest', 'temporary-assigned-priest', 'attached-priest', 'priest', 'deacon'];
    private const GROUPS = ['hierarch', 'priest', 'deacon'];
    private const TITLES = ['ips', 'ps', 'pc', 'pcuv', 'pr', 'diac', 'other'];
    /** Clergy sub-category alias => diocese code used by the parish field. */
    private const CL_ALIAS = ['archdiocese' => 'archdiocese', 'diocese-of-canada' => 'canada', 'south-america' => 'south-america'];

    /* ------------------------------------------------------------------ categories and rights */

    public static function parCat(): int
    {
        return Admin::catByAlias('parishes');
    }

    /** Diocese code => clergy category id. */
    public static function clCats(): array
    {
        static $out = null;
        if ($out === null) {
            $out = [];
            $root = Admin::catByAlias('clergy');
            if ($root) {
                $db = Admin::db();
                foreach ($db->setQuery($db->getQuery(true)->select(['id', 'alias'])->from('#__categories')
                    ->where('parent_id = ' . $root)->where('published = 1'))->loadObjectList() as $c) {
                    if (isset(self::CL_ALIAS[$c->alias])) {
                        $out[self::CL_ALIAS[$c->alias]] = (int) $c->id;
                    }
                }
            }
        }
        return $out;
    }

    public static function can(string $what): bool
    {
        $u = Factory::getApplication()->getIdentity();
        if (!$u || $u->guest) {
            return false;
        }
        if ($what === 'par') {
            $c = self::parCat();
            return $c > 0 && (bool) $u->authorise('core.create', 'com_content.category.' . $c);
        }
        foreach (self::clCats() as $c) {
            if ($u->authorise('core.create', 'com_content.category.' . $c)) {
                return true;
            }
        }
        return false;
    }

    private static function rights(object $r): array
    {
        $u = Factory::getApplication()->getIdentity();
        $asset = 'com_content.article.' . (int) $r->id;
        $own = (int) $r->created_by === (int) $u->id;
        return [
            'edit' => $u->authorise('core.edit', $asset) || ($own && $u->authorise('core.edit.own', $asset)),
            'del'  => (bool) $u->authorise('core.edit.state', $asset),
        ];
    }

    /* ------------------------------------------------------------------ subforms */

    /** Subform JSON => list of rows keyed by field name. */
    private static function subRead(string $raw, array $names): array
    {
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return [];
        }
        $byId = array_flip(Admin::fieldIds($names));
        $rows = [];
        foreach ($d as $row) {
            $r = [];
            foreach ((array) $row as $k => $v) {
                $id = (int) preg_replace('/\D/', '', (string) $k);
                if (isset($byId[$id])) {
                    $r[$byId[$id]] = $v;
                }
            }
            $rows[] = $r;
        }
        return $rows;
    }

    /** Rows keyed by field name => subform JSON ('' when empty). */
    private static function subWrite(array $rows, array $names): string
    {
        $ids = Admin::fieldIds($names);
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $o = [];
            foreach ($names as $n) {
                if (isset($ids[$n])) {
                    $o['field' . $ids[$n]] = $r[$n] ?? '';
                }
            }
            $out['row' . $i] = $o;
        }
        return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : '';
    }

    private static function str($v, int $max = 250): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(is_array($v) ? (string) reset($v) : (string) $v))), 0, $max);
    }

    private static function photoOf(?string $images): string
    {
        $im = json_decode((string) $images, true) ?: [];
        return Admin::safeRel((string) ($im['image_intro'] ?? '') ?: (string) ($im['image_fulltext'] ?? ''));
    }

    private static function thumb(string $rel): string
    {
        return $rel !== '' && is_file(JPATH_ROOT . '/' . $rel) ? News::thumbUrl($rel) : '';
    }

    /* ------------------------------------------------------------------ lists */

    /** All parishes for the list and the pickers: [id, name, type, diocese, city, state, edit, del]. */
    public static function parishes(): array
    {
        $cat = self::parCat();
        if (!$cat) {
            return [];
        }
        $db = Admin::db();
        $rows = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'created_by', 'state'])->from('#__content')
            ->where('catid = ' . $cat)->where('state IN (0,1)'))->loadObjectList();
        $f = Admin::fieldValues(array_map(fn ($r) => (int) $r->id, $rows), ['parish-name-ro', 'parish-type', 'parish-diocese', 'parish-city', 'parish-state']);
        $out = [];
        foreach ($rows as $r) {
            $v = $f[(int) $r->id] ?? [];
            $rg = self::rights($r);
            $out[] = [(int) $r->id, ($v['parish-name-ro'] ?? '') ?: (string) $r->title, ($v['parish-type'] ?? '') ?: 'parish',
                ($v['parish-diocese'] ?? '') ?: 'archdiocese', (string) ($v['parish-city'] ?? ''), (string) ($v['parish-state'] ?? ''), $rg['edit'], $rg['del']];
        }
        usort($out, fn ($a, $b) => strcoll($a[1], $b[1]));
        return $out;
    }

    /** All clergy (or one), for the list: id, name, last, group, title, status, diocese, assignments, thumbnail, rights. */
    public static function clergy(int $only = 0): array
    {
        $cats = self::clCats();
        if (!$cats) {
            return [];
        }
        $byCat = array_flip($cats);
        $db = Admin::db();
        $q = $db->getQuery(true)->select(['id', 'title', 'catid', 'images', 'created_by', 'state'])->from('#__content')
            ->whereIn('catid', array_values($cats))->where('state IN (0,1)');
        if ($only) {
            $q->where('id = ' . $only);
        }
        $rows = $db->setQuery($q)->loadObjectList();
        $f = Admin::fieldValues(array_map(fn ($r) => (int) $r->id, $rows), self::CL_FIELDS);
        $out = [];
        foreach ($rows as $r) {
            $v = $f[(int) $r->id] ?? [];
            $asg = [];
            foreach (self::subRead((string) ($v['clergy-parishes'] ?? ''), self::SUB_ASG) as $a) {
                $p = (int) self::str($a['parish'] ?? '');
                if ($p) {
                    $asg[] = [$p, self::str($a['role'] ?? '')];
                }
            }
            $words = preg_split('/\s+/u', trim((string) $r->title));
            $out[] = [
                'id' => (int) $r->id, 'name' => (string) $r->title, 'last' => ($v['last-name'] ?? '') ?: (string) end($words),
                'group' => in_array($v['clergy-group'] ?? '', self::GROUPS, true) ? $v['clergy-group'] : 'priest',
                'title' => (string) ($v['clergy-title'] ?? ''), 'tro' => (string) ($v['clergy-title-ro'] ?? ''),
                'status' => ($v['clergy-status'] ?? '') === 'retired' ? 'retired' : 'active',
                'dio' => $byCat[(int) $r->catid] ?? 'archdiocese', 'asg' => $asg, 'th' => self::thumb(self::photoOf($r->images)),
            ] + self::rights($r);
        }
        usort($out, fn ($a, $b) => strcoll($a['last'] . ' ' . $a['name'], $b['last'] . ' ' . $b['name']));
        return $out;
    }

    /* ------------------------------------------------------------------ API */

    public static function api(string $action): array
    {
        $p = Factory::getApplication()->getInput()->post;
        switch ($action) {
            case 'dir_par_get':
                return self::parGet($p->getInt('id'));
            case 'dir_par_save':
                return self::parSave();
            case 'dir_par_trash':
                return self::parTrash($p->getInt('id'));
            case 'dir_cl_get':
                return self::clGet($p->getInt('id'));
            case 'dir_cl_save':
                return self::clSave();
            case 'dir_cl_trash':
                return self::clTrash($p->getInt('id'));
        }
        return ['ok' => false, 'error' => 'Cerere invalidă.'];
    }

    private static function load(int $id, array $cats): ?object
    {
        $db = Admin::db();
        $a = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias', 'catid', 'images', 'created_by', 'state'])->from('#__content')->where('id = ' . $id))->loadObject();
        return $a && in_array((int) $a->catid, $cats, true) && (int) $a->state !== -2 ? $a : null;
    }

    /** Moves an uploaded photo (from the upload batch) to images/<dir>/; returns the relative path or ''. */
    private static function placePhoto(string $dir, string $alias, string $batch, string $name): string
    {
        if (!preg_match('/^[a-f0-9]{8,40}$/', $batch) || !preg_match('/^[A-Za-z0-9_\-]+\.(jpe?g|png|webp)$/i', $name)) {
            return '';
        }
        $from = Admin::batchDir($batch) . '/' . $name;
        if (!is_file($from)) {
            return '';
        }
        $abs = JPATH_ROOT . '/images/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true)) {
            return '';
        }
        $file = (Admin::slug($alias, 60) ?: $dir) . '-' . date('Ymd-His') . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!@rename($from, $abs . '/' . $file)) {
            return '';
        }
        @chmod($abs . '/' . $file, 0644);
        $bdir = dirname($from);
        if (count(scandir($bdir) ?: []) <= 2) {
            @rmdir($bdir);
        }
        return 'images/' . $dir . '/' . $file;
    }

    /** The new image_intro after the form: photo = 'keep' | 'none' | uploaded file name. */
    private static function photoAfter(?string $oldImages, string $dir, string $alias): array
    {
        $p = Factory::getApplication()->getInput()->post;
        $im = json_decode((string) $oldImages, true) ?: [];
        $im += ['image_intro' => '', 'image_intro_alt' => '', 'image_intro_caption' => '', 'float_intro' => '', 'image_fulltext' => '', 'image_fulltext_alt' => '', 'image_fulltext_caption' => '', 'float_fulltext' => ''];
        $ph = (string) $p->getString('photo');
        if ($ph === 'none') {
            $im['image_intro'] = '';
            $im['image_fulltext'] = '';
        } elseif ($ph !== '' && $ph !== 'keep') {
            $rel = self::placePhoto($dir, $alias, $p->getAlnum('batch'), $ph);
            if ($rel !== '') {
                $im['image_intro'] = $rel;
                $im['image_fulltext'] = '';
            }
        }
        return $im;
    }

    private static function store($table, array $bind, string $what): ?int
    {
        if ($bind) {
            $table->bind($bind);
        }
        if (!$table->check() || !$table->store()) {
            Log::add($what . ' save failed' . (method_exists($table, 'getError') ? ': ' . $table->getError() : ''), Log::WARNING, 'mitropolia');
            return null;
        }
        return (int) $table->id;
    }

    /* ---------- parishes ---------- */

    private static function parGet(int $id): array
    {
        $a = self::load($id, [self::parCat()]);
        if (!$a) {
            return ['ok' => false, 'error' => 'Parohia nu mai există.'];
        }
        $v = Admin::fieldValues([$id], self::PAR_FIELDS)[$id] ?? [];
        $s = fn ($k) => (string) ($v[$k] ?? '');
        $photo = self::photoOf($a->images);
        $sched = array_map(fn ($r) => [
            'd' => [self::str($r['sched-day-ro'] ?? ''), self::str($r['sched-day-en'] ?? ''), self::str($r['sched-day-es'] ?? '')],
            's' => [self::str($r['sched-service-ro'] ?? ''), self::str($r['sched-service-en'] ?? ''), self::str($r['sched-service-es'] ?? '')],
            't' => self::str($r['sched-time'] ?? ''),
        ], self::subRead($s('parish-services'), self::SUB_SCHED));
        $feasts = array_map(fn ($r) => [self::str($r['feast-ro'] ?? ''), self::str($r['feast-en'] ?? ''), self::str($r['feast-es'] ?? '')], self::subRead($s('parish-feasts'), self::SUB_FEAST));
        $lay = array_map(fn ($r) => [
            'n' => self::str($r['lay-name'] ?? ''), 'roles' => array_values(array_intersect(array_map('strval', (array) ($r['lay-roles'] ?? [])), self::LAY_ROLES)),
            'ph' => self::str($r['lay-phone'] ?? ''), 'em' => self::str($r['lay-email'] ?? ''),
        ], self::subRead($s('parish-lay-leaders'), self::SUB_LAY));
        return ['ok' => true, 'item' => [
            'id' => $id, 'type' => $s('parish-type') ?: 'parish', 'dio' => $s('parish-diocese') ?: 'archdiocese', 'dea' => $s('parish-deanery'),
            'ro' => $s('parish-name-ro') ?: (string) $a->title, 'en' => $s('parish-name-en'), 'es' => $s('parish-name-es'),
            'street' => $s('parish-street'), 'city' => $s('parish-city'), 'state' => $s('parish-state'), 'zip' => $s('parish-postal'),
            'country' => $s('parish-country'), 'at' => $s('parish-services-at'), 'geo' => $s('parish-location'), 'mail' => $s('parish-mailing'),
            'phone' => $s('parish-phone'), 'email' => $s('parish-email'), 'web' => $s('parish-website'), 'fb' => $s('parish-facebook'),
            'about' => ['ro' => $s('parish-about-ro'), 'en' => $s('parish-about-en'), 'es' => $s('parish-about-es')],
            'sched' => array_values(array_filter($sched, fn ($r) => implode('', $r['d']) . implode('', $r['s']) . $r['t'] !== '')),
            'feasts' => array_values(array_filter($feasts, fn ($r) => implode('', $r) !== '')),
            'lay' => array_values(array_filter($lay, fn ($r) => $r['n'] !== '')),
            'photo' => $photo !== '' && is_file(JPATH_ROOT . '/' . $photo) ? Admin::relUrl($photo) : '',
            'url' => Admin::articleUrl($id),
        ]];
    }

    private static function parSave(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $p = $app->getInput()->post;
        $cat = self::parCat();
        if (!$cat) {
            return ['ok' => false, 'error' => 'Categoria parohiilor nu a fost găsită.'];
        }
        $id = $p->getInt('id');
        $a = null;
        if ($id) {
            $a = self::load($id, [$cat]);
            if (!$a) {
                return ['ok' => false, 'error' => 'Parohia nu mai există.'];
            }
            if (!self::rights($a)['edit']) {
                return ['ok' => false, 'error' => 'Nu aveți dreptul să modificați această parohie.'];
            }
        } elseif (!self::can('par')) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să adăugați parohii.'];
        }
        $g = fn ($k, $max = 250) => self::str($p->getString($k), $max);
        $ro = $g('ro');
        $city = $g('city', 120);
        if ($ro === '' || $city === '') {
            return ['ok' => false, 'error' => 'Lipsește numele în română sau orașul.'];
        }
        $type = in_array($p->getCmd('type'), self::TYPES, true) ? $p->getCmd('type') : 'parish';
        $dio = in_array($p->getCmd('dio'), self::DIOS, true) ? $p->getCmd('dio') : 'archdiocese';
        $dea = in_array($p->getCmd('dea'), self::DEANERIES[$dio], true) ? $p->getCmd('dea') : self::DEANERIES[$dio][0];
        $geo = '';
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', (string) $p->getString('geo'), $m)
            && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180) {
            $geo = round((float) $m[1], 6) . ',' . round((float) $m[2], 6);
        }
        $url = function (string $k): string {
            $u = trim((string) Factory::getApplication()->getInput()->post->getString($k));
            if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                $u = 'https://' . $u;
            }
            return $u !== '' && filter_var($u, FILTER_VALIDATE_URL) ? mb_substr($u, 0, 400) : '';
        };
        $email = trim((string) $p->getString('email'));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

        $sched = [];
        foreach (array_slice((array) json_decode((string) $p->getRaw('sched'), true), 0, 40) as $r) {
            $d = array_map(fn ($x) => self::str($x, 80), array_pad((array) ($r['d'] ?? []), 3, ''));
            $s = array_map(fn ($x) => self::str($x, 120), array_pad((array) ($r['s'] ?? []), 3, ''));
            $t = self::str($r['t'] ?? '', 40);
            if ($d[0] . $s[0] . $t === '') {
                continue;
            }
            $sched[] = ['sched-day-ro' => $d[0], 'sched-day-en' => $d[1], 'sched-day-es' => $d[2],
                'sched-service-ro' => $s[0], 'sched-service-en' => $s[1], 'sched-service-es' => $s[2], 'sched-time' => $t];
        }
        $feasts = [];
        foreach (array_slice((array) json_decode((string) $p->getRaw('feasts'), true), 0, 10) as $r) {
            $f = array_map(fn ($x) => self::str($x, 150), array_pad((array) $r, 3, ''));
            if ($f[0] !== '') {
                $feasts[] = ['feast-ro' => $f[0], 'feast-en' => $f[1], 'feast-es' => $f[2]];
            }
        }
        $lay = [];
        foreach (array_slice((array) json_decode((string) $p->getRaw('lay'), true), 0, 40) as $r) {
            $n = self::str($r['n'] ?? '', 120);
            if ($n === '') {
                continue;
            }
            $em = trim((string) ($r['em'] ?? ''));
            $lay[] = ['lay-name' => $n, 'lay-roles' => array_values(array_intersect(array_map('strval', (array) ($r['roles'] ?? [])), self::LAY_ROLES)),
                'lay-phone' => self::str($r['ph'] ?? '', 60), 'lay-email' => filter_var($em, FILTER_VALIDATE_EMAIL) ? $em : ''];
        }
        $about = [];
        foreach (['ro', 'en', 'es'] as $l) {
            $about[$l] = Admin::cleanHtml((string) $p->getRaw('about_' . $l));
        }

        $en = $g('en');
        $now = Factory::getDate()->toSql();
        $table = Admin::table();
        $title = $en !== '' ? $en : $ro;
        if ($a) {
            $table->load($id);
            $table->title = $title;
            $table->images = json_encode(self::photoAfter($a->images, 'parishes', (string) $a->alias));
            $table->modified = $now;
            $table->modified_by = (int) $user->id;
            $pid = self::store($table, [], 'Parish');
        } else {
            $alias = Admin::uniqueAlias($cat, Admin::slug($title . ' ' . $city . ' ' . $g('state', 40), 120) ?: 'parohie');
            $pid = self::store($table, [
                'title' => $title, 'alias' => $alias, 'catid' => $cat, 'state' => 1, 'access' => 1, 'language' => '*',
                'introtext' => '', 'fulltext' => '', 'created' => $now, 'created_by' => (int) $user->id, 'publish_up' => $now, 'featured' => 0,
                'images' => json_encode(self::photoAfter(null, 'parishes', $alias)), 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}',
                'metakey' => '', 'metadesc' => '', 'note' => 'admin-form',
            ], 'Parish');
        }
        if (!$pid) {
            return ['ok' => false, 'error' => 'Parohia nu a putut fi salvată.'];
        }
        Admin::writeFields($pid, [
            'parish-name-ro' => $ro, 'parish-name-en' => $en, 'parish-name-es' => $g('es'),
            'parish-type' => $type, 'parish-diocese' => $dio, 'parish-deanery' => $dea,
            'parish-street' => $g('street'), 'parish-city' => $city, 'parish-state' => $g('state', 60), 'parish-postal' => $g('zip', 20),
            'parish-country' => $g('country', 60), 'parish-location' => $geo, 'parish-services-at' => $g('at'),
            'parish-mailing' => mb_substr(trim(strip_tags((string) $p->getString('mail'))), 0, 500),
            'parish-phone' => $g('phone', 60), 'parish-email' => $email, 'parish-website' => $url('web'), 'parish-facebook' => $url('fb'),
            'parish-about-ro' => $about['ro'], 'parish-about-en' => $about['en'], 'parish-about-es' => $about['es'],
            'parish-services' => self::subWrite($sched, self::SUB_SCHED), 'parish-feasts' => self::subWrite($feasts, self::SUB_FEAST),
            'parish-lay-leaders' => self::subWrite($lay, self::SUB_LAY),
        ]);
        Admin::ensureWorkflow([$cat]);

        // clergy of this parish: the same rows as on the clergy records
        $changed = [];
        $note = '';
        if ($p->getInt('clchg') === 1) {
            $want = [];
            foreach (array_slice((array) json_decode((string) $p->getRaw('clergy'), true), 0, 60) as $r) {
                $c = (int) ($r['c'] ?? 0);
                $role = in_array($r['r'] ?? '', self::ROLES, true) ? $r['r'] : 'priest';
                if ($c) {
                    $want[$c] = $role;
                }
            }
            [$changed, $skipped] = self::syncParish($pid, $want);
            if ($skipped) {
                $note = 'Nu aveți dreptul să modificați fișele: ' . implode(', ', $skipped) . '.';
            }
        }
        $row = array_values(array_filter(self::parishes(), fn ($r) => $r[0] === $pid))[0] ?? null;
        return ['ok' => true, 'item' => $row, 'clergy' => $changed ? array_values(array_filter(self::clergy(), fn ($c) => in_array($c['id'], $changed, true))) : [],
            'url' => Admin::articleUrl($pid), 'note' => $note];
    }

    /**
     * Makes the clergy assignments of one parish match $want (cleric id => role).
     * Returns [ids of clergy records changed, names of clergy the user may not edit].
     */
    private static function syncParish(int $pid, array $want): array
    {
        $cats = array_values(self::clCats());
        if (!$cats) {
            return [[], []];
        }
        $db = Admin::db();
        $fid = Admin::fieldIds(['clergy-parishes'])['clergy-parishes'] ?? 0;
        // clergy who have this parish now, plus the ones who should
        $have = [];
        if ($fid) {
            $ids = $db->setQuery($db->getQuery(true)->select('item_id')->from('#__fields_values')->where('field_id = ' . (int) $fid)
                ->where('value LIKE ' . $db->quote('%"' . $pid . '"%')))->loadColumn();
            $have = array_map('intval', $ids);
        }
        $touch = array_unique(array_merge($have, array_keys($want)));
        if (!$touch) {
            return [[], []];
        }
        $rows = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'catid', 'created_by', 'state'])->from('#__content')
            ->whereIn('id', $touch)->whereIn('catid', $cats)->where('state IN (0,1)'))->loadObjectList('id');
        $vals = Admin::fieldValues(array_keys($rows), ['clergy-parishes']);
        $changed = [];
        $skipped = [];
        foreach ($rows as $cid => $r) {
            $cur = self::subRead((string) ($vals[$cid]['clergy-parishes'] ?? ''), self::SUB_ASG);
            $next = [];
            $found = false;
            foreach ($cur as $a) {
                if ((int) self::str($a['parish'] ?? '') === $pid) {
                    if (isset($want[$cid]) && !$found) {
                        $next[] = ['parish' => (string) $pid, 'role' => $want[$cid]];
                        $found = true;
                    }
                    continue;
                }
                $next[] = ['parish' => (string) self::str($a['parish'] ?? ''), 'role' => self::str($a['role'] ?? '')];
            }
            if (isset($want[$cid]) && !$found) {
                $next[] = ['parish' => (string) $pid, 'role' => $want[$cid]];
            }
            $old = array_map(fn ($a) => [(string) self::str($a['parish'] ?? ''), self::str($a['role'] ?? '')], $cur);
            $new = array_map(fn ($a) => [$a['parish'], $a['role']], $next);
            if ($old === $new) {
                continue;
            }
            if (!self::rights($r)['edit']) {
                $skipped[] = (string) $r->title;
                continue;
            }
            Admin::writeFields((int) $cid, ['clergy-parishes' => self::subWrite($next, self::SUB_ASG)]);
            $changed[] = (int) $cid;
        }
        return [$changed, $skipped];
    }

    private static function parTrash(int $id): array
    {
        $user = Factory::getApplication()->getIdentity();
        $a = self::load($id, [self::parCat()]);
        if (!$a) {
            return ['ok' => false, 'error' => 'Parohia nu mai există.'];
        }
        if (!self::rights($a)['del']) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să ștergeți această parohie.'];
        }
        if (!Admin::table()->publish([$id], -2, (int) $user->id)) {
            return ['ok' => false, 'error' => 'Parohia nu a putut fi ștearsă.'];
        }
        [$changed] = self::syncParish($id, []);
        return ['ok' => true, 'clergy' => $changed ? array_values(array_filter(self::clergy(), fn ($c) => in_array($c['id'], $changed, true))) : []];
    }

    /* ---------- clergy ---------- */

    private static function clGet(int $id): array
    {
        $a = self::load($id, array_values(self::clCats()));
        if (!$a) {
            return ['ok' => false, 'error' => 'Fișa nu mai există.'];
        }
        $row = self::clergy($id)[0] ?? null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Fișa nu mai există.'];
        }
        $v = Admin::fieldValues([$id], self::CL_FIELDS)[$id] ?? [];
        $photo = self::photoOf($a->images);
        return ['ok' => true, 'item' => $row + [
            'ten' => (string) ($v['clergy-title-en'] ?? ''), 'tes' => (string) ($v['clergy-title-es'] ?? ''),
            'phone' => (string) ($v['clergy-phone'] ?? ''), 'email' => (string) ($v['clergy-email'] ?? ''),
            'photo' => $photo !== '' && is_file(JPATH_ROOT . '/' . $photo) ? Admin::relUrl($photo) : '',
        ]];
    }

    private static function clSave(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $p = $app->getInput()->post;
        $cats = self::clCats();
        $dio = in_array($p->getCmd('dio'), self::DIOS, true) ? $p->getCmd('dio') : 'archdiocese';
        $cat = $cats[$dio] ?? 0;
        if (!$cat) {
            return ['ok' => false, 'error' => 'Categoria clerului pentru această eparhie nu a fost găsită.'];
        }
        $id = $p->getInt('id');
        $a = null;
        if ($id) {
            $a = self::load($id, array_values($cats));
            if (!$a) {
                return ['ok' => false, 'error' => 'Fișa nu mai există.'];
            }
            if (!self::rights($a)['edit']) {
                return ['ok' => false, 'error' => 'Nu aveți dreptul să modificați această fișă.'];
            }
        } elseif (!$user->authorise('core.create', 'com_content.category.' . $cat)) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să adăugați clerici.'];
        }
        $name = self::str($p->getString('name'), 200);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Scrieți numele.'];
        }
        $words = preg_split('/\s+/u', $name);
        $last = self::str($p->getString('last'), 100) ?: (string) end($words);
        $group = in_array($p->getCmd('group'), self::GROUPS, true) ? $p->getCmd('group') : 'priest';
        $title = in_array($p->getCmd('title'), self::TITLES, true) ? $p->getCmd('title') : '';
        $custom = $title === 'other' ? [self::str($p->getString('tro'), 100), self::str($p->getString('ten'), 100), self::str($p->getString('tes'), 100)] : ['', '', ''];
        if ($title === 'other' && $custom[0] === '') {
            return ['ok' => false, 'error' => 'Scrieți titlul în română.'];
        }
        $status = $p->getCmd('status') === 'retired' ? 'retired' : 'active';
        $email = trim((string) $p->getString('email'));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        $parIds = array_column(self::parishes(), 0);
        $asg = [];
        foreach (array_slice((array) json_decode((string) $p->getRaw('asg'), true), 0, 20) as $r) {
            $pid = (int) ($r['p'] ?? 0);
            if ($pid && in_array($pid, $parIds, true) && !in_array($pid, array_column($asg, 'parish'), true)) {
                $asg[] = ['parish' => (string) $pid, 'role' => in_array($r['r'] ?? '', self::ROLES, true) ? $r['r'] : 'priest'];
            }
        }
        // keep assignments to parishes this list does not show (e.g. unpublished ones the form could not load)
        if ($a) {
            $old = self::subRead((string) (Admin::fieldValues([$id], ['clergy-parishes'])[$id]['clergy-parishes'] ?? ''), self::SUB_ASG);
            foreach ($old as $o) {
                $op = (int) self::str($o['parish'] ?? '');
                if ($op && !in_array($op, $parIds, true)) {
                    $asg[] = ['parish' => (string) $op, 'role' => self::str($o['role'] ?? '')];
                }
            }
        }

        $now = Factory::getDate()->toSql();
        $table = Admin::table();
        if ($a) {
            $table->load($id);
            $table->title = $name;
            if ((int) $table->catid !== $cat) {
                $table->catid = $cat;
                $table->alias = Admin::uniqueAlias($cat, (string) $table->alias, $id);
            }
            $table->images = json_encode(self::photoAfter($a->images, 'clergy', (string) $a->alias));
            $table->modified = $now;
            $table->modified_by = (int) $user->id;
            $cid = self::store($table, [], 'Clergy');
        } else {
            $alias = Admin::uniqueAlias($cat, Admin::slug($name, 100) ?: 'cleric');
            $cid = self::store($table, [
                'title' => $name, 'alias' => $alias, 'catid' => $cat, 'state' => 1, 'access' => 1, 'language' => '*',
                'introtext' => '', 'fulltext' => '', 'created' => $now, 'created_by' => (int) $user->id, 'publish_up' => $now, 'featured' => 0,
                'images' => json_encode(self::photoAfter(null, 'clergy', $alias)), 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}',
                'metakey' => '', 'metadesc' => '', 'note' => 'admin-form',
            ], 'Clergy');
        }
        if (!$cid) {
            return ['ok' => false, 'error' => 'Fișa nu a putut fi salvată.'];
        }
        Admin::writeFields($cid, [
            'last-name' => $last, 'clergy-group' => $group, 'clergy-title' => $title,
            'clergy-title-ro' => $custom[0], 'clergy-title-en' => $custom[1], 'clergy-title-es' => $custom[2],
            'clergy-status' => $status, 'clergy-email' => $email, 'clergy-phone' => self::str($p->getString('phone'), 60),
            'clergy-parishes' => self::subWrite($asg, self::SUB_ASG),
        ]);
        Admin::ensureWorkflow(array_values($cats));
        $row = self::clergy($cid)[0] ?? null;
        return $row ? ['ok' => true, 'item' => $row] : ['ok' => false, 'error' => 'Fișa a fost salvată, dar nu poate fi citită. Reîncărcați pagina.'];
    }

    private static function clTrash(int $id): array
    {
        $user = Factory::getApplication()->getIdentity();
        $a = self::load($id, array_values(self::clCats()));
        if (!$a) {
            return ['ok' => false, 'error' => 'Fișa nu mai există.'];
        }
        if (!self::rights($a)['del']) {
            return ['ok' => false, 'error' => 'Nu aveți dreptul să ștergeți această fișă.'];
        }
        if (!Admin::table()->publish([$id], -2, (int) $user->id)) {
            return ['ok' => false, 'error' => 'Fișa nu a putut fi ștearsă.'];
        }
        return ['ok' => true];
    }

    /* ------------------------------------------------------------------ page */

    public static function panel(bool $par, bool $cl, string $mode): string
    {
        $data = [
            'api' => Admin::here() . '?mitadm=api', 'token' => \Joomla\CMS\Session\Session::getFormToken(),
            'can' => ['par' => $par, 'cl' => $cl], 'mode' => $mode,
            'P' => self::parishes(), 'C' => self::clergy(),
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<div class="mif-wrap md">' . self::html() . '</div><script type="application/json" id="md-data">' . $json . '</script>' . self::js();
    }

    private static function html(): string
    {
        return <<<'HTML'
<form class="mif-card" id="md-pf" novalidate hidden>
 <div class="ok" id="md-pok" hidden></div>
 <h2 id="md-pft">Parohie nouă</h2><p class="hint md-sub" id="md-pfs">Completați ce știți; restul se poate adăuga oricând.</p>
 <details class="sec" open><summary>Nume și tip<span class="sum" id="md-s1"></span></summary><div class="bd">
  <div class="f"><span class="lab">Tipul</span><div class="chips" id="md-pType"></div></div>
  <div class="row"><div class="f"><label for="md-pDio">Eparhia</label><select id="md-pDio"></select></div><div class="f"><label for="md-pDea">Protopopiatul</label><select id="md-pDea"></select></div></div>
  <div class="f"><label for="md-pRo">Numele în română</label><input type="text" id="md-pRo" maxlength="250" placeholder="Biserica „Sfântul Nicolae”"></div>
  <div class="row"><div class="f"><label for="md-pEn">English <span class="opt">(opțional)</span></label><input type="text" id="md-pEn" maxlength="250" placeholder="Saint Nicholas Romanian Orthodox Church"></div><div class="f"><label for="md-pEs">Español <span class="opt">(opțional)</span></label><input type="text" id="md-pEs" maxlength="250" placeholder="Iglesia Ortodoxa Rumana de San Nicolás"></div></div>
 </div></details>
 <details class="sec"><summary>Adresă și contact<span class="sum" id="md-s2"></span></summary><div class="bd">
  <div class="f"><label for="md-pStreet">Strada și numărul</label><input type="text" id="md-pStreet" maxlength="250"></div>
  <div class="row3"><div class="f"><label for="md-pCity">Orașul</label><input type="text" id="md-pCity" maxlength="120"></div><div class="f"><label for="md-pState">Statul / provincia</label><input type="text" id="md-pState" maxlength="60"></div><div class="f"><label for="md-pZip">Codul poștal</label><input type="text" id="md-pZip" maxlength="20"></div></div>
  <div class="row"><div class="f"><label for="md-pCountry">Țara</label><input type="text" id="md-pCountry" maxlength="60" list="md-countries"><datalist id="md-countries"><option value="USA"><option value="Canada"><option value="Argentina"><option value="Brazil"><option value="Chile"><option value="Colombia"><option value="Ecuador"><option value="Mexico"><option value="Venezuela"></datalist></div><div class="f"><label for="md-pAt">Slujbele se țin la <span class="opt">(dacă e altă biserică)</span></label><input type="text" id="md-pAt" maxlength="250"></div></div>
  <div class="f"><label for="md-pGeo">Locul pe hartă</label><div class="geo"><input type="text" id="md-pGeo" placeholder="41.97906, -87.80023"><button type="button" class="btn sec" id="md-pGeoBtn">Caută după adresă</button></div><p class="hint" id="md-pGeoHint">Sau copiați coordonatele din Google Maps (clic dreapta pe locul bisericii).</p></div>
  <div class="f"><label for="md-pMail">Adresa poștală <span class="opt">(dacă diferă)</span></label><textarea id="md-pMail" maxlength="500"></textarea></div>
  <div class="row"><div class="f"><label for="md-pPhone">Telefon</label><input type="text" id="md-pPhone" maxlength="60" inputmode="tel"></div><div class="f"><label for="md-pEmail">E-mail</label><input type="text" id="md-pEmail" maxlength="120" inputmode="email"></div></div>
  <div class="row"><div class="f"><label for="md-pWeb">Site web</label><input type="text" id="md-pWeb" maxlength="400" placeholder="https://" inputmode="url"></div><div class="f"><label for="md-pFb">Facebook</label><input type="text" id="md-pFb" maxlength="400" placeholder="https://facebook.com/…" inputmode="url"></div></div>
 </div></details>
 <details class="sec"><summary>Programul slujbelor<span class="sum" id="md-s3"></span></summary><div class="bd">
  <p class="hint">Ziua și slujba se traduc singure în engleză și spaniolă. Pentru altceva alegeți „Scriu eu”.</p>
  <div class="rows" id="md-pSched"></div><button type="button" class="add" id="md-addSched">+ Adaugă o slujbă</button>
 </div></details>
 <details class="sec"><summary>Hramuri<span class="sum" id="md-s4"></span></summary><div class="bd">
  <div class="rows" id="md-pFeasts"></div><button type="button" class="add" id="md-addFeast">+ Adaugă un hram</button>
 </div></details>
 <details class="sec" id="md-pClBox"><summary>Clerici<span class="sum" id="md-s5"></span></summary><div class="bd">
  <p class="hint">Aceleași atribuiri apar și în fișa fiecărui cleric.</p>
  <div class="rows" id="md-pClergy"></div><button type="button" class="add" id="md-addAsg">+ Adaugă un cleric</button>
 </div></details>
 <details class="sec"><summary>Consiliul parohial și persoane de contact<span class="sum" id="md-s6"></span></summary><div class="bd">
  <div class="rows" id="md-pLay"></div><button type="button" class="add" id="md-addLay">+ Adaugă o persoană</button>
 </div></details>
 <details class="sec"><summary>Prezentare și fotografie<span class="sum" id="md-s7"></span></summary><div class="bd">
  <div class="f"><span class="lab">Fotografia bisericii</span><div class="photo"><div class="pv" id="md-pPhotoPv">fără fotografie</div><label class="pick">Alegeți o fotografie<input type="file" accept="image/*" id="md-pPhoto"></label><button type="button" class="link" id="md-pPhotoX" hidden>Scoate fotografia</button></div></div>
  <div class="f"><span class="lab">Despre parohie</span>
   <div class="mng-ltabs" role="tablist" aria-label="Limba"><button type="button" role="tab" data-l="ro" aria-selected="true">Română</button><button type="button" role="tab" data-l="en" aria-selected="false">English</button><button type="button" role="tab" data-l="es" aria-selected="false">Español</button></div>
   <div class="mng-ed rte" id="md-pAbout" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Despre parohie"></div></div>
 </div></details>
 <div class="actions"><button class="btn" type="submit" id="md-pSave">Salvează parohia</button><button class="btn sec" type="button" id="md-pCancel" hidden>Renunță</button><span class="err" id="md-pErr" role="alert"></span></div>
</form>

<form class="mif-card" id="md-cf" novalidate hidden>
 <div class="ok" id="md-cok" hidden></div>
 <h2 id="md-cft">Cleric nou</h2><p class="hint md-sub">Așa va apărea în directorul clerului:</p>
 <div class="prev"><div class="av" id="md-cAv">?</div><div><div class="rk" id="md-cRk"></div><b id="md-cNm">Prenume Nume</b><div class="ps" id="md-cPs">Fără parohie</div></div></div>
 <details class="sec" open><summary>Nume și rang<span class="sum" id="md-c1"></span></summary><div class="bd">
  <div class="row"><div class="f"><label for="md-cName">Prenumele și numele</label><input type="text" id="md-cName" maxlength="200" placeholder="Vasile Mureșan"></div><div class="f"><label for="md-cLast">Numele de familie</label><input type="text" id="md-cLast" maxlength="100"><p class="hint">Pentru ordinea alfabetică. Se completează singur.</p></div></div>
  <div class="f"><span class="lab">Treapta</span><div class="chips" id="md-cGroup"></div></div>
  <div class="f"><label for="md-cTitle">Titlul de adresare</label><select id="md-cTitle"></select></div>
  <div class="row3" id="md-cCustom" hidden><input type="text" id="md-cTro" maxlength="100" placeholder="Română" aria-label="Titlu în română"><input type="text" id="md-cTen" maxlength="100" placeholder="English" aria-label="Title in English"><input type="text" id="md-cTes" maxlength="100" placeholder="Español" aria-label="Título en español"></div>
  <div class="row"><div class="f"><label for="md-cDio">Eparhia</label><select id="md-cDio"></select></div><div class="f"><span class="lab">Starea</span><div class="chips" id="md-cStatus"></div></div></div>
 </div></details>
 <details class="sec" open><summary>Parohii<span class="sum" id="md-c2"></span></summary><div class="bd">
  <p class="hint">Același cleric apare automat și în pagina fiecărei parohii.</p>
  <div class="rows" id="md-cPar"></div><button type="button" class="add" id="md-addCpar">+ Adaugă o parohie</button>
 </div></details>
 <details class="sec"><summary>Contact și fotografie<span class="sum" id="md-c3"></span></summary><div class="bd">
  <div class="row"><div class="f"><label for="md-cPhone">Telefon</label><input type="text" id="md-cPhone" maxlength="60" inputmode="tel"></div><div class="f"><label for="md-cEmail">E-mail</label><input type="text" id="md-cEmail" maxlength="120" inputmode="email"></div></div>
  <div class="f"><span class="lab">Fotografie</span><div class="photo round"><div class="pv" id="md-cPhotoPv">?</div><label class="pick">Alegeți o fotografie<input type="file" accept="image/*" id="md-cPhoto"></label><button type="button" class="link" id="md-cPhotoX" hidden>Scoate fotografia</button></div><p class="hint">În director fotografia apare ca dreptunghi (5:4), păstrând partea de sus, unde este fața. Cel mai bine arată un portret de la piept în sus.</p></div>
 </div></details>
 <div class="actions"><button class="btn" type="submit" id="md-cSave">Salvează</button><button class="btn sec" type="button" id="md-cCancel" hidden>Renunță</button><span class="err" id="md-cErr" role="alert"></span></div>
</form>

<aside class="mif-card side">
 <h2 id="md-lt">Parohiile</h2>
 <div class="mls"><input type="search" id="md-q" placeholder="Căutați după nume sau oraș" aria-label="Căutare"><select id="md-dioF" aria-label="Eparhia"></select></div>
 <p class="hint" id="md-cnt"></p>
 <div class="list" id="md-list"></div>
</aside>
HTML;
    }

    public static function css(): string
    {
        return <<<'HTML'
<style>
/* Administrare: clergy and parish tabs (Mitropolia plugin). Uses the .mif tokens. */
.md .md-sub{margin:-12px 0 16px}
.md details.sec{border:1px solid var(--line);border-radius:8px;margin-bottom:12px;background:#FCFAF5}
.md details.sec>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;padding:14px 16px;font-weight:700;color:var(--navy);font-size:17px}
.md details.sec>summary::-webkit-details-marker{display:none}
.md details.sec>summary::after{content:'+';font-size:22px;line-height:1;color:var(--blue);margin-left:8px}
.md details.sec[open]>summary::after{content:'–'}
.md details.sec>summary .sum{font-weight:400;font-size:14px;color:var(--soft);margin-left:auto;text-align:right}
.md details.sec .bd{padding:14px 16px 6px;border-top:1px solid var(--line);background:#fff;border-radius:0 0 8px 8px}
.md .row3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.md textarea,.md input[type=email]{width:100%;min-height:80px;border:1px solid var(--line2);border-radius:6px;padding:10px 12px;font:inherit;font-size:17px;color:var(--ink);background:#fff;margin:0;resize:vertical}
.md .rows{display:flex;flex-direction:column;gap:8px;margin:0 0 12px}
.md .rw{display:grid;gap:8px;align-items:center;border:1px solid var(--line);border-radius:6px;padding:8px;background:#fff}
.md .rw.sched{grid-template-columns:minmax(0,1.1fr) minmax(0,1.4fr) 110px auto}
.md .rw.feast{grid-template-columns:minmax(0,1fr) auto}
.md .rw.asg{grid-template-columns:minmax(0,1.6fr) minmax(0,1fr) auto}
.md .rw.lay{grid-template-columns:minmax(0,1fr) auto}
.md .rw input[type=text],.md .rw select{min-height:44px;font-size:16px}
.md .rw .tr{grid-column:1/-1;font-size:13px;color:var(--soft);margin-top:-2px}
.md .rw .custom{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.md .rw .custom .cl{grid-column:1/-1;font-size:13px;font-weight:700;color:var(--navy)}
.md .rw .full{grid-column:1/-1}
.md .rw .ctl{display:flex;gap:2px}
.md .rw .ctl button{border:1px solid var(--line2);background:#fff;border-radius:4px;min-width:36px;min-height:36px;font:inherit;font-size:15px;color:var(--navy);cursor:pointer}
.md .rw .ctl button.x{color:var(--red)}
.md .rw .chips .chip{min-height:36px;padding:6px 12px;font-size:14px}
.md .add{border:1px dashed var(--line2);background:none;border-radius:6px;font:inherit;font-weight:700;color:var(--blue);padding:9px 14px;cursor:pointer;margin-bottom:12px}
.md .geo{display:flex;gap:8px;flex-wrap:wrap}
.md .geo input{flex:1 1 200px;width:auto}
.md .geo .btn{min-height:48px;padding:8px 16px;font-size:15px}
.md .photo{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.md .photo .pv{width:140px;max-width:100%;aspect-ratio:4/3;border-radius:6px;background:var(--sand) center/cover no-repeat;display:grid;place-items:center;color:var(--muted);font-size:13px;text-align:center}
.md .photo.round .pv{width:150px;aspect-ratio:5/4;border-radius:6px;background-position:50% 25%;font-family:'Baskervville',Georgia,serif;font-size:30px;color:var(--navy)}
.md .photo .pick{border:1px solid var(--line2);border-radius:6px;padding:10px 14px;font-weight:700;color:var(--blue);cursor:pointer;background:#fff;position:relative}
.md .photo .pick input{position:absolute;opacity:0;width:1px;height:1px;left:0;top:0}
.md .rte{min-height:160px}
.md .prev{border:1px solid var(--line);border-radius:8px;padding:14px;display:grid;grid-template-columns:100px minmax(0,1fr);gap:14px;align-items:center;margin-bottom:16px;background:#FCFAF5}
.md .prev .av{width:100px;aspect-ratio:5/4;border-radius:5px;background:var(--sand) 50% 25%/cover no-repeat;display:grid;place-items:center;font-family:'Baskervville',Georgia,serif;color:var(--navy);font-size:22px}
.md .prev .rk{font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--red);min-height:1em}
.md .prev b{display:block;font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:22px;color:var(--navy);line-height:1.2;overflow-wrap:anywhere}
.md .prev .ps{font-size:14px;color:var(--muted)}
.md .dit{background:#fff;border:1px solid var(--line);border-radius:6px;padding:10px 12px;display:grid;grid-template-columns:44px minmax(0,1fr);gap:12px;align-items:start}
.md .dit.on{border-color:var(--blue);box-shadow:inset 3px 0 0 var(--blue)}
.md .dit .ic{width:44px;height:44px;border-radius:6px;background:var(--sand) 50% 25%/cover;display:grid;place-items:center;font-family:'Baskervville',Georgia,serif;color:var(--navy);font-size:16px}
.md .dit .ic.sq{border-radius:6px;font-family:'Source Sans 3',sans-serif;font-size:11px;font-weight:700;letter-spacing:.06em}
.md .dit b{display:block;color:var(--navy);font-size:16px;line-height:1.3;overflow-wrap:anywhere}
.md .dit .meta{font-size:13px;color:var(--muted)}
.md .dit .ia{display:flex;gap:16px;margin-top:4px}
.md .dit .ia button{border:0;background:none;font:inherit;font-size:14px;font-weight:700;color:var(--blue);padding:4px 0;cursor:pointer}
.md .dit .ia button.del{color:var(--red)}
.md .dit .confirm{flex-direction:column;align-items:flex-start}
.md .dit .confirm div{display:flex;gap:8px;flex-wrap:wrap}
.md .ptype{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:3px;padding:1px 6px;border:1px solid #C9D3E6;color:var(--blue);background:#EEF2F9}
.md .ptype.cathedral{color:var(--red);background:#FBEDEE;border-color:#EBC5C8}
.md .ptype.monastery{color:var(--gold);background:#FBF1D6;border-color:#ECD9A0}
.md .ok a{color:inherit}
@media (max-width:560px){.md .row3{grid-template-columns:minmax(0,1fr)}.md .rw.sched{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.md .rw.sched .ctl{grid-column:1/-1;justify-content:flex-end}.md .rw.asg{grid-template-columns:minmax(0,1fr)}.md .rw .custom{grid-template-columns:minmax(0,1fr)}}
</style>
HTML;
    }

    private static function js(): string
    {
        return '<script>' . file_get_contents(__DIR__ . '/admin-dir.js') . '</script>';
    }
}
