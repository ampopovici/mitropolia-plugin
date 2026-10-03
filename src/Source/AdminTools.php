<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Super-user tools, called from the Joomla administrator (?mittool=api, POST, form token):
 * - old_zip: fetches images from the old site (www.mitropolia.us only) and packs them into
 *   images/_export/<name>.zip for download.
 * - es_list / es_src / es_save: Spanish translations of Romanian news. es_save creates or updates the
 *   Spanish article in news-es with the Romanian article's date, author, images and custom fields, and
 *   links it with the Romanian and English versions (associations).
 * - doc_copy / doc_tags / doc_save: the Documents import. doc_copy copies PDFs from the old site's
 *   /institutional/ folder to files/institutional/ (only when missing), doc_tags creates the document tags
 *   per language, doc_save creates one document article (documents-ro/en/es) with its file, date, tags and
 *   association.
 * - pub_*: the publications (Revista Credința, Almanahul Credința). pub_cats creates the two "All languages"
 *   categories and assigns the publication fields to them; pub_copy copies issue PDFs from the old site's /pdf/
 *   folder; pub_save creates the issue articles; pub_cover stores a cover (JPEG made from the PDF's first page).
 */
final class AdminTools
{
    public static function api(): void
    {
        $app = Factory::getApplication();
        try {
            $out = self::run();
        } catch (\Throwable $x) {
            Log::add('AdminTools: ' . $x->getMessage() . ' @' . $x->getLine(), Log::WARNING, 'mitropolia');
            $out = ['ok' => false, 'error' => $x->getMessage()];
        }
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->sendHeaders();
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        $app->close();
    }

    private static function run(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user || $user->guest || !$user->authorise('core.admin')) {
            return ['ok' => false, 'error' => 'super user only'];
        }
        if (strtoupper($app->getInput()->getMethod()) !== 'POST' || !Session::checkToken('post')) {
            return ['ok' => false, 'error' => 'bad token'];
        }
        $p = $app->getInput()->post;
        switch ($p->getCmd('action')) {
            case 'old_zip':
                return self::oldZip(json_decode((string) $p->getRaw('files'), true) ?: [], $p->getCmd('name') ?: 'export');
            case 'old_put':
                return self::oldPut(json_decode((string) $p->getRaw('files'), true) ?: []);
            case 'cat_dump':
                $db = Admin::db();
                return ['ok' => true, 'items' => $db->setQuery($db->getQuery(true)->select(['c.id', 'c.alias', 'c.parent_id', 'c.language', 'c.title', 'c.published', '(SELECT COUNT(*) FROM #__content a WHERE a.catid = c.id AND a.state IN (0,1)) AS n'])
                    ->from($db->quoteName('#__categories', 'c'))->where('c.extension = ' . $db->quote('com_content'))->order('c.lft'))->loadObjectList()];
            case 'es_list':
                return self::esList($p->getInt('year'));
            case 'es_src':
                return self::esSrc($p->getInt('id'));
            case 'es_save':
                return self::esSave();
            case 'doc_copy':
                return self::docCopy(json_decode((string) $p->getRaw('files'), true) ?: []);
            case 'doc_tags':
                return self::docTags(json_decode((string) $p->getRaw('tags'), true) ?: []);
            case 'doc_tag_add':
                return self::docTagAdd(array_map('intval', json_decode((string) $p->getRaw('ids'), true) ?: []), (string) $p->getString('tag'));
            case 'news_peek':
                return self::newsPeek($p->getInt('id'));
            case 'news_import':
                return self::newsImport(json_decode((string) $p->getRaw('item'), true) ?: []);
            case 'old_page':
                return self::oldPage((string) $p->getString('url'));
            case 'pub_diag':
                return self::pubDiag();
            case 'pub_cats':
                return self::pubCats();
            case 'pub_copy':
                return self::pubCopy(json_decode((string) $p->getRaw('files'), true) ?: []);
            case 'pub_save':
                return self::pubSave(json_decode((string) $p->getRaw('items'), true) ?: []);
            case 'pub_make':
                return self::pubMake(array_map('intval', json_decode((string) $p->getRaw('ids'), true) ?: []));
            case 'pub_cover':
                return self::pubCover($p->getInt('id'), (string) $p->getRaw('jpeg'));
            case 'doc_save':
                return self::docSave(json_decode((string) $p->getRaw('doc'), true) ?: []);
            case 'site_asset':
                $data = (string) $p->getRaw('data');
                return self::siteAsset((string) $p->getCmd('name'), $p->getInt('b64') ? (string) base64_decode($data, true) : $data, $p->getCmd('dir') === 'partners' ? 'partners' : 'site');
            case 'logo_get':
                return self::logoGet((string) $p->getString('url'), (string) $p->getCmd('name'));
            case 'page_layout':
                return self::pageLayout($p->getInt('id'), (string) $p->getRaw('layout'));
        }
        return ['ok' => false, 'error' => 'unknown action'];
    }

    /* ------------------------------------------------------------------ old images */

    /** files: [{url: "https://www.mitropolia.us/images/...", as: "path/inside/zip.jpg"}] */
    private static function oldZip(array $files, string $name): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive missing'];
        }
        $dir = JPATH_ROOT . '/images/_export';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'mkdir'];
        }
        $zipPath = $dir . '/' . $name . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'zip open'];
        }
        $report = [];
        foreach (array_slice($files, 0, 100) as $f) {
            $url = (string) ($f['url'] ?? '');
            $as = trim(str_replace(['..', '\\'], ['', '/'], (string) ($f['as'] ?? '')), '/');
            if (!preg_match('#^https://(www\.)?mitropolia\.us/#', $url) || $as === '') {
                $report[] = [$url, 'skipped'];
                continue;
            }
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mitropolia staging'], 'ssl' => ['verify_peer' => true]]);
            $data = @file_get_contents($url, false, $ctx);
            if ($data === false || strlen($data) < 500) {
                $report[] = [$url, 'failed'];
                continue;
            }
            $zip->addFromString($as, $data);
            $report[] = [$url, strlen($data)];
        }
        if (!empty($files[0]['readme'])) {
            $zip->addFromString('CITIȚI.txt', (string) $files[0]['readme']);
        }
        $zip->close();
        return ['ok' => true, 'url' => rtrim(Uri::root(), '/') . '/images/_export/' . rawurlencode($name) . '.zip', 'size' => @filesize($zipPath), 'files' => $report];
    }

    /**
     * files: [{url: "https://www.mitropolia.us/...", to: "images/migrated/..."}]
     * Copies each image to its place on this site, only when nothing is there yet.
     */
    private static function oldPut(array $files): array
    {
        $report = [];
        foreach (array_slice($files, 0, 100) as $f) {
            $url = (string) ($f['url'] ?? '');
            $to = Admin::safeRel((string) ($f['to'] ?? ''));
            if (!preg_match('#^https://(www\.)?mitropolia\.us/#', $url) || $to === '' || !preg_match('#\.(jpe?g|png|webp)$#i', $to)) {
                $report[] = [$to, 'skipped'];
                continue;
            }
            $abs = JPATH_ROOT . '/' . $to;
            if (is_file($abs)) {
                $report[] = [$to, 'exists'];
                continue;
            }
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mitropolia staging']]);
            $data = @file_get_contents($url, false, $ctx);
            if ($data === false || strlen($data) < 500 || !@getimagesizefromstring($data)) {
                $report[] = [$to, 'download failed'];
                continue;
            }
            if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
                $report[] = [$to, 'mkdir failed'];
                continue;
            }
            $report[] = [$to, @file_put_contents($abs, $data) ? strlen($data) : 'write failed'];
            @chmod($abs, 0644);
        }
        return ['ok' => true, 'files' => $report];
    }

    /* ------------------------------------------------------------------ Spanish news */

    private static function cats(): array
    {
        return ['ro' => Admin::catByAlias('news-ro'), 'en' => Admin::catByAlias('news-en'), 'es' => Admin::catByAlias('news-es')];
    }

    /** Associated ids by language tag. */
    private static function assoc(int $id): array
    {
        $db = Admin::db();
        $key = $db->setQuery($db->getQuery(true)->select($db->quoteName('key'))->from('#__associations')
            ->where('context = ' . $db->quote('com_content.item'))->where('id = ' . $id), 0, 1)->loadResult();
        $out = [];
        if ($key) {
            $rows = $db->setQuery('SELECT a.id, a.language FROM #__associations s INNER JOIN #__content a ON a.id = s.id'
                . ' WHERE s.context = ' . $db->quote('com_content.item') . ' AND s.' . $db->quoteName('key') . ' = ' . $db->quote($key)
                . ' AND a.state <> -2')->loadObjectList();
            foreach ($rows as $r) {
                $out[(string) $r->language] = (int) $r->id;
            }
        }
        $out['ro-RO'] = $out['ro-RO'] ?? $id;
        return $out;
    }

    private static function esList(int $year): array
    {
        $c = self::cats();
        $db = Admin::db();
        $rows = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'publish_up', 'state', 'CHAR_LENGTH(introtext) + CHAR_LENGTH(' . $db->quoteName('fulltext') . ') AS len'])
            ->from('#__content')->where('catid = ' . (int) $c['ro'])->where('state IN (0,1)')
            ->where('publish_up >= ' . $db->quote($year . '-01-01 00:00:00'))->where('publish_up < ' . $db->quote(($year + 1) . '-01-01 00:00:00'))
            ->order('publish_up ASC'))->loadObjectList();
        $out = [];
        foreach ($rows as $r) {
            $as = self::assoc((int) $r->id);
            $out[] = ['id' => (int) $r->id, 'dt' => substr((string) $r->publish_up, 0, 10), 'title' => (string) $r->title, 'state' => (int) $r->state,
                'len' => (int) $r->len, 'en' => $as['en-US'] ?? 0, 'es' => $as['es-ES'] ?? 0];
        }
        return ['ok' => true, 'items' => $out];
    }

    private static function esSrc(int $id): array
    {
        $db = Admin::db();
        $a = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'introtext', $db->quoteName('fulltext'), 'metadesc', 'publish_up', 'catid'])
            ->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$a || (int) $a->catid !== self::cats()['ro']) {
            return ['ok' => false, 'error' => 'not a Romanian news article'];
        }
        $as = self::assoc($id);
        $en = !empty($as['en-US']) ? $db->setQuery($db->getQuery(true)->select('title')->from('#__content')->where('id = ' . (int) $as['en-US']))->loadResult() : '';
        return ['ok' => true, 'id' => $id, 'dt' => substr((string) $a->publish_up, 0, 10), 'title' => (string) $a->title, 'intro' => (string) $a->introtext,
            'full' => (string) $a->fulltext, 'metadesc' => (string) $a->metadesc, 'en_title' => (string) $en, 'es' => $as['es-ES'] ?? 0];
    }

    private static function esSave(): array
    {
        $app = Factory::getApplication();
        $p = $app->getInput()->post;
        $c = self::cats();
        $id = $p->getInt('id');
        $db = Admin::db();
        $ro = $db->setQuery($db->getQuery(true)->select('*')->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$ro || (int) $ro->catid !== $c['ro'] || !$c['es']) {
            return ['ok' => false, 'error' => 'not a Romanian news article'];
        }
        $title = Admin::plainTitle((string) $p->getString('title'));
        $intro = trim((string) $p->getRaw('intro'));
        $full = trim((string) $p->getRaw('full'));
        if ($title === '' || $intro === '') {
            return ['ok' => false, 'error' => 'title and text are required'];
        }
        $as = self::assoc($id);
        $esId = (int) ($as['es-ES'] ?? 0);
        $now = Factory::getDate()->toSql();
        $table = Admin::table();
        if ($esId) {
            $table->load($esId);
            $table->title = $title;
            $table->introtext = $intro;
            $table->fulltext = $full;
            $table->metadesc = mb_substr(trim((string) $p->getString('metadesc')), 0, 300);
            $table->modified = $now;
            $table->modified_by = (int) $app->getIdentity()->id;
        } else {
            $table->bind([
                'title' => $title, 'alias' => Admin::uniqueAlias($c['es'], Admin::slug($title, 120) ?: 'noticia'),
                'catid' => $c['es'], 'state' => (int) $ro->state, 'access' => (int) $ro->access, 'language' => 'es-ES',
                'introtext' => $intro, 'fulltext' => $full, 'created' => (string) $ro->created, 'created_by' => (int) $ro->created_by,
                'created_by_alias' => (string) $ro->created_by_alias, 'publish_up' => (string) $ro->publish_up, 'featured' => (int) $ro->featured,
                'images' => (string) $ro->images, 'urls' => (string) $ro->urls ?: '{}', 'attribs' => (string) $ro->attribs ?: '{}',
                'metadata' => (string) $ro->metadata ?: '{}', 'metakey' => '', 'metadesc' => mb_substr(trim((string) $p->getString('metadesc')), 0, 300),
                'note' => 'traducere ES (Claude), din RO ' . $id,
            ]);
        }
        if (!$table->check() || !$table->store()) {
            return ['ok' => false, 'error' => 'store failed' . (method_exists($table, 'getError') ? ': ' . $table->getError() : '')];
        }
        $esId = (int) $table->id;
        // custom fields: the same values as the Romanian article (gallery folder, attachments, location...)
        $vals = $db->setQuery($db->getQuery(true)->select(['field_id', 'value'])->from('#__fields_values')->where('item_id = ' . $db->quote((string) $id)))->loadObjectList();
        $db->setQuery($db->getQuery(true)->delete('#__fields_values')->where('item_id = ' . $db->quote((string) $esId)))->execute();
        foreach ($vals as $v) {
            $o = (object) ['field_id' => (int) $v->field_id, 'item_id' => (string) $esId, 'value' => (string) $v->value];
            $db->insertObject('#__fields_values', $o);
        }
        $ids = $as;
        $ids['es-ES'] = $esId;
        $ids = array_filter($ids);
        $db->setQuery($db->getQuery(true)->delete('#__associations')->where('context = ' . $db->quote('com_content.item'))
            ->where('id IN (' . implode(',', array_map('intval', $ids)) . ')'))->execute();
        $key = md5(json_encode($ids));
        foreach ($ids as $aid) {
            $o = (object) ['id' => (int) $aid, 'context' => 'com_content.item', 'key' => $key, 'parent_id' => 0];
            $db->insertObject('#__associations', $o);
        }
        Admin::ensureWorkflow([$c['es']]);
        return ['ok' => true, 'es' => $esId, 'assoc' => $ids];
    }

    /* ------------------------------------------------------------------ documents import */

    /** files: ["docs/statut-mi-ro.pdf", ...] relative to /institutional/ */
    private static function docCopy(array $files): array
    {
        $report = [];
        foreach (array_slice($files, 0, 60) as $rel) {
            $rel = (string) $rel;
            if (!preg_match('#^(docs|sacraments|resumes|policies)/[a-z0-9][a-z0-9-]*\.pdf$#', $rel)) {
                $report[] = [$rel, 'skipped'];
                continue;
            }
            $abs = JPATH_ROOT . '/files/institutional/' . $rel;
            if (is_file($abs)) {
                $report[] = [$rel, 'exists', filesize($abs), md5_file($abs)];
                continue;
            }
            $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'Mitropolia staging']]);
            $data = @file_get_contents('https://www.mitropolia.us/institutional/' . $rel, false, $ctx);
            if ($data === false || strncmp($data, '%PDF', 4) !== 0) {
                $report[] = [$rel, 'download failed'];
                continue;
            }
            if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
                $report[] = [$rel, 'mkdir failed'];
                continue;
            }
            $report[] = [$rel, @file_put_contents($abs, $data) ? 'copied' : 'write failed', strlen($data), md5($data)];
            @chmod($abs, 0644);
        }
        return ['ok' => true, 'files' => $report];
    }

    /** tags: [{title, alias, lang: "ro-RO"}]; creates the missing ones at the top level. */
    private static function docTags(array $tags): array
    {
        $db = Admin::db();
        $out = [];
        foreach (array_slice($tags, 0, 40) as $t) {
            $title = trim((string) ($t['title'] ?? ''));
            $alias = trim((string) ($t['alias'] ?? ''));
            $lang = (string) ($t['lang'] ?? '');
            if ($title === '' || !preg_match('#^[a-z0-9-]+$#', $alias) || !in_array($lang, ['ro-RO', 'en-US', 'es-ES'], true)) {
                $out[] = [$alias, 'skipped'];
                continue;
            }
            $id = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__tags')->where('alias = ' . $db->quote($alias))->where('published >= 0'), 0, 1)->loadResult();
            if ($id) {
                $out[] = [$alias, 'exists', $id];
                continue;
            }
            $table = Factory::getApplication()->bootComponent('com_tags')->getMVCFactory()->createTable('Tag', 'Administrator');
            $table->setLocation(1, 'last-child');
            $table->bind(['title' => $title, 'alias' => $alias, 'language' => $lang, 'published' => 1, 'access' => 1, 'parent_id' => 1,
                'description' => '', 'note' => 'Documente', 'params' => '{}', 'metadata' => '{}', 'images' => '{}', 'urls' => '{}']);
            if (!$table->check() || !$table->store()) {
                $out[] = [$alias, 'failed: ' . (method_exists($table, 'getError') ? $table->getError() : '')];
                continue;
            }
            $table->rebuildPath($table->id);
            $out[] = [$alias, 'created', (int) $table->id];
        }
        return ['ok' => true, 'tags' => $out];
    }

    /** doc: {lang: ro|en|es, title, file: "files/institutional/..", date: "Y-m-d"|'' , tags: [alias], assoc: id} */
    private static function docSave(array $d): array
    {
        $app = Factory::getApplication();
        $db = Admin::db();
        $l = (string) ($d['lang'] ?? '');
        $tag = ['ro' => 'ro-RO', 'en' => 'en-US', 'es' => 'es-ES'][$l] ?? '';
        $cat = $tag ? Admin::catByAlias('documents-' . $l) : 0;
        $title = trim((string) ($d['title'] ?? ''));
        $file = (string) ($d['file'] ?? '');
        if (!$cat || $title === '' || !preg_match('#^files/institutional/[a-z]+/[a-z0-9-]+\.pdf$#', $file) || !is_file(JPATH_ROOT . '/' . $file)) {
            return ['ok' => false, 'error' => 'bad document: ' . $title . ' ' . $file];
        }
        $tagIds = [];
        foreach ((array) ($d['tags'] ?? []) as $a) {
            $tid = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__tags')->where('alias = ' . $db->quote((string) $a))
                ->where('language = ' . $db->quote($tag))->where('published = 1'), 0, 1)->loadResult();
            if (!$tid) {
                return ['ok' => false, 'error' => 'missing tag ' . $a];
            }
            $tagIds[] = $tid;
        }
        $now = Factory::getDate()->toSql();
        $table = Admin::table();
        $table->bind([
            'title' => $title, 'alias' => Admin::uniqueAlias($cat, Admin::slug($title, 120) ?: 'document'),
            'catid' => $cat, 'state' => 1, 'access' => 1, 'language' => $tag, 'introtext' => '', 'fulltext' => '',
            'created' => $now, 'created_by' => (int) $app->getIdentity()->id, 'publish_up' => $now,
            'images' => '{}', 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}', 'metakey' => '', 'metadesc' => '',
            'note' => 'import documente (Claude), ' . basename($file),
        ]);
        $table->newTags = $tagIds;
        if (!$table->check() || !$table->store()) {
            return ['ok' => false, 'error' => 'store failed' . (method_exists($table, 'getError') ? ': ' . $table->getError() : '')];
        }
        $id = (int) $table->id;
        $vals = ['document-pdf' => json_encode(['file' => $file, 'linktext' => ''], JSON_UNESCAPED_SLASHES)];
        $date = (string) ($d['date'] ?? '');
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) {
            $vals['document-date'] = $date . ' 12:00:00';
        }
        Admin::writeFields($id, $vals);
        $other = (int) ($d['assoc'] ?? 0);
        if ($other) {
            $ids = self::assoc($other);
            $ids = array_filter(array_merge($ids, [$tag => $id]));
            $db->setQuery($db->getQuery(true)->delete('#__associations')->where('context = ' . $db->quote('com_content.item'))
                ->where('id IN (' . implode(',', array_map('intval', $ids)) . ')'))->execute();
            $key = md5(json_encode($ids));
            foreach ($ids as $aid) {
                $o = (object) ['id' => (int) $aid, 'context' => 'com_content.item', 'key' => $key, 'parent_id' => 0];
                $db->insertObject('#__associations', $o);
            }
        }
        Admin::ensureWorkflow([$cat]);
        return ['ok' => true, 'id' => $id];
    }

    /** Adds one tag (by alias, in each article's language) to documents, keeping their other tags. */
    private static function docTagAdd(array $ids, string $alias): array
    {
        $db = Admin::db();
        $cats = array_filter([Admin::catByAlias('documents-ro'), Admin::catByAlias('documents-en'), Admin::catByAlias('documents-es')]);
        $out = [];
        foreach (array_slice($ids, 0, 60) as $id) {
            $table = Admin::table();
            if (!$table->load($id) || !in_array((int) $table->catid, $cats, true)) {
                $out[] = [$id, 'not a document'];
                continue;
            }
            $tid = (int) $db->setQuery($db->getQuery(true)->select('id')->from('#__tags')->where('alias = ' . $db->quote($alias))->where('published = 1'), 0, 1)->loadResult();
            if (!$tid) {
                $out[] = [$id, 'missing tag'];
                continue;
            }
            $have = array_map('intval', $db->setQuery($db->getQuery(true)->select('tag_id')->from('#__contentitem_tag_map')
                ->where('type_alias = ' . $db->quote('com_content.article'))->where('content_item_id = ' . (int) $id))->loadColumn());
            if (in_array($tid, $have, true)) {
                $out[] = [$id, 'already'];
                continue;
            }
            $table->newTags = array_merge($have, [$tid]);
            $out[] = [$id, $table->store() ? 'tagged' : 'failed'];
        }
        return ['ok' => true, 'items' => $out];
    }

    /* ------------------------------------------------------------------ publications */

    private static function pubDiag(): array
    {
        $im = class_exists('Imagick');
        $fmts = [];
        if ($im) {
            try {
                $fmts = \Imagick::queryFormats('PDF');
            } catch (\Throwable $x) {
            }
        }
        $fns = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return ['ok' => true, 'imagick' => $im, 'pdf' => $fmts, 'exec' => function_exists('exec') && !in_array('exec', $fns, true), 'gd' => function_exists('imagecreatefromstring')];
    }

    private static function pubCats(): array
    {
        $db = Admin::db();
        $out = [];
        foreach (['revista-credinta' => 'Revista Credința', 'almanahul-credinta' => 'Almanahul Credința'] as $alias => $title) {
            $id = Admin::catByAlias($alias);
            if (!$id) {
                $table = Factory::getApplication()->bootComponent('com_categories')->getMVCFactory()->createTable('Category', 'Administrator');
                $table->setLocation(1, 'last-child');
                $table->bind(['title' => $title, 'alias' => $alias, 'extension' => 'com_content', 'published' => 1, 'access' => 1, 'language' => '*',
                    'parent_id' => 1, 'description' => '', 'note' => 'Publicație (ALL)', 'params' => '{}', 'metadata' => '{}']);
                if (!$table->check() || !$table->store()) {
                    return ['ok' => false, 'error' => 'category ' . $alias . ': ' . (method_exists($table, 'getError') ? $table->getError() : '')];
                }
                $table->rebuildPath($table->id);
                $id = (int) $table->id;
            }
            // the publication fields (issue, year, PDF) also apply here
            $fields = $db->setQuery($db->getQuery(true)->select('id')->from('#__fields')
                ->whereIn('name', ['publication-issue', 'publication-year', 'publication-pdf'], \Joomla\Database\ParameterType::STRING))->loadColumn();
            foreach ($fields as $fid) {
                $has = (int) $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from('#__fields_categories')
                    ->where('field_id = ' . (int) $fid)->where('category_id = ' . $id))->loadResult();
                if (!$has) {
                    $o = (object) ['field_id' => (int) $fid, 'category_id' => $id];
                    $db->insertObject('#__fields_categories', $o);
                }
            }
            $out[$alias] = $id;
        }
        return ['ok' => true, 'cats' => $out];
    }

    /** files: [{from: "2026.07.30.pdf", to: "credinta/credinta-2026-2.pdf"}] */
    private static function pubCopy(array $files): array
    {
        $report = [];
        foreach (array_slice($files, 0, 40) as $f) {
            $from = (string) ($f['from'] ?? '');
            $to = (string) ($f['to'] ?? '');
            if (!preg_match('#^[A-Za-z0-9._-]+\.pdf$#', $from) || !preg_match('#^(credinta|almanah)/[a-z0-9-]+\.pdf$#', $to)) {
                $report[] = [$to, 'skipped'];
                continue;
            }
            $abs = JPATH_ROOT . '/files/publications/' . $to;
            if (is_file($abs)) {
                $report[] = [$to, 'exists', filesize($abs)];
                continue;
            }
            $ctx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'Mitropolia staging']]);
            $data = @file_get_contents('https://www.mitropolia.us/pdf/' . rawurlencode($from), false, $ctx);
            if ($data === false || strncmp($data, '%PDF', 4) !== 0) {
                $report[] = [$to, 'download failed'];
                continue;
            }
            if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
                $report[] = [$to, 'mkdir failed'];
                continue;
            }
            $report[] = [$to, @file_put_contents($abs, $data) ? 'copied' : 'write failed', strlen($data)];
            @chmod($abs, 0644);
        }
        return ['ok' => true, 'files' => $report];
    }

    /** items: [{cat: "revista-credinta", title, issue: "2", year: "2026", file: "files/publications/..."}] */
    private static function pubSave(array $items): array
    {
        $app = Factory::getApplication();
        $out = [];
        foreach (array_slice($items, 0, 40) as $d) {
            $cat = Admin::catByAlias((string) ($d['cat'] ?? ''));
            $file = (string) ($d['file'] ?? '');
            $title = trim((string) ($d['title'] ?? ''));
            if (!$cat || !in_array((string) $d['cat'], ['revista-credinta', 'almanahul-credinta'], true) || $title === ''
                || !preg_match('#^files/publications/(credinta|almanah)/[a-z0-9-]+\.pdf$#', $file) || !is_file(JPATH_ROOT . '/' . $file)) {
                $out[] = [$title, 'bad item'];
                continue;
            }
            $now = Factory::getDate()->toSql();
            $table = Admin::table();
            $table->bind([
                'title' => $title, 'alias' => Admin::uniqueAlias($cat, Admin::slug($title, 120) ?: 'numar'),
                'catid' => $cat, 'state' => 1, 'access' => 1, 'language' => '*', 'introtext' => '', 'fulltext' => '',
                'created' => $now, 'created_by' => (int) $app->getIdentity()->id, 'publish_up' => $now,
                'images' => '{}', 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}', 'metakey' => '', 'metadesc' => '',
                'note' => 'import publicații (Claude), ' . basename($file),
            ]);
            if (!$table->check() || !$table->store()) {
                $out[] = [$title, 'store failed'];
                continue;
            }
            $id = (int) $table->id;
            Admin::writeFields($id, [
                'publication-issue' => (string) ($d['issue'] ?? ''),
                'publication-year'  => (string) ($d['year'] ?? ''),
                'publication-pdf'   => json_encode(['file' => $file, 'linktext' => ''], JSON_UNESCAPED_SLASHES),
            ]);
            $out[] = [$title, 'created', $id];
        }
        Admin::ensureWorkflow([Admin::catByAlias('revista-credinta'), Admin::catByAlias('almanahul-credinta')]);
        return ['ok' => true, 'items' => $out];
    }

    /** Stores images/publications/covers/<id>.jpg from a base64 JPEG (the PDF's first page, drawn in the browser). */
    private static function pubCover(int $id, string $b64): array
    {
        $cats = [Admin::catByAlias('revista-credinta'), Admin::catByAlias('almanahul-credinta')];
        $db = Admin::db();
        $cat = (int) $db->setQuery($db->getQuery(true)->select('catid')->from('#__content')->where('id = ' . $id))->loadResult();
        if (!$cat || !in_array($cat, $cats, true)) {
            return ['ok' => false, 'error' => 'not a publication'];
        }
        $data = base64_decode(preg_replace('#^data:image/jpeg;base64,#', '', $b64), true);
        $info = $data ? @getimagesizefromstring($data) : false;
        if (!$info || $info[2] !== IMAGETYPE_JPEG || strlen($data) > 3 * 1048576) {
            return ['ok' => false, 'error' => 'not a jpeg'];
        }
        $dir = JPATH_ROOT . '/images/publications/covers';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'mkdir'];
        }
        $ok = @file_put_contents($dir . '/' . $id . '.jpg', $data);
        @chmod($dir . '/' . $id . '.jpg', 0644);
        return ['ok' => (bool) $ok, 'size' => strlen($data), 'w' => $info[0], 'h' => $info[1]];
    }

    /** Makes covers for issues from their PDFs (server side, Imagick). */
    private static function pubMake(array $ids): array
    {
        $db = Admin::db();
        $out = [];
        foreach (array_slice($ids, 0, 10) as $id) {
            $v = (string) $db->setQuery('SELECT v.value FROM #__fields_values v INNER JOIN #__fields f ON f.id = v.field_id WHERE f.name = ' . $db->quote('publication-pdf')
                . ' AND v.item_id = ' . $db->quote((string) $id))->loadResult();
            $j = json_decode($v, true);
            $file = is_array($j) ? (string) ($j['file'] ?? '') : $v;
            $t = microtime(true);
            @unlink(JPATH_ROOT . '/images/publications/covers/' . $id . '.jpg');
            $ok = $file !== '' && Publications::makeCover($id, $file);
            $out[] = [$id, $ok ? 'ok' : 'failed', round(microtime(true) - $t, 1)];
        }
        return ['ok' => true, 'items' => $out];
    }

    /* ------------------------------------------------------------------ news from the old site */

    /** A staging article as stored: text, images, fields, associations. */
    private static function newsPeek(int $id): array
    {
        $db = Admin::db();
        $a = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'alias', 'catid', 'language', 'introtext', $db->quoteName('fulltext'), 'images', 'urls', 'attribs', 'metadata', 'metadesc', 'created', 'created_by', 'created_by_alias', 'publish_up', 'state', 'featured', 'access', 'note', 'hits'])
            ->from('#__content')->where('id = ' . $id))->loadAssoc();
        if (!$a) {
            return ['ok' => false, 'error' => 'no article'];
        }
        $f = $db->setQuery('SELECT f.name, v.value FROM #__fields_values v INNER JOIN #__fields f ON f.id = v.field_id WHERE v.item_id = ' . $db->quote((string) $id))->loadAssocList('name', 'value');
        $tags = $db->setQuery('SELECT tag_id FROM #__contentitem_tag_map WHERE type_alias = ' . $db->quote('com_content.article') . ' AND content_item_id = ' . $id)->loadColumn();
        return ['ok' => true, 'a' => $a, 'fields' => $f, 'tags' => $tags, 'assoc' => self::assoc($id)];
    }

    /** The article part of a page on the old site (www.mitropolia.us only), for importing. */
    private static function oldPage(string $url): array
    {
        if (!preg_match('#^https://www\.mitropolia\.us/index\.php/(ro|en)/([0-9]+[a-z0-9-]*)?$#', $url)) {
            return ['ok' => false, 'error' => 'bad url'];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mitropolia staging']]);
        $h = @file_get_contents($url, false, $ctx);
        if ($h === false) {
            return ['ok' => false, 'error' => 'download failed'];
        }
        $h = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', '', $h);
        $start = stripos($h, 'itemprop="articleBody"');
        $title = preg_match('#<h2[^>]*itemprop="headline"[^>]*>(.*?)</h2>#is', $h, $m) ? trim(strip_tags($m[1])) : (preg_match('#<title>(.*?)</title>#is', $h, $m) ? trim($m[1]) : '');
        $date = preg_match('#<time[^>]*datetime="([^"]+)"[^>]*itemprop="datePublished"#i', $h, $m) || preg_match('#itemprop="datePublished"[^>]*datetime="([^"]+)"#i', $h, $m) ? $m[1] : '';
        $og = preg_match('#<meta property="og:image" content="([^"]+)"#i', $h, $m) ? $m[1] : '';
        $body = $start !== false ? substr($h, $start, 400000) : substr($h, 0, 400000);
        return ['ok' => true, 'title' => $title, 'date' => $date, 'og' => $og, 'len' => strlen($h), 'body' => $body];
    }

    /**
     * Imports one news article from the old site with its old id (so old links keep working).
     * item: {id, lang: ro|en, title, alias, publish_up (UTC), introtext, image (images/...), assoc (id of the other language)}
     */
    private static function newsImport(array $d): array
    {
        $app = Factory::getApplication();
        $db = Admin::db();
        $id = (int) ($d['id'] ?? 0);
        $l = (string) ($d['lang'] ?? '');
        $tag = ['ro' => 'ro-RO', 'en' => 'en-US'][$l] ?? '';
        $cat = $tag ? Admin::catByAlias('news-' . $l) : 0;
        $title = Admin::plainTitle((string) ($d['title'] ?? ''));
        $intro = trim((string) ($d['introtext'] ?? ''));
        $pub = (string) ($d['publish_up'] ?? '');
        if ($id < 2000 || !$cat || $title === '' || $intro === '' || !preg_match('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$#', $pub)) {
            return ['ok' => false, 'error' => 'bad item ' . $id];
        }
        if ((int) $db->setQuery('SELECT COUNT(*) FROM #__content WHERE id = ' . $id)->loadResult()) {
            return ['ok' => false, 'error' => 'id ' . $id . ' exists'];
        }
        $img = Admin::safeRel((string) ($d['image'] ?? ''));
        $images = json_encode(['image_intro' => $img, 'float_intro' => '', 'image_intro_alt' => '', 'image_intro_caption' => '', 'image_fulltext' => '', 'float_fulltext' => '', 'image_fulltext_alt' => '', 'image_fulltext_caption' => ''], JSON_UNESCAPED_SLASHES);
        $alias = Admin::uniqueAlias($cat, Admin::slug((string) ($d['alias'] ?? '') ?: $title, 180) ?: 'stire');
        $row = (object) [
            'id' => $id, 'asset_id' => 0, 'title' => $title, 'alias' => $alias, 'introtext' => $intro, 'fulltext' => '', 'state' => 1, 'catid' => $cat,
            'created' => $pub, 'created_by' => (int) ($d['created_by'] ?? 0) ?: (int) $app->getIdentity()->id, 'created_by_alias' => '',
            'modified' => $pub, 'modified_by' => 0, 'publish_up' => $pub, 'images' => $images,
            'urls' => '{"urla":false,"urlatext":"","targeta":"","urlb":false,"urlbtext":"","targetb":"","urlc":false,"urlctext":"","targetc":""}',
            'attribs' => '{}', 'version' => 1, 'ordering' => 0, 'metakey' => '', 'metadesc' => '', 'access' => 1, 'hits' => 0,
            'metadata' => '{"robots":"","author":"","rights":""}', 'featured' => 0, 'language' => $tag, 'note' => 'import mitropolia.us (Claude), Oct 2',
        ];
        $db->insertObject('#__content', $row);
        // store once through the table so the asset and the other bookkeeping are made
        $table = Admin::table();
        if ($table->load($id)) {
            $table->store();
        }
        Admin::ensureWorkflow([$cat]);
        $other = (int) ($d['assoc'] ?? 0);
        if ($other) {
            $ids = array_filter([$tag => $id, ($tag === 'ro-RO' ? 'en-US' : 'ro-RO') => $other]);
            $db->setQuery($db->getQuery(true)->delete('#__associations')->where('context = ' . $db->quote('com_content.item'))
                ->where('id IN (' . implode(',', array_map('intval', $ids)) . ')'))->execute();
            $key = md5(json_encode($ids));
            foreach ($ids as $aid) {
                $o = (object) ['id' => (int) $aid, 'context' => 'com_content.item', 'key' => $key, 'parent_id' => 0];
                $db->insertObject('#__associations', $o);
            }
        }
        return ['ok' => true, 'id' => $id, 'alias' => $alias];
    }

    /* ------------------------------------------------------------------ builder pages */

    /**
     * Writes a YOOtheme builder layout into an article the way YOOtheme stores it
     * (an HTML comment holding the JSON, after the read-more line). Used for the homepage articles,
     * whose layouts contain Mitropolia elements the YOOtheme MCP server doesn't know about.
     */
    private static function pageLayout(int $id, string $json): array
    {
        $l = json_decode($json, true);
        if (!$id || !is_array($l) || ($l['type'] ?? '') !== 'layout' || empty($l['children'])) {
            return ['ok' => false, 'error' => 'bad layout'];
        }
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $row = $db->setQuery($db->getQuery(true)->select($db->quoteName(['id', 'introtext', 'fulltext']))->from('#__content')->where('id = ' . $id))->loadObject();
        if (!$row) {
            return ['ok' => false, 'error' => 'no article'];
        }
        $comment = '<!-- ' . json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' -->';
        $o = (object) ['id' => $id, 'introtext' => '', 'fulltext' => $comment, 'modified' => Factory::getDate()->toSql()];
        $db->updateObject('#__content', $o, 'id');
        return ['ok' => true, 'id' => $id, 'bytes' => strlen($comment), 'before' => substr((string) $row->introtext . '|' . (string) $row->fulltext, 0, 160)];
    }

    /** Saves a small site graphic (SVG, PNG or WebP) into images/site/, e.g. the seal watermark. */
    private static function siteAsset(string $name, string $data, string $dir = 'site'): array
    {
        if (!preg_match('/^[a-z0-9-]{1,40}\.(svg|png|webp)$/', $name) || $data === '' || strlen($data) > 4000000) {
            return ['ok' => false, 'error' => 'bad name or data'];
        }
        if (str_ends_with($name, '.svg')) {
            if (stripos($data, '<svg') === false || preg_match('#<script|on[a-z]+\s*=|javascript:#i', $data)) {
                return ['ok' => false, 'error' => 'svg rejected'];
            }
        } elseif (!@getimagesizefromstring($data)) {
            return ['ok' => false, 'error' => 'not an image'];
        }
        if (!is_dir(JPATH_ROOT . '/images/' . $dir)) {
            @mkdir(JPATH_ROOT . '/images/' . $dir, 0755, true);
        }
        $abs = JPATH_ROOT . '/images/' . $dir . '/' . $name;
        $ok = @file_put_contents($abs, $data);
        return ['ok' => (bool) $ok, 'path' => 'images/' . $dir . '/' . $name, 'bytes' => (int) $ok];
    }

    /** Copies a partner logo (SVG or PNG) from the partner's own site into images/partners/. */
    private static function logoGet(string $url, string $name): array
    {
        if (!preg_match('#^https://(www\.)?(patriarhia\.ro|episcopia\.ca|assemblyofbishops\.org|spcharity\.org)/[^\s"\'<>]+\.(svg|png)$#i', $url)) {
            return ['ok' => false, 'error' => 'url not allowed'];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (Mitropolia staging)']]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 100) {
            return ['ok' => false, 'error' => 'download failed'];
        }
        return self::siteAsset($name, $data, 'partners');
    }
}
