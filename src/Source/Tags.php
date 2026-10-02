<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * Tag page ("Tag page" builder element): every published article with the tag, in the page language
 * (or "All languages"), grouped by section. The section is the article's top category, by alias
 * (news-ro, pastoral-letters-en, parishes...). Each group shows its latest 6 cards and a "See all" link;
 * ?g=<group> shows one group in full, 24 per page. Cards are the News cards.
 */
final class Tags
{
    private const FIRST = 6;
    private const PER_PAGE = 24;
    /** Display order of the groups; top-category alias without the -ro/-en/-es ending. */
    private const GROUPS = ['news', 'pastoral-letters', 'events', 'itinerary', 'hierarchs', 'parishes', 'words-of-wisdom', 'documents', 'publications', 'galleries', 'media', 'pages'];
    /** Top categories never listed: clergy have no pages, Static Pages is internal. */
    private const SKIP = ['clergy', 'static'];

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    public static function text(array $props): string
    {
        return '';
    }

    private static function tagIds(array $props): array
    {
        $in = Factory::getApplication()->getInput();
        if ($in->get('option') === 'com_tags' && $in->get('view') === 'tag') {
            $ids = (array) $in->get('id', [], 'array');
        } else {
            $ids = [(string) ($props['tag_id'] ?? '')];
        }
        // ids can arrive as "12:slug"
        return array_values(array_filter(array_map(fn ($v) => (int) explode(':', (string) $v, 2)[0], $ids)));
    }

    public static function render(array $props): string
    {
        try {
            $ids = self::tagIds($props);
            if (!$ids) {
                return '';
            }
            $db = self::db();
            $levels = array_map('intval', Factory::getApplication()->getIdentity()->getAuthorisedViewLevels()) ?: [1];
            $tags = $db->setQuery($db->getQuery(true)->select(['id', 'title', 'description'])->from('#__tags')
                ->whereIn('id', $ids)->where('published = 1')->whereIn('access', $levels))->loadObjectList();
            if (!$tags) {
                return '';
            }
            return self::html($tags, $props) . News::sharedCss();
        } catch (\Throwable $x) {
            Log::add('Tag page: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    /** Group key of an article from its category path: "pastoral-letters-ro/mitropolitul-nicolae" -> "pastoral-letters". */
    private static function group(string $path): string
    {
        $root = explode('/', $path, 2)[0];
        return preg_replace('/-(ro|en|es)$/', '', $root);
    }

    private static function html(array $tags, array $props): string
    {
        $e = [self::class, 'e'];
        $app = Factory::getApplication();
        $db = self::db();
        $in = $app->getInput();
        $tag = $app->getLanguage()->getTag();
        $now = Factory::getDate()->toSql();
        $levels = array_map('intval', $app->getIdentity()->getAuthorisedViewLevels()) ?: [1];
        $tagIds = array_map(fn ($t) => (int) $t->id, $tags);

        $q = $db->getQuery(true)
            ->select(['DISTINCT a.id', 'a.title', 'a.alias', 'a.catid', 'a.language', 'a.images', 'a.publish_up', 'a.introtext', 'c.path'])
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName('#__contentitem_tag_map', 'm') . ' ON m.content_item_id = a.id AND m.type_alias = ' . $db->quote('com_content.article'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = a.catid AND c.published = 1')
            ->whereIn('m.tag_id', $tagIds)
            ->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $db->quote($now) . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down > ' . $db->quote($now) . ')')
            ->whereIn('a.access', $levels)
            ->whereIn('a.language', [$tag, '*'], \Joomla\Database\ParameterType::STRING)
            ->order('a.publish_up DESC, a.id DESC');
        $rows = $db->setQuery($q)->loadObjectList();

        $groups = [];
        foreach ($rows as $r) {
            $g = self::group((string) $r->path);
            if (in_array($g, self::SKIP, true)) {
                continue;
            }
            $groups[$g][] = $r;
        }
        uksort($groups, function ($a, $b) {
            $ia = array_search($a, self::GROUPS, true);
            $ib = array_search($b, self::GROUPS, true);
            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });
        $total = array_sum(array_map('count', $groups));

        $gSel = (string) $in->getCmd('g');
        if ($gSel !== '' && !isset($groups[$gSel])) {
            $gSel = '';
        }
        $page = max(1, (int) $in->getInt('p', 1));
        $base = strtok(Uri::getInstance()->toString(['path']), '?');
        $link = function (string $g = '', int $p = 1) use ($base) {
            $q = array_filter(['g' => $g, 'p' => $p > 1 ? $p : null]);
            return $base . ($q ? '?' . http_build_query($q) : '');
        };
        $label = function (string $g) {
            $k = 'MIT_TAG_G_' . strtoupper(str_replace('-', '_', $g));
            $t = Text::_($k);
            return $t !== $k ? $t : ucfirst(str_replace('-', ' ', $g));
        };

        // header
        $title = implode(' · ', array_map(fn ($t) => (string) $t->title, $tags));
        try {
            $doc = $app->getDocument();
            $site = (string) $app->get('sitename');
            $doc->setTitle($site !== '' ? $site . ' - ' . $title : $title);
            if ($desc0 = trim(strip_tags((string) $tags[0]->description))) {
                $doc->setDescription(mb_substr($desc0, 0, 160));
            }
        } catch (\Throwable $x) {
        }
        $home = $app->getMenu()->getDefault($tag);
        $crumbs = '<nav class="mpx-crumbs" aria-label="' . $e(Text::_('MIT_DIR_BREADCRUMB')) . '">'
            . ($home ? '<a href="' . $e(Route::_('index.php?Itemid=' . (int) $home->id)) . '">' . $e((string) $home->title) . '</a><span aria-hidden="true">›</span>' : '')
            . '<span aria-current="page">' . $e($title) . '</span></nav>';
        $desc = count($tags) === 1 ? trim(strip_tags((string) $tags[0]->description)) : '';
        $count = Text::sprintf($total === 1 ? 'MIT_TAG_COUNT_1' : 'MIT_TAG_COUNT', $total);
        $chips = '';
        if (count($groups) > 1) {
            $chips = '<div class="mnx-chips"><a class="mpx-chip' . ($gSel === '' ? ' on' : '') . '" href="' . $e($link()) . '">' . $e(Text::_('MIT_NEWS_ALL')) . ' <span class="mtg-n">' . $total . '</span></a>';
            foreach ($groups as $g => $list) {
                $chips .= '<a class="mpx-chip' . ($gSel === $g ? ' on' : '') . '" href="' . $e($link($g)) . '">' . $e($label($g)) . ' <span class="mtg-n">' . count($list) . '</span></a>';
            }
            $chips .= '</div>';
        }
        $head = '<div class="mnx-hero"><div class="mnx-hero-in">' . $crumbs
            . '<div class="mnx-hero-row"><div class="mnx-hero-text"><span class="mtg-k">' . $e(Text::_('MIT_TAG_LABEL')) . '</span>'
            . '<h1 class="mpx-h1">' . $e($title) . '</h1>'
            . '<p class="mnx-intro">' . $e($desc !== '' ? $desc : $count) . '</p></div></div>' . $chips . '</div></div>';

        // body
        $body = '';
        if (!$groups) {
            $body = '<p class="mnx-empty">' . $e(Text::_('MIT_TAG_NONE')) . '</p>';
        } elseif ($gSel !== '') {
            $list = $groups[$gSel];
            $pages = max(1, (int) ceil(count($list) / self::PER_PAGE));
            $page = min($page, $pages);
            $grid = '';
            foreach (array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE) as $r) {
                $grid .= News::sharedCard($r);
            }
            $body = '<section class="mtg-sec"><div class="mnx-sechead"><h2 class="mpx-h2">' . $e($label($gSel)) . '</h2>'
                . '<a class="mnx-all" href="' . $e($link()) . '">← ' . $e(Text::_('MIT_TAG_BACK')) . '</a></div><div class="mnx-grid">' . $grid . '</div>';
            if ($pages > 1) {
                $body .= '<nav class="mnx-pg" aria-label="' . $e(Text::_('MIT_NEWS_PAGES')) . '">';
                for ($i = 1; $i <= $pages; $i++) {
                    $body .= $i === $page ? '<span class="on" aria-current="page">' . $i . '</span>' : '<a href="' . $e($link($gSel, $i)) . '">' . $i . '</a>';
                }
                $body .= '</nav>';
            }
            $body .= '</section>';
        } else {
            foreach ($groups as $g => $list) {
                $grid = '';
                foreach (array_slice($list, 0, self::FIRST) as $r) {
                    $grid .= News::sharedCard($r);
                }
                $more = count($list) > self::FIRST
                    ? '<a class="mnx-all" href="' . $e($link($g)) . '">' . $e(Text::sprintf('MIT_TAG_SEE_ALL', count($list))) . ' →</a>' : '';
                $body .= '<section class="mtg-sec"><div class="mnx-sechead"><h2 class="mpx-h2">' . $e($label($g)) . '</h2>' . $more . '</div>'
                    . '<div class="mnx-grid">' . $grid . '</div></section>';
            }
        }

        return '<div class="mnx mtg' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">' . $head
            . '<div class="mtg-body">' . $body . '</div></div>'
            . '<style>.mtg-k{display:block;font-size:13px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--mp-red);margin-bottom:10px}'
            . '.mtg-body{padding:48px 0 96px}.mtg-sec+.mtg-sec{margin-top:64px}.mtg .mnx-sechead{display:flex;justify-content:space-between;align-items:baseline;gap:20px;margin-bottom:24px}'
            . '.mtg-n{font-weight:400;opacity:.7;margin-left:4px}</style>';
    }
}
