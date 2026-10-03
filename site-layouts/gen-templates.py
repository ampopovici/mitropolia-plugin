"""Builds the YOOtheme page templates, one per language (RO, EN, ES), the way the homepage is built:
the page header (breadcrumb, title, intro, date, lead photo) is native YOOtheme elements, bound to the
article or written in the page language; lists, filters and article bodies stay Mitropolia elements.
Styles: pages.css, pasted into YOOtheme > Settings > CSS after the homepage styles.

python3 gen-templates.py   ->  templates/<key>-<lang>.json  ({"tpl": {...}} ready for POST builder/template)"""
import hashlib, json, os, re

V = "5.0.50"
HERE = os.path.dirname(os.path.abspath(__file__))
LANGS = {"ro": "ro-RO", "en": "en-US", "es": "es-ES"}


def ini(code):
    out = {}
    with open(os.path.join(HERE, "..", "language", code, "plg_system_mitropoliasources.ini"), encoding="utf-8") as f:
        for line in f:
            m = re.match(r'^([A-Z0-9_]+)="(.*)"\s*$', line)
            if m:
                out[m.group(1)] = m.group(2).replace('\\"', '"')
    return out


T = {l: ini(c) for l, c in LANGS.items()}


def el(t, props=None, children=None, source=None, **kw):
    d = {"type": t, "props": props or {}}
    if children is not None:
        d["children"] = children
    if source:
        d["source"] = source
    d.update(kw)
    return d


def bind(query, field, prop="content", **extra):
    """Dynamic content: element prop <- field of the page's article/category."""
    return {"query": {"name": query}, "props": {prop: dict({"name": field, "filters": {}}, **extra)}}


def section(name, cls, rows):
    return el("section", {"style": "default", "width": "default", "padding": "", "class": cls}, rows, name=name)


def row(*cols, cls=""):
    return el("row", {"class": cls} if cls else {}, list(cols))


def col(children, w="1-1", cls=""):
    p = {"width_medium": w}
    if cls:
        p["class"] = cls
    return el("column", p, children)


HOME = {"ro": "Acasă", "en": "Home", "es": "Inicio"}
CUR = {"l": "ro"}  # language of the layout being built


def crumbs(current=True):
    return el("breadcrumbs", {"show_home": True, "home_text": HOME[CUR["l"]], "show_current": current, "margin": "remove", "class": "mx-crumbs"})


def h1(text=None, src=None, cls="mx-h1"):
    return el("headline", {"content": text or "", "title_element": "h1", "margin": "remove", "class": cls}, source=src)


def para(text=None, cls="mx-intro", src=None):
    return el("text", {"content": ("<p>" + text + "</p>") if text else "", "margin": "remove", "class": cls}, source=src)


def block(t, **props):
    return el(t, props)


def layout(*sections):
    return {"type": "layout", "version": V, "children": list(sections)}


# ------------------------------------------------------------------ list pages: crumbs, title, intro, search on the right, chips below

def list_head(title, intro, tools=None, chips=None, cls=""):
    """intro: text in the page language, or a Mitropolia element (when it depends on the category)."""
    rows = [row(col([crumbs(True)]))]
    text = [h1(title), intro if isinstance(intro, dict) else para(intro)]
    left = col(text, "expand", "mx-head-text")
    rows.append(row(left, col([tools], "auto", "mx-head-tools"), cls="mx-head-row") if tools else row(left, cls="mx-head-row"))
    if chips:
        rows.append(row(col([chips]), cls="mx-chips-row"))
    return section("Page header", ("mx-head " + cls).strip(), rows)


def body(name, *blocks, cls="mx-body"):
    return section(name, cls, [row(col(list(blocks)))])


def news_list(l):
    t = T[l]
    return layout(
        list_head(t["MIT_NEWS_TITLE"], t["MIT_NEWS_INTRO"], block("mitropolia_news_list", part="search"), block("mitropolia_news_list", part="chips")),
        body("List", block("mitropolia_news_list", part="body")),
    )


def pastoral_list(l, words=False):
    t = T[l]
    return layout(
        list_head(t["MIT_WM_TITLE" if words else "MIT_PL_TITLE"], block("mitropolia_pastoral_list", part="intro"),
                  block("mitropolia_pastoral_list", part="search"), block("mitropolia_pastoral_list", part="chips"), "mx-head-760"),
        body("List", block("mitropolia_pastoral_list", part="body")),
    )


# ------------------------------------------------------------------ article pages: crumb band, date and title, lead photo, body

def article_head(kicker=None, byline=None, meta=True, lead=True, cls=""):
    out = [section("Breadcrumb", "mx-band", [row(col([crumbs(False)]))])]
    items = []
    if meta:
        m = [kicker] if kicker else []
        m += [para(cls="mx-date", src=bind("article", "mitropolia_date")),
              para(cls="mx-reading", src=bind("article", "mitropolia_reading"))]
        items.append(row(col(m, cls="mx-meta")))
    items.append(row(col([h1(src=bind("article", "title"), cls="mx-title")])))
    if byline:
        items.append(row(col([byline], cls="mx-byline")))
    if lead:
        items.append(row(col([
            el("image", {"image": "", "margin": "remove", "class": "mx-lead", "image_svg_inline": False},
               source={"query": {"name": "article"}, "props": {"image": {"name": "mitropolia_lead", "filters": {}},
                                                               "image_alt": {"name": "mitropolia_lead_alt", "filters": {}}}}),
            para(cls="mx-lead-cap", src=bind("article", "mitropolia_lead_caption")),
        ])))
    out.append(section("Title", ("mx-head-art " + cls).strip(), items))
    return out


def news_article(l):
    return layout(*article_head(), body("Article", block("mitropolia_news_article", part="body"), cls="mx-body-art"))


def pastoral_letter(l):
    b = lambda p: block("mitropolia_pastoral_letter", part=p)
    return layout(*article_head(b("kicker"), b("byline"), lead=False, cls="mx-head-letter"), body("Letter", b("body"), cls="mx-body-art"))


def words_article(l):
    b = lambda p: block("mitropolia_pastoral_letter", part=p)
    return layout(*article_head(b("kicker"), b("byline"), cls="mx-head-words"), body("Article", b("body"), cls="mx-body-art"))



# ------------------------------------------------------------------ static page, tag, search, 404

def static_page(l):
    head = section("Page header", "mx-page-head", [
        row(col([crumbs(True)]), cls="mx-page-crumbs"),
        row(col([h1(src=bind("article", "title"), cls="mx-title"),
                 para(cls="mx-page-lead", src=bind("article", "mitropolia_description"))])),
    ])
    return layout(head, body("Page", block("mitropolia_static_page", part="body"), cls="mx-body-art"))


def tag_page(l):
    t = T[l]
    b = lambda p: block("mitropolia_tag_page", part=p)
    rows = [row(col([crumbs(True)])),
            row(col([para(t["MIT_TAG_LABEL"], cls="mx-kick"), h1(src=bind("tagsSingle", "title")), b("intro")], "expand", "mx-head-text"), cls="mx-head-row"),
            row(col([b("chips")]), cls="mx-chips-row")]
    return layout(section("Page header", "mx-head", rows), body("Results", b("body")))


SEARCH = {"ro": "Căutare", "en": "Search", "es": "Búsqueda"}


def search_page(l):
    b = lambda p: block("mitropolia_search", part=p)
    rows = [row(col([crumbs(False)])),
            row(col([h1(SEARCH[l]), b("intro"), b("search")], cls="mx-head-text mx-search-text"), cls="mx-search-row"),
            row(col([b("chips")]), cls="mx-chips-row")]
    return layout(section("Page header", "mx-head mx-head-search", rows), body("Results", b("body")))


MAIL = "contact@mitropolia.us"
NF_TITLES = {"ro": "Nu am găsit această pagină.", "en": "We couldn’t find that page.", "es": "No encontramos esta página."}
HOME_URL = {"ro": "/ro", "en": "/en", "es": "/es"}
HOME_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/></svg>'


def not_found(l):
    t = T[l]
    others = " · ".join('<span lang="%s">%s</span>' % (k, v) for k, v in NF_TITLES.items() if k != l)
    els = [
        el("text", {"content": "<p>404</p>", "margin": "remove", "class": "m404-num"}),
        el("headline", {"content": t["MIT_404_TITLE"], "title_element": "h1", "margin": "remove", "class": "m404-h1"}),
        el("text", {"content": "<p>" + t["MIT_404_TEXT"] + "</p>", "margin": "remove", "class": "m404-text"}),
        el("text", {"content": "<p>" + others + "</p>", "margin": "remove", "class": "m404-other"}),
        el("text", {"content": '<p><a href="' + HOME_URL[l] + '">' + HOME_SVG + t["MIT_404_HOME"] + "</a></p>", "margin": "remove", "class": "m404-home"}),
        block("mitropolia_not_found", part="search"),
        block("mitropolia_not_found", part="cards"),
        el("text", {"content": "<p>" + t["MIT_404_OLDLINK"].replace("%s", '<a href="mailto:' + MAIL + '">' + MAIL + "</a>") + "</p>", "margin": "remove", "class": "m404-old"}),
    ]
    return layout(section("Page not found", "m404 mx-404", [row(col(els))]))



# ------------------------------------------------------------------ hierarchs, itinerary, galleries, events

def hierarch_page(l):
    photo = col([el("image", {"image": "", "margin": "remove", "class": "mx-hier-ph", "image_svg_inline": False},
                    source={"query": {"name": "article"}, "props": {"image": {"name": "mitropolia_lead", "filters": {}},
                                                                    "image_alt": {"name": "title", "filters": {}}}})], "1-2", "mx-hier-phcol")
    text = col([
        el("image", {"image": "images/site/cruce.svg", "image_alt": "", "margin": "remove", "class": "mx-hier-cross", "image_svg_inline": False}),
        para(cls="mx-hier-honor", src=bind("article", "mitropolia_honor")),
        h1(src=bind("article", "mitropolia_name"), cls="mx-hier-name"),
        el("text", {"content": "", "margin": "remove", "class": "mx-hier-titles"}, source=bind("article", "mitropolia_titles")),
        el("text", {"content": "", "margin": "remove", "class": "mx-hier-lead"}, source=bind("article", "mitropolia_hier_lead")),
    ], "1-2", "mx-hier-tx")
    return layout(section("Hierarch", "mx-hier-hero", [row(photo, text, cls="mx-hier-row")]),
                  body("Biography, itinerary, writings, news", block("mitropolia_hierarch_page", part="body"), cls="mx-body-art"))


def hierarchs_list(l):
    t = T[l]
    return layout(section("Title", "mx-hl-hero", [row(col([h1(t["MIT_HL_TITLE"], cls="mx-hl-title")]))]),
                  body("Hierarchs", block("mitropolia_hierarchs_list", part="body"), cls="mx-body-art"))


ITIN = {"ro": "Itinerar pastoral", "en": "Pastoral Itinerary", "es": "Itinerario pastoral"}


def itinerary(l):
    b = lambda p: block("mitropolia_itinerary", part=p)
    rows = [row(col([h1(ITIN[l], cls="mx-h1 mx-it-h1"), b("intro")], "expand", "mx-head-text mx-it-text"),
                col([b("print")], "auto", "mx-head-tools"), cls="mx-it-row")]
    return layout(section("Page header", "mx-head mx-head-it", rows), body("Visits", b("body")))


GAL = {"ro": ("Galerii foto", "Fotografii de la slujbele, vizitele și evenimentele din viața Mitropoliei."),
       "en": ("Photo galleries", "Photographs from the services, visits and events in the life of the Metropolia."),
       "es": ("Galerías de fotos", "Fotografías de los oficios, visitas y eventos de la vida de la Metropolía.")}


def gallery_list(l):
    title, intro = GAL[l]
    return layout(list_head(title, intro, block("mitropolia_gallery_list", part="search")),
                  body("Galleries", block("mitropolia_gallery_list", part="body")))


def gallery_page(l):
    return layout(section("Breadcrumb", "mx-band", [row(col([crumbs(False)]))]),
                  body("Gallery", block("mitropolia_gallery_page", part="body"), cls="mx-body-art"))


def events_list(l):
    b = lambda p: block("mitropolia_events_list", part=p)
    rows = [row(col([crumbs(True)])), row(col([b("intro")]), cls="mx-ev-row"), row(col([b("chips")]), cls="mx-chips-row mx-ev-chips")]
    return layout(section("Page header", "mx-head mx-head-ev", rows), body("Events", b("body")))


def event(l):
    return layout(section("Breadcrumb", "mx-band mx-band-ev", [row(col([crumbs(False)]))]),
                  body("Event", block("mitropolia_event", part="body"), cls="mx-body-art"))


TEMPLATES = {
    # key: (existing template id, name, type, catids, builder)
    "news-list": ("k2yyjw9k", "News list", "com_content.category", ["99", "100", "101"], news_list),
    "news-article": ("u87nnnv4", "News article", "com_content.article", ["99", "100", "101"], news_article),
    "pastoral-letter": ("8np9hryq", "Pastoral letter", "com_content.article", ["137", "138", "139", "140", "141", "142"], pastoral_letter),
    "pastoral-list": ("dd6gk1od", "Pastoral letters list", "com_content.category", ["106", "107", "108", "137", "138", "139", "140", "141", "142"], pastoral_list),
    "words-article": ("qf51qa0e", "Words and Messages article", "com_content.article", ["109", "110", "111", "143", "144", "145", "146", "147", "148"], words_article),
    "static-page": ("rtdxt9xl", "Static page", "com_content.article", ["121", "122", "123"], static_page),
    "tag-page": ("6g44mvak", "Tag page", "com_tags.tag", None, tag_page),
    "search": ("if3pvfu9", "Search results", "com_finder.search", None, search_page),
    "404": (None, "Page not found (404)", "error-404", None, not_found),
    "hierarch-page": ("hrmtjnjj", "Hierarch page", "com_content.article", ["112", "113", "114", "129", "130", "131"], hierarch_page),
    "hierarchs-list": ("lmowfkkz", "Hierarchs list", "com_content.category", ["112", "113", "114"], hierarchs_list),
    "itinerary": ("5n7iso63", "Pastoral itinerary", "com_content.category", ["134", "135"], itinerary),
    "gallery-list": ("1gzdt10a", "Gallery list", "com_content.category", ["127"], gallery_list),
    "gallery-page": ("fzvvh9bq", "Gallery page", "com_content.article", ["127"], gallery_page),
    "events-list": ("j90l29sz", "Events list", "com_content.category", ["103", "104", "105"], events_list),
    "event": ("5umq1f54", "Event", "com_content.article", ["103", "104", "105"], event),
    "words-list": ("h82m87em", "Words and Messages list", "com_content.category", ["109", "110", "111", "143", "144", "145", "146", "147", "148"], lambda l: pastoral_list(l, True)),
}

LANG_LABEL = {"ro": "RO", "en": "EN", "es": "ES"}

if __name__ == "__main__":
    os.makedirs(os.path.join(HERE, "templates"), exist_ok=True)
    for key, (tid, name, typ, cats, fn) in TEMPLATES.items():
        for l, code in LANGS.items():
            CUR["l"] = l
            q = {"lang": code.lower()}  # YOOtheme matches lowercase codes
            if cats is not None:
                q = {"catid": cats, "tag": [], "lang": code.lower()}
            elif typ == "com_tags.tag":
                q = {"lang": code.lower()}
            elif typ == "com_finder.search":
                q = {"pages": "", "lang": code.lower()}
            tpl = {"type": typ, "query": q, "name": name + " · " + LANG_LABEL[l], "layout": fn(l)}
            # RO keeps the id of the old all-language template; EN and ES get a stable id of their own
            # (the 404 page keeps its old all-language template as a fallback, so all three get new ids)
            tpl["id"] = tid if (l == "ro" and tid) else "mx" + hashlib.md5((key + l).encode()).hexdigest()[:6]
            with open(os.path.join(HERE, "templates", key + "-" + l + ".json"), "w", encoding="utf-8") as f:
                json.dump({"tpl": tpl}, f, ensure_ascii=False)
    print("ok")
