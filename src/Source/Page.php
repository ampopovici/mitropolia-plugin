<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;

/**
 * Static page, rendered for the "Static page" builder element.
 *
 * Everything comes from Joomla itself, so a new page needs no extra setup:
 * - Description under the title: the text before the "Read More" break (optional).
 * - Side menu: the other items under the same parent in the menu the page belongs to.
 *   A page with no menu item, a top-level item, or a parent with only this page shows no side menu.
 * - Links to PDF or Word files that stand alone in a paragraph become document cards.
 * - Tables get the site's table style; class "mp-people" on a block of cards (see the build guide).
 */
final class Page
{
    private const I_FB = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8h3V4h-3c-2.8 0-4 1.7-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8.5c0-.3.2-.5.5-.5z"/></svg>';
    private const I_MAIL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>';
    private const I_LINK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>';
    private const I_PRINT = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>';
    private const I_CHEV = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function lang(): string
    {
        return strtolower(substr(Factory::getApplication()->getLanguage()->getTag(), 0, 2));
    }

    public static function render(array $props): string
    {
        try {
            $id = (int) ($props['article_id'] ?? 0);
            if (!$id) {
                $in = Factory::getApplication()->getInput();
                $id = $in->get('option') === 'com_content' && $in->get('view') === 'article' ? $in->getInt('id') : 0;
            }
            if (!$id) {
                return '';
            }
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $a = $db->setQuery($db->getQuery(true)
                ->select(['a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.introtext', 'a.fulltext', 'a.modified', 'a.publish_up', 'a.metadesc'])
                ->from($db->quoteName('#__content', 'a'))->where('a.id = ' . (int) $id)->where('a.state = 1'))->loadObject();
            if (!$a) {
                return '';
            }
            return self::html($a, $props) . self::assets();
        } catch (\Throwable $x) {
            Log::add('Static page: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    public static function text(array $props): string
    {
        return '';
    }

    /** Menu item of this article: the active one if it points here, else the first one in this language. */
    private static function menuItem(int $id)
    {
        $menu = Factory::getApplication()->getMenu();
        $act = $menu->getActive();
        $isMine = function ($it) use ($id) {
            $q = $it->query ?? [];
            return ($q['option'] ?? '') === 'com_content' && ($q['view'] ?? '') === 'article' && (int) ($q['id'] ?? 0) === $id;
        };
        if ($act && $isMine($act)) {
            return $act;
        }
        foreach ($menu->getItems(['component'], ['com_content']) as $it) {
            if ($isMine($it)) {
                return $it;
            }
        }
        return null;
    }

    private static function link($it): string
    {
        switch ($it->type) {
            case 'url':
                return (string) $it->link;
            case 'alias':
                $to = (int) $it->getParams()->get('aliasoptions');
                return $to ? Route::_('index.php?Itemid=' . $to) : '';
            case 'heading':
            case 'separator':
                return '';
            default:
                return Route::_('index.php?Itemid=' . (int) $it->id);
        }
    }

    private static function shown($it): bool
    {
        return (int) $it->getParams()->get('menu_show', 1) === 1;
    }

    private static function date(?string $sql): string
    {
        if (!$sql || strpos($sql, '0000') === 0) {
            return '';
        }
        $d = HTMLHelper::_('date', $sql, Text::_('MIT_PAGE_DATE_FORMAT'));
        return in_array(self::lang(), ['ro', 'es'], true) ? mb_strtolower($d, 'UTF-8') : $d;
    }

    /** A paragraph that holds only one link to a PDF or Word file becomes a document card. */
    private static function docCards(string $html): string
    {
        return preg_replace_callback(
            '#<p[^>]*>\s*(?:<(?:strong|b|em)>\s*)?<a\s([^>]*href="([^"]+\.(pdf|docx?))(?:[?\#][^"]*)?"[^>]*)>(.*?)</a>\s*(?:</(?:strong|b|em)>\s*)?</p>#is',
            function ($m) {
                $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                $ext = strtoupper($m[3] === 'pdf' ? 'pdf' : 'doc');
                $title = trim(strip_tags($m[4]));
                if ($title === '') {
                    $title = basename($href);
                }
                $meta = $ext === 'PDF' ? 'PDF' : 'Word';
                $local = preg_replace('#^' . preg_quote(Uri::root(), '#') . '#', '', $href);
                if (!preg_match('#^[a-z]+:#i', $local)) {
                    $file = JPATH_ROOT . '/' . ltrim(rawurldecode(explode('?', $local)[0]), '/');
                    if (strpos($file, '..') === false && is_file($file)) {
                        $kb = filesize($file) / 1024;
                        $meta .= ' · ' . ($kb >= 1024 ? number_format($kb / 1024, 1) . ' MB' : max(1, (int) round($kb)) . ' KB');
                    }
                }
                $target = preg_match('#^https?://#i', $href) && strpos($href, Uri::root()) !== 0 ? ' target="_blank" rel="noopener"' : '';
                return '<a class="mpg-doc" href="' . self::e($href) . '"' . $target . '><span class="mpg-doc-ic" aria-hidden="true">' . $ext . '</span>'
                    . '<span class="mpg-doc-t"><b>' . self::e($title) . '</b><span>' . self::e($meta) . '</span></span>'
                    . '<span class="mpg-doc-go">' . self::e(Text::_('MIT_PAGE_OPEN')) . '</span></a>';
            },
            $html
        );
    }

    /** The page description (text before Read More, as plain text), for headers built in YOOtheme. */
    public static function leadText(object $a): string
    {
        if (!isset($a->fulltext) && !empty($a->id)) {
            $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $r = $db->setQuery($db->getQuery(true)->select(['a.introtext', 'a.' . $db->quoteName('fulltext')])->from($db->quoteName('#__content', 'a'))->where('a.id = ' . (int) $a->id))->loadObject();
            if (!$r) {
                return '';
            }
            $a = $r;
        }
        $t = preg_replace('#<!--.*?-->#s', '', (string) ($a->fulltext ?? ''));
        if (trim($t) === '') {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $a->introtext), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function html(object $a, array $props): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $menu = $app->getMenu();

        // description = text before Read More; body = the rest (or everything when there is no break)
        $hasMore = trim((string) $a->fulltext) !== '';
        $lead = $hasMore ? trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $a->introtext), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '';
        $body = $hasMore ? (string) $a->fulltext : (string) $a->introtext;
        try {
            $body = HTMLHelper::_('content.prepare', $body, null, 'com_content.article');
        } catch (\Throwable $x) {
        }
        $body = self::docCards($body);

        if ($lead !== '' && trim((string) $a->metadesc) === '') {
            try {
                $app->getDocument()->setDescription(mb_substr($lead, 0, 160));
            } catch (\Throwable $x) {
            }
        }

        // menu context
        $item = self::menuItem((int) $a->id);
        $parent = ($item && (int) $item->parent_id > 1) ? $menu->getItem((int) $item->parent_id) : null;
        $siblings = [];
        if ($parent) {
            foreach ($menu->getItems('parent_id', (int) $parent->id) as $s) {
                if (self::shown($s) || (int) $s->id === (int) $item->id) {
                    $siblings[] = $s;
                }
            }
        }
        $hasNav = count($siblings) > 1;

        // breadcrumbs: home, the parents in the menu (linked when they are pages), this page
        $crumbs = [];
        $home = $menu->getDefault($app->getLanguage()->getTag());
        if ($home) {
            $crumbs[] = '<a href="' . $e(Route::_('index.php?Itemid=' . (int) $home->id)) . '">' . $e((string) $home->title) . '</a>';
        }
        if ($item) {
            foreach (array_slice((array) ($item->tree ?? []), 0, -1) as $pid) {
                $p = $menu->getItem((int) $pid);
                if (!$p || ($home && (int) $p->id === (int) $home->id)) {
                    continue;
                }
                $l = self::link($p);
                $crumbs[] = $l !== '' ? '<a href="' . $e($l) . '">' . $e((string) $p->title) . '</a>' : '<span>' . $e((string) $p->title) . '</span>';
            }
        }
        $crumbs[] = '<span aria-current="page">' . $e((string) $a->title) . '</span>';

        $head = '<header class="mpg-band"><nav class="mpx-crumbs" aria-label="' . $e(Text::_('MIT_DIR_BREADCRUMB')) . '">'
            . implode('<span aria-hidden="true">›</span>', $crumbs) . '</nav>'
            . '<h1 class="mpg-title">' . $e((string) $a->title) . '</h1>'
            . ($lead !== '' ? '<p class="mpg-lead">' . $e($lead) . '</p>' : '') . '</header>';

        if (($props['part'] ?? '') === 'body') {
            $head = '';
        }

        $nav = '';
        if ($hasNav) {
            $links = '';
            foreach ($siblings as $s) {
                $cur = (int) $s->id === (int) $item->id;
                $l = self::link($s);
                if ($l === '' && !$cur) {
                    continue;
                }
                $ext = $s->type === 'url' && preg_match('#^https?://#i', $l) && strpos($l, Uri::root()) !== 0;
                $links .= $cur
                    ? '<a class="on" aria-current="page" href="' . $e($l) . '">' . $e((string) $s->title) . '</a>'
                    : '<a href="' . $e($l) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . $e((string) $s->title) . '</a>';
            }
            $nav = '<nav class="mpg-nav" aria-label="' . $e((string) $parent->title) . '"><h2>' . $e((string) $parent->title) . '</h2>'
                . '<details open><summary><span><small>' . $e((string) $parent->title) . '</small>' . $e((string) $item->title) . '</span>' . self::I_CHEV . '</summary><div>' . $links . '</div></details></nav>';
        }

        $url = Uri::getInstance()->toString(['scheme', 'host', 'port']) . ($item ? Route::_('index.php?Itemid=' . (int) $item->id) : Route::_(RouteHelper::getArticleRoute($a->id . ':' . $a->alias, (int) $a->catid, $a->language)));
        $share = '<div class="mpg-box mpg-share"><h4>' . $e(Text::_('MIT_NEWS_SHARE')) . '</h4><div class="mnx-share">'
            . '<a href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url) . '" target="_blank" rel="noopener" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_FB')) . '">' . self::I_FB . '</a>'
            . '<a href="mailto:?subject=' . rawurlencode((string) $a->title) . '&amp;body=' . rawurlencode($url) . '" aria-label="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '" title="' . $e(Text::_('MIT_NEWS_SHARE_MAIL')) . '">' . self::I_MAIL . '</a>'
            . '<button type="button" data-copy="' . $e($url) . '" data-done="' . $e(Text::_('MIT_NEWS_COPIED')) . '" aria-label="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '" title="' . $e(Text::_('MIT_NEWS_COPY_LINK')) . '">' . self::I_LINK . '</button>'
            . '<button type="button" data-print aria-label="' . $e(Text::_('MIT_NEWS_PRINT')) . '" title="' . $e(Text::_('MIT_NEWS_PRINT')) . '">' . self::I_PRINT . '</button></div></div>';

        $upd = self::date($a->modified ?: $a->publish_up);
        $main = '<div class="mpg-main"><div class="mpg-prose">' . $body . '</div>'
            . ($upd !== '' ? '<div class="mpg-foot">' . $e(Text::sprintf('MIT_PAGE_UPDATED', $upd)) . '</div>' : '') . '</div>';

        return '<article class="mnx mpg' . ($hasNav ? '' : ' mpg-solo') . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">'
            . $head . '<div class="mpg-grid">' . $nav . $share . $main . '</div></article>';
    }

    private static function assets(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>
/* Static page (Mitropolia plugin) */
.mpg{--mp-navy:#172E5C;--mp-blue:#203D78;--mp-red:#A32D36;--mp-gold:#B99755;--mp-ink:#1B2A4A;--mp-muted:#6B6F7B;--mp-line:#E4D8BE;--mp-ivory:#EFE3CB;font-family:'Source Sans 3',sans-serif;color:var(--mp-ink)}
.mpg a{text-decoration:none}
.mpg .mpx-crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:14px;color:#6B5A34}.mpg .mpx-crumbs a{color:var(--mp-blue)}
.mpg-band{position:relative;background:var(--mp-ivory);box-shadow:0 0 0 100vmax var(--mp-ivory);clip-path:inset(0 -100vmax);border-bottom:1px solid var(--mp-line);padding:24px 0 52px}
.mpg-band .mpx-crumbs{margin-bottom:40px}
.mpg-title{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:50px;line-height:1.12;color:var(--mp-navy);margin:0;max-width:1080px}
.mpg-lead{font-family:'Source Serif 4',Georgia,serif;font-size:21px;line-height:1.55;color:#3C4A66;margin:18px 0 0;max-width:760px}
.mpg-grid{display:grid;grid-template-columns:230px minmax(0,1fr);grid-template-areas:'nav main' 'share main' '. main';grid-template-rows:auto auto 1fr;column-gap:72px;align-items:start;padding:56px 0 88px}
.mpg-nav{grid-area:nav}.mpg-main{grid-area:main;min-width:0}.mpg-share{grid-area:share}
.mpg-solo .mpg-grid{display:flex;flex-direction:column}
.mpg-solo .mpg-share{order:2;margin-top:40px;align-self:flex-start}
.mpg-box{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:22px}
.mpg-box h4{margin:0 0 14px;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;font-family:'Source Sans 3',sans-serif}
.mpg-nav+.mpg-share{margin-top:28px}
.mnx-share{display:flex;gap:8px}
.mnx-share a,.mnx-share button{width:40px;height:40px;border-radius:50%;border:1px solid #D9CBAA;display:inline-flex;align-items:center;justify-content:center;color:var(--mp-blue);background:#fff;cursor:pointer;padding:0;position:relative}
.mnx-share a:hover,.mnx-share button:hover{background:var(--mp-blue);color:#fff;border-color:var(--mp-blue)}
.mnx-share .done::after{content:attr(data-done);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--mp-navy);color:#fff;font-size:12px;padding:4px 8px;border-radius:4px;white-space:nowrap}
.mpg-nav{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:22px 0 14px;position:relative}
.mpg-nav h2{margin:0 22px 10px;font-family:'Source Sans 3',sans-serif;font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;line-height:1.4}
.mpg-nav a{display:block;padding:9px 19px;border-left:3px solid transparent;color:var(--mp-blue);font-size:16px;line-height:1.35}
.mpg-nav a:hover{color:var(--mp-red);border-left-color:var(--mp-line)}
.mpg-nav a.on{border-left-color:var(--mp-red);background:#F7F1E4;font-weight:600;color:var(--mp-navy)}
.mpg-nav details summary{display:none}
.mpg-prose{font-family:'Source Serif 4',Georgia,serif;font-size:19px;line-height:1.75;color:var(--mp-ink);max-width:760px}
.mpg-prose>:first-child{margin-top:0}
.mpg-prose p{margin:0 0 1.2em;text-align:left!important}
.mpg-prose h2{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:32px;line-height:1.2;color:var(--mp-navy);margin:1.6em 0 .6em}
.mpg-prose h3{font-family:'Source Sans 3',sans-serif;font-weight:600;font-size:15px;letter-spacing:.1em;text-transform:uppercase;color:#6B5A34;margin:1.8em 0 .6em}
.mpg-prose h4{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:24px;color:var(--mp-navy);margin:1.4em 0 .5em}
.mpg-prose a{color:var(--mp-blue);text-decoration:underline;text-decoration-color:var(--mp-gold);text-underline-offset:3px}
.mpg-prose a:hover{color:var(--mp-red)}
.mpg-prose ul,.mpg-prose ol{margin:0 0 1.2em;padding-left:1.4em}.mpg-prose li{margin:.3em 0}
.mpg-prose img{max-width:100%;height:auto;border-radius:6px}
.mpg-prose figure{margin:1.6em 0}.mpg-prose figcaption{font-family:'Source Sans 3',sans-serif;font-size:14px;color:var(--mp-muted);margin-top:8px}
.mpg-prose blockquote{margin:1.8em 0;padding:24px 28px;background:var(--mp-ivory);border-left:3px solid var(--mp-gold);border-radius:4px;font-family:'Baskervville',Georgia,serif;font-size:23px;line-height:1.45;color:var(--mp-navy)}
.mpg-prose blockquote p:last-child{margin:0}
.mpg-prose table{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid var(--mp-line);border-radius:6px;margin:1em 0 1.6em;font-family:'Source Sans 3',sans-serif;font-size:16px;line-height:1.45;overflow:hidden}
.mpg-prose td,.mpg-prose th{padding:12px 20px;border:0;border-top:1px solid #F0E8D6;text-align:left;vertical-align:top}
.mpg-prose tr:first-child>td,.mpg-prose tr:first-child>th{border-top:0}
.mpg-prose th{font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#6B5A34;background:#FBF8F1}
.mpg-prose td:first-child{color:var(--mp-muted);width:34%}
.mpg-prose td+td{font-weight:600;color:var(--mp-navy)}
.mpg-prose td+td a{font-weight:600}
.mpg-doc{display:flex;align-items:center;gap:18px;background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:16px 20px;margin:0 0 12px;text-decoration:none!important;transition:box-shadow .2s,border-color .2s}
.mpg-doc:last-of-type{margin-bottom:1.6em}
.mpg-doc:hover{border-color:var(--mp-gold);box-shadow:0 10px 24px -16px rgba(23,46,92,.45)}
.mpg-doc-ic{flex:none;width:44px;height:52px;border-radius:4px;background:var(--mp-red);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Source Sans 3',sans-serif;font-size:12px;font-weight:700;letter-spacing:.06em}
.mpg-doc-t{flex:1;min-width:0;font-family:'Source Sans 3',sans-serif}
.mpg-doc-t b{display:block;font-weight:600;font-size:17px;line-height:1.35;color:var(--mp-navy)}
.mpg-doc-t span{font-size:14px;color:var(--mp-muted)}
.mpg-doc-go{flex:none;font-family:'Source Sans 3',sans-serif;font-weight:600;font-size:15px;color:var(--mp-red)}
.mpg-prose .mp-people{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:1em 0 1.6em}
.mpg-prose .mp-person{background:#fff;border:1px solid var(--mp-line);border-radius:6px;padding:18px 20px;font-family:'Source Sans 3',sans-serif;font-size:15px;line-height:1.5;color:#3C4A66}
.mpg-prose .mp-person p{margin:0}
.mpg-prose .mp-person p:first-child{font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--mp-red)}
.mpg-prose .mp-person p:nth-child(2){font-family:'Baskervville',Georgia,serif;font-size:22px;font-weight:500;color:var(--mp-navy);margin:4px 0 8px;line-height:1.3}
.mpg-prose .mp-person a{text-decoration:none}
.mpg-foot{border-top:1px solid var(--mp-line);margin-top:40px;padding-top:18px;font-size:14px;color:var(--mp-muted);max-width:760px}
@media (max-width:1000px){.mpg-title{font-size:40px}
 .mpg-grid,.mpg-solo .mpg-grid{display:grid;grid-template-columns:minmax(0,1fr);grid-template-areas:'nav' 'main' 'share';grid-template-rows:auto;row-gap:28px}
 .mpg-nav+.mpg-share,.mpg-solo .mpg-share{margin-top:0}.mpg-share{justify-self:start}
 .mpg-nav{padding:0}.mpg-nav h2{display:none}
 .mpg-nav details summary{display:flex;justify-content:space-between;align-items:center;list-style:none;cursor:pointer;padding:14px 18px;font-weight:600;color:var(--mp-blue)}
 .mpg-nav details summary::-webkit-details-marker{display:none}
 .mpg-nav summary small{display:block;font-size:12px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#6B5A34;margin-bottom:2px}
 .mpg-nav summary span{color:var(--mp-navy)}
 .mpg-nav details[open] summary{border-bottom:1px solid #F0E8D6}
 .mpg-nav details[open] summary svg{transform:rotate(180deg)}
 .mpg-nav details>div{padding:0 0 8px}}
@media (max-width:640px){.mpg-title{font-size:32px}.mpg-band{padding-bottom:36px}.mpg-band .mpx-crumbs{margin-bottom:28px}.mpg-lead{font-size:18px}.mpg-grid{padding:36px 0 64px}
 .mpg-prose{font-size:18px}.mpg-prose h2{font-size:27px}.mpg-prose .mp-people{grid-template-columns:minmax(0,1fr)}
 .mpg-prose td,.mpg-prose th{display:block;padding:2px 16px;border-top:0}.mpg-prose tr{display:block;padding:10px 0;border-top:1px solid #F0E8D6}.mpg-prose tr:first-child{border-top:0}.mpg-prose td:first-child{width:auto}
 .mpg-doc{padding:14px 16px;gap:14px}.mpg-doc-go{display:none}}
@media print{.mpg-band .mpx-crumbs,.mpg-nav,.mpg-share{display:none!important}.mpg-grid{display:block;padding-top:24px}.mpg-band{box-shadow:none;background:none;padding-bottom:16px}}
</style>
<script>(function(run){if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();})(function(){
(function(){
if(window.matchMedia&&matchMedia('(max-width:1000px)').matches){document.querySelectorAll('.mpg-nav details').forEach(function(d){d.open=false;});}
document.querySelectorAll('.mpg .mnx-share [data-copy]').forEach(function(b){b.addEventListener('click',function(){var u=b.getAttribute('data-copy');function ok(){b.classList.add('done');setTimeout(function(){b.classList.remove('done');},1600);}
if(navigator.clipboard){navigator.clipboard.writeText(u).then(ok,function(){prompt('',u);});}else{prompt('',u);}});});
document.querySelectorAll('.mpg .mnx-share [data-print]').forEach(function(b){b.addEventListener('click',function(){window.print();});});
})();
});</script>
HTML;
    }
}
