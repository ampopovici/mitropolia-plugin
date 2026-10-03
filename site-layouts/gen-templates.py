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


TEMPLATES = {
    # key: (existing template id, name, type, catids, builder)
    "news-list": ("k2yyjw9k", "News list", "com_content.category", ["99", "100", "101"], news_list),
    "news-article": ("u87nnnv4", "News article", "com_content.article", ["99", "100", "101"], news_article),
    "pastoral-letter": ("8np9hryq", "Pastoral letter", "com_content.article", ["137", "138", "139", "140", "141", "142"], pastoral_letter),
    "pastoral-list": ("dd6gk1od", "Pastoral letters list", "com_content.category", ["106", "107", "108", "137", "138", "139", "140", "141", "142"], pastoral_list),
    "words-article": ("qf51qa0e", "Words and Messages article", "com_content.article", ["109", "110", "111", "143", "144", "145", "146", "147", "148"], words_article),
    "words-list": ("h82m87em", "Words and Messages list", "com_content.category", ["109", "110", "111", "143", "144", "145", "146", "147", "148"], lambda l: pastoral_list(l, True)),
}

LANG_LABEL = {"ro": "RO", "en": "EN", "es": "ES"}

if __name__ == "__main__":
    os.makedirs(os.path.join(HERE, "templates"), exist_ok=True)
    for key, (tid, name, typ, cats, fn) in TEMPLATES.items():
        for l, code in LANGS.items():
            CUR["l"] = l
            tpl = {"type": typ, "query": {"catid": cats, "tag": [], "lang": code.lower()},  # YOOtheme matches lowercase codes
                   "name": name + " · " + LANG_LABEL[l], "layout": fn(l)}
            # RO keeps the id of the old all-language template; EN and ES get a stable id of their own
            tpl["id"] = tid if l == "ro" else "mx" + hashlib.md5((key + l).encode()).hexdigest()[:6]
            with open(os.path.join(HERE, "templates", key + "-" + l + ".json"), "w", encoding="utf-8") as f:
                json.dump({"tpl": tpl}, f, ensure_ascii=False)
    print("ok")
