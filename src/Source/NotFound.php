<?php
namespace Mitropolia\Plugin\System\MitropoliaSources\Source;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/**
 * "Page not found" (404), rendered for the "Page not found" builder element.
 * Texts come from the plugin language files; links are looked up in the menu of the page language
 * (parish directory, news, events, contact, search), so nothing needs to be set per language.
 */
final class NotFound
{
    private const TITLES = [
        'ro' => 'Nu am găsit această pagină.',
        'en' => 'We couldn’t find that page.',
        'es' => 'No encontramos esta página.',
    ];
    private const MAIL = 'contact@mitropolia.us';

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    public static function text(array $props): string
    {
        return '';
    }

    private static function catId(string $alias): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q = $db->getQuery(true)->select('id')->from('#__categories')
            ->where('extension = ' . $db->quote('com_content'))->where('alias = :a')->bind(':a', $alias);
        return (int) $db->setQuery($q)->loadResult();
    }

    /** Menu links for this language: parishes, news, events, contact, search. */
    private static function links(string $tag, string $lang): array
    {
        $app = Factory::getApplication();
        $menu = $app->getMenu();
        $news = self::catId('news-' . $lang);
        $events = self::catId('events-' . $lang);
        $out = [];
        foreach ($menu->getItems(['language'], [[$tag, '*']]) as $it) {
            $q = $it->query ?? [];
            $opt = $q['option'] ?? '';
            $view = $q['view'] ?? '';
            $id = (int) ($q['id'] ?? 0);
            $url = Route::_('index.php?Itemid=' . (int) $it->id);
            if ($opt === 'com_content' && $view === 'category') {
                if ($id === 124 && !isset($out['parishes'])) {
                    $out['parishes'] = $url;
                } elseif ($id === $news && $news && !isset($out['news'])) {
                    $out['news'] = $url;
                } elseif ($id === $events && $events && !isset($out['events'])) {
                    $out['events'] = $url;
                }
            } elseif ($opt === 'com_content' && $view === 'article' && in_array((string) $it->alias, ['contact', 'contacto'], true) && !isset($out['contact'])) {
                $out['contact'] = $url;
            } elseif ($opt === 'com_finder' && !isset($out['search'])) {
                $out['search'] = $url;
            }
        }
        if (!isset($out['news'])) {
            $home = $menu->getDefault($tag);
            if ($home) {
                $out['news'] = Route::_('index.php?Itemid=' . (int) $home->id);
            }
        }
        return $out;
    }

    public static function render(array $props): string
    {
        try {
            $e = [self::class, 'e'];
            // On a 404 Joomla stops before onAfterRoute, where the plugin normally loads its labels
            Factory::getApplication()->getLanguage()->load('plg_system_mitropoliasources', JPATH_PLUGINS . '/system/mitropoliasources');
            $tag = Factory::getApplication()->getLanguage()->getTag();
            $lang = strtolower(substr($tag, 0, 2));
            $l = self::links($tag, $lang);

            $others = [];
            foreach (self::TITLES as $k => $t) {
                if ($k !== $lang) {
                    $others[] = '<span lang="' . $k . '">' . $e($t) . '</span>';
                }
            }

            $cards = '';
            foreach (['parishes' => 'PARISHES', 'news' => 'NEWS', 'events' => 'EVENTS', 'contact' => 'CONTACT'] as $k => $key) {
                if (!empty($l[$k])) {
                    $cards .= '<a class="m404-card" href="' . $e($l[$k]) . '"><span>' . $e(Text::_('MIT_404_' . $key . '_K')) . '</span>' . $e(Text::_('MIT_404_' . $key)) . '</a>';
                }
            }

            $search = '';
            if (!empty($l['search'])) {
                $search = '<form class="m404-search" role="search" method="get" action="' . $e($l['search']) . '">'
                    . '<label><span aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg></span>'
                    . '<input type="search" name="q" placeholder="' . $e(Text::_('MIT_404_SEARCH_PH')) . '" aria-label="' . $e(Text::_('MIT_404_SEARCH_PH')) . '"></label>'
                    . '<button type="submit">' . $e(Text::_('MIT_404_SEARCH_BTN')) . '</button></form>';
            }

            $home = Factory::getApplication()->getMenu()->getDefault($tag);
            $homeUrl = $home ? Route::_('index.php?Itemid=' . (int) $home->id) : '/' . $lang;
            $homeBtn = '<p class="m404-home"><a href="' . $e($homeUrl) . '"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/></svg>' . $e(Text::_('MIT_404_HOME')) . '</a></p>';

            $mail = '<a href="mailto:' . self::MAIL . '">' . self::MAIL . '</a>';

            return '<section class="m404' . (!empty($props['class']) ? ' ' . $e((string) $props['class']) : '') . '">'
                . '<div class="m404-num" aria-hidden="true">404</div>'
                . '<h1>' . $e(Text::_('MIT_404_TITLE')) . '</h1>'
                . '<p class="m404-text">' . $e(Text::_('MIT_404_TEXT')) . '</p>'
                . '<p class="m404-other">' . implode(' · ', $others) . '</p>'
                . $homeBtn
                . $search
                . ($cards !== '' ? '<nav class="m404-cards" aria-label="' . $e(Text::_('MIT_404_START')) . '">' . $cards . '</nav>' : '')
                . '<p class="m404-old">' . Text::sprintf('MIT_404_OLDLINK', $mail) . '</p>'
                . '</section>' . self::assets();
        } catch (\Throwable $x) {
            Log::add('404 page: ' . $x->getMessage(), Log::WARNING, 'mitropolia');
            return '';
        }
    }

    private static function assets(): string
    {
        return <<<'HTML'
<style>
.m404{position:relative;background:#F7F3EA;box-shadow:0 0 0 100vmax #F7F3EA;clip-path:inset(0 -100vmax);padding:60px 0;min-height:100vh;min-height:100dvh;box-sizing:border-box;display:flex;flex-direction:column;justify-content:center;text-align:center;font-family:'Source Sans 3',sans-serif;color:#1B2A4A}
.m404-num{font-family:'Baskervville',Georgia,serif;font-size:150px;line-height:1;color:#E4D8BE;letter-spacing:.04em}
.m404 h1{font-family:'Baskervville',Georgia,serif;font-weight:500;font-size:44px;line-height:1.15;color:#172E5C;margin:4px 0 12px}
.m404-text{font-family:'Source Serif 4',Georgia,serif;font-size:19px;line-height:1.6;color:#3C4A66;max-width:560px;margin:0 auto 6px}
.m404-other{font-family:'Source Serif 4',Georgia,serif;font-size:16px;line-height:1.6;color:#6B6F7B;font-style:italic;max-width:560px;margin:0 auto 26px}
.m404-home{margin:0 0 30px}
.m404-home a{display:inline-flex;align-items:center;gap:10px;height:46px;padding:0 24px;border-radius:4px;background:#203D78;color:#fff;font-weight:600;font-size:16px;text-decoration:none}
.m404-home a:hover{background:#172E5C;color:#fff;text-decoration:none}
.m404-search{display:flex;gap:10px;max-width:560px;margin:0 auto}
.m404-search label{flex:1;min-width:0;position:relative;display:block;margin:0}
.m404-search label>span{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#B99755;display:flex}
.m404-search input{height:46px;border:1px solid #D9CBAA;border-radius:4px;padding:0 14px 0 42px;font:inherit;font-size:16px;width:100%;box-sizing:border-box;background:#fff;color:#1B2A4A}
.m404-search button{height:46px;border:0;border-radius:4px;padding:0 22px;font:inherit;font-weight:600;font-size:16px;background:#A32D36;color:#fff;cursor:pointer}
.m404-search button:hover{background:#8a242c}
.m404-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;max-width:880px;margin:44px auto 0}
.m404-card{background:#fff;border:1px solid #E4D8BE;border-radius:6px;padding:22px 16px;display:flex;flex-direction:column;gap:6px;align-items:center;color:#172E5C;font-weight:600;text-decoration:none;transition:border-color .2s,box-shadow .2s}
.m404-card:hover{border-color:#B99755;color:#A32D36;text-decoration:none;box-shadow:0 10px 24px -16px rgba(23,46,92,.45)}
.m404-card span{color:#B99755;font-size:13px;letter-spacing:.1em;text-transform:uppercase}
.m404-old{margin:40px auto 0;font-size:14px;color:#6B6F7B;max-width:640px}
.m404-old a{color:#203D78}
@media (max-width:900px){.m404-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.m404{padding:40px 0}.m404-num{font-size:104px}.m404 h1{font-size:32px}.m404-text{font-size:18px}.m404-search{flex-direction:column}.m404-cards{gap:10px;margin-top:32px}}
</style>
HTML;
    }
}
