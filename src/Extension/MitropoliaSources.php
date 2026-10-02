<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Mitropolia\Plugin\System\MitropoliaSources\Source\Repository;

/**
 * Registers the Mitropolia sources with YOOtheme Pro through its documented
 * extension point (Application::load + the source.init event).
 *
 * Update safety: nothing here edits Joomla or YOOtheme files. If YOOtheme is not
 * loaded, or a later version changes its API, loading is skipped and logged, and
 * the site keeps working without these sources.
 */
final class MitropoliaSources extends CMSPlugin implements SubscriberInterface
{
    private static bool $loaded = false;

    /**
     * News articles that were duplicates of a pastoral piece and went to the trash (Oct 2026):
     * old id => the pastoral article that replaces it.
     */
    private const REPLACED = [
        2044 => 3660, 2045 => 3661, 2102 => 3664, 2103 => 3665, 2095 => 3666, 2096 => 3667,
        2079 => 3668, 2080 => 3669, 2078 => 3670, 2073 => 3672, 2074 => 3673, 2011 => 3674, 2012 => 3675,
    ];

    /** lang_code => URL of the current "All languages" article in that language (built after dispatch). */
    private array $switch = [];

    public static function getSubscribedEvents(): array
    {
        // Try early, and again after routing in case YOOtheme boots later.
        return [
            'onAfterInitialise' => 'loadSources',
            'onAfterRoute'      => 'onAfterRoute',
            'onAfterDispatch'   => 'onAfterDispatch',
            'onAfterRender'     => 'onAfterRender',
            'onError'           => ['onError', 10],
        ];
    }

    /** Sources, plus the Mitropolia field and list labels in the page language (after the language is final). */
    public function onAfterRoute(): void
    {
        $this->loadSources();
        try {
            $this->getApplication()->getLanguage()->load('plg_system_mitropoliasources', JPATH_PLUGINS . '/system/mitropoliasources');
        } catch (\Throwable $e) {
            // labels fall back to their keys; never break the page
        }
        // Itinerary manager form posts (?mitit=api): save or remove a visit, answer in JSON
        try {
            $app = $this->getApplication();
            if (($app->isClient('site') || $app->isClient('administrator')) && $app->getInput()->getCmd('mitit') === 'api') {
                \Mitropolia\Plugin\System\MitropoliaSources\Source\Itinerary::api();
            }
            // Administrare page, news and galleries (?mitadm=api): upload, save, trash
            if ($app->isClient('site') && $app->getInput()->getCmd('mitadm') === 'api') {
                \Mitropolia\Plugin\System\MitropoliaSources\Source\Admin::api();
            }
        } catch (\Throwable $e) {
            Log::add('Itinerary API not run: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
        }
    }

    public function loadSources(): void
    {
        if (self::$loaded || !class_exists('YOOtheme\\Application')) {
            return;
        }

        try {
            Repository::setParams($this->params);
            \YOOtheme\Application::getInstance()->load(JPATH_PLUGINS . '/system/mitropoliasources/src/Source/bootstrap.php');
            self::$loaded = true;
        } catch (\Throwable $e) {
            self::$loaded = true; // do not retry on every event
            Log::add('Mitropolia sources not loaded: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
        }
    }

    /**
     * Language switcher on "All languages" articles (parishes, and any other
     * content entered once for all languages). Joomla's switcher only follows
     * associations, and such an article has none, so it sends visitors to the
     * parent menu page. Here we work out the same article's address in each
     * language; onAfterRender puts those addresses into the switcher.
     */
    public function onAfterDispatch(): void
    {
        try {
            $app = $this->getApplication();
            if (!$app->isClient('site') || !$app->getLanguageFilter() || $app->getDocument()->getType() !== 'html') {
                return;
            }
            $in = $app->getInput();
            $id = $in->getInt('id');
            if ($in->getCmd('option') !== 'com_content' || $in->getCmd('view') !== 'article' || $id < 1) {
                return;
            }

            $db = \Joomla\CMS\Factory::getContainer()->get(DatabaseInterface::class);
            $q  = $db->getQuery(true)
                ->select($db->quoteName(['catid', 'language']))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('state') . ' = 1')
                ->bind(':id', $id, ParameterType::INTEGER);
            $row = $db->setQuery($q)->loadObject();
            if (!$row || $row->language !== '*') {
                return;
            }

            $current = $app->getLanguage()->getTag();
            foreach (LanguageHelper::getContentLanguages([1], true, 'lang_code', 'ordering', 'ASC') as $code => $lang) {
                if ($code === $current) {
                    continue;
                }
                $url = Route::link('site', 'index.php?option=com_content&view=article&id=' . $id . '&catid=' . (int) $row->catid . '&lang=' . $code, false);
                // Only use addresses that resolved to a page of that language's menu
                if ($url && strpos($url, 'option=com_content') === false && strpos($url, '/component/') === false) {
                    $this->switch[$code] = ['url' => $url, 'sef' => (string) $lang->sef, 'names' => [(string) $lang->title, (string) $lang->title_native]];
                }
            }
        } catch (\Throwable $e) {
            $this->switch = [];
            Log::add('Mitropolia language switch: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
        }
    }

    public function onAfterRender(): void
    {
        if (!$this->switch) {
            return;
        }
        try {
            $app  = $this->getApplication();
            $body = $app->getBody();
            if (!$body || strpos($body, 'mod-languages') === false) {
                return;
            }

            $all   = array_keys(LanguageHelper::getContentLanguages([1], true, 'lang_code', 'ordering', 'ASC'));
            $map   = $this->switch;

            // Each language module block: from the "mod-languages" wrapper to the end of its list
            $body = preg_replace_callback('#(<div[^>]*class="[^"]*\bmod-languages\b[^"]*"[^>]*>)(.*?)(</ul>)#s', function ($m) use ($map, $all) {
                $i = -1;
                $count = preg_match_all('#<a\s#i', $m[2]);
                $inner = preg_replace_callback('#<a\b([^>]*)\bhref="([^"]*)"([^>]*)>(.*?)</a>#s', function ($a) use ($map, $all, &$i, $count) {
                    $i++;
                    $code = null;
                    // 1. hreflang / lang attribute, 2. link text (RO, English, Español...), 3. position
                    if (preg_match('#\b(?:hreflang|lang)="([^"]+)"#i', $a[1] . $a[3], $h) && isset($map[$h[1]])) {
                        $code = $h[1];
                    }
                    if ($code === null) {
                        $text = trim(html_entity_decode(strip_tags($a[4]), ENT_QUOTES, 'UTF-8'));
                        foreach ($map as $c => $l) {
                            if ($text !== '' && (strcasecmp($text, $l['sef']) === 0 || in_array($text, $l['names'], true))) {
                                $code = $c;
                                break;
                            }
                        }
                    }
                    if ($code === null && $count === count($all) && isset($all[$i], $map[$all[$i]])) {
                        $code = $all[$i];
                    }
                    if ($code === null) {
                        return $a[0];
                    }
                    return '<a' . $a[1] . 'href="' . htmlspecialchars($map[$code]['url'], ENT_QUOTES, 'UTF-8') . '"' . $a[3] . '>' . $a[4] . '</a>';
                }, $m[2]);
                return $m[1] . $inner . $m[3];
            }, $body);

            if (is_string($body)) {
                $app->setBody($body);
            }
        } catch (\Throwable $e) {
            Log::add('Mitropolia language switch: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
        }
    }

    /**
     * Old article addresses that now give a 404 because the article moved to another category
     * (the hierarchs' letters and messages moved from News to Pastoral letters) or was replaced
     * by a pastoral copy. Old links look like /index.php/ro/1981-alias or /ro/alias; the article
     * is looked up by id, then by alias in that language, and the visitor is sent to its
     * current address with a 301. Anything else is left to the normal 404 page.
     */
    public function onError($event): void
    {
        try {
            $app = $this->getApplication();
            if (!$app->isClient('site') || strtoupper((string) $app->getInput()->getMethod()) !== 'GET') {
                return;
            }
            $error = method_exists($event, 'getError') ? $event->getError() : ($event->getArgument('subject') ?? null);
            if (!$error instanceof \Throwable || (int) $error->getCode() !== 404) {
                return;
            }
            $uri  = \Joomla\CMS\Uri\Uri::getInstance();
            $path = trim(substr($uri->getPath(), strlen(rtrim(\Joomla\CMS\Uri\Uri::base(true), '/'))), '/');
            $path = preg_replace('#^index\.php/?#', '', $path);
            $seg  = array_values(array_filter(explode('/', $path), 'strlen'));
            if (count($seg) < 2) {
                return;
            }
            $langs = LanguageHelper::getLanguages('sef');
            if (!isset($langs[$seg[0]])) {
                return;
            }
            $tag  = (string) $langs[$seg[0]]->lang_code;
            $last = preg_replace('#\.html?$#', '', rawurldecode((string) end($seg)));
            $id   = 0;
            $alias = $last;
            if (preg_match('#^(\d+)(?:-(.*))?$#', $last, $m)) {
                $id = (int) $m[1];
                $alias = (string) ($m[2] ?? '');
            }
            if (!preg_match('#^[a-z0-9-]*$#', $alias)) {
                return;
            }

            $db  = \Joomla\CMS\Factory::getContainer()->get(DatabaseInterface::class);
            $now = \Joomla\CMS\Factory::getDate()->toSql();
            $base = function () use ($db, $now) {
                return $db->getQuery(true)->select(['a.id', 'a.alias', 'a.catid', 'a.language'])
                    ->from($db->quoteName('#__content', 'a'))
                    ->where('a.state = 1')->where('a.access = 1')
                    ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
                    ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')');
            };
            $hit = null;
            if ($id > 0) {
                $target = self::REPLACED[$id] ?? $id;
                $hit = $db->setQuery($base()->where('a.id = ' . (int) $target))->loadObject();
            }
            if (!$hit && $alias !== '') {
                // same alias in this language; the oldest one first (mitropolia.us pieces before later imports)
                $hit = $db->setQuery($base()->where('a.alias = ' . $db->quote($alias))
                    ->whereIn('a.language', [$tag, '*'], ParameterType::STRING)->order('a.id ASC'), 0, 1)->loadObject();
                if (!$hit) {
                    // a trashed duplicate with this alias that has a replacement
                    $old = $db->setQuery($db->getQuery(true)->select('id')->from('#__content')
                        ->where('alias = ' . $db->quote($alias))->whereIn('language', [$tag, '*'], ParameterType::STRING)
                        ->whereIn('id', array_keys(self::REPLACED)))->loadColumn();
                    foreach ($old as $o) {
                        $hit = $db->setQuery($base()->where('a.id = ' . (int) self::REPLACED[(int) $o]))->loadObject();
                        if ($hit) {
                            break;
                        }
                    }
                }
            }
            if (!$hit) {
                return;
            }
            $lang = $hit->language === '*' ? $tag : (string) $hit->language;
            $url  = Route::link('site', \Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute($hit->id . ':' . $hit->alias, (int) $hit->catid, $lang), false);
            if (!$url || strpos($url, 'option=com_content') !== false || rtrim($url, '/') === rtrim($uri->getPath(), '/')) {
                return;
            }
            $app->redirect($url, 301);
        } catch (\Throwable $e) {
            Log::add('Mitropolia old link: ' . $e->getMessage(), Log::WARNING, 'mitropolia');
        }
    }
}
