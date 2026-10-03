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
            case 'es_list':
                return self::esList($p->getInt('year'));
            case 'es_src':
                return self::esSrc($p->getInt('id'));
            case 'es_save':
                return self::esSave();
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
}
