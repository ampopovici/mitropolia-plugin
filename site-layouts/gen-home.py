"""Builds the three homepage builder layouts (RO, EN, ES) from the approved mockup new/home.html.
Static content is native YOOtheme elements; news, events, visits and the newest issue are Mitropolia elements.
Styles: home.css, pasted into YOOtheme > Settings > CSS."""
import json, os

V = "5.0.50"
ICON = "/plugins/system/mitropoliasources/src/Source/assets/home/"

L = {
'ro': dict(
  q=[("Istorie", "/ro/despre/istorie"), ("Credința Ortodoxă", "/ro/despre/credinta-ortodoxa"), ("Ierarhi", "/ro/ierarhi"),
     ("Director parohii", "/ro/structura/directoare/parohii"), ("Scrisori pastorale", "/ro/ierarhi/mitropolit/pastorale"), ("Galerii foto", "/ro/galerii-foto")],
  tiles=[("parish", "Găsește o parohie", "Parohii, mănăstiri și misiuni în Statele Unite, Canada și America de Sud.", "/ro/structura/directoare/parohii"),
         ("clergy", "Directorul clerului", "Datele de contact ale clericilor care slujesc în Mitropolie.", "/ro/structura/directoare/cler"),
         ("letters", "Scrisori pastorale", "Cuvântul ierarhilor la marile praznice și în timpul anului.", "/ro/ierarhi/mitropolit/pastorale"),
         ("magazine", "Revista Credința", "Revista trimestrială și Almanahul anual, gratuit de descărcat.", "/ro/publicatii/revista-credinta")],
  news_k="Știri", news_h="Știri din Mitropolie",
  hier_k="Ierarhii noștri", hier_h="Păstorii Mitropoliei",
  hier=[("Înaltpreasfinția Sa", "Mitropolitul Nicolae", "Arhiepiscop al Arhiepiscopiei Ortodoxe Române a Statelor Unite ale Americii și Mitropolit al Mitropoliei Ortodoxe Române a celor Două Americi",
         "/images/site/hierarch-nicolae.jpg", [("Biografia", "/ro/ierarhi/mitropolit/biografia"), ("Scrisori pastorale", "/ro/ierarhi/mitropolit/pastorale")]),
        ("Preasfinția Sa", "Episcopul Ioan Casian", "Episcop al Episcopiei Ortodoxe Române a Canadei",
         "/images/site/hierarch-ioan-casian.jpg", [("Biografia", "/ro/ierarhi/episcop/biografia"), ("Scrisori pastorale", "/ro/ierarhi/episcop/pastorale")])],
  st_k="Structura", st_h="O singură Mitropolie în cele două Americi",
  st_text="Mitropolia Ortodoxă Română a celor Două Americi este o mitropolie autonomă a Bisericii Ortodoxe Române, cu parohii, mănăstiri și misiuni în Statele Unite, Canada și America de Sud.",
  st=[("Statele Unite", "Arhiepiscopia", "Reședința la Chicago, Illinois. Păstorită de Mitropolitul Nicolae.", "/ro/structura/eparhii/arhiepiscopia"),
      ("Canada", "Episcopia", "Păstorită de Episcopul Ioan Casian.", "/ro/structura/eparhii/episcopia"),
      ("America de Sud", "Iglesia Ortodoxa Rumana", "Parohii și misiuni de limbă spaniolă și portugheză.", "/ro/structura/directoare/parohii")],
  don_k="Sprijin", don_h="Susțineți viața Bisericii", don_t="Darul dumneavoastră susține slujirile, publicațiile și misiunile Mitropoliei.", don_b="Donează online", don="/ro/doneaza",
  part="Parteneri și prieteni ai Mitropoliei",
  stay_h="Rămâneți în legătură", stay_t="Primiți prin e-mail scrisorile pastorale, știrile și fiecare număr nou al revistei Credința.", stay_b="Abonează-te", stay="/ro/contact"),
'en': dict(
  q=[("History", "/en/about/history"), ("The Orthodox Faith", "/en/about/the-orthodox-faith"), ("Hierarchs", "/en/hierarchs"),
     ("Parish directory", "/en/structure/directories/parish-directory"), ("Pastoral letters", "/en/hierarchs/metropolitan/pastoral-letters"), ("Photo gallery", "/en/photo-galleries")],
  tiles=[("parish", "Find a parish", "Parishes, monasteries and missions in the United States, Canada and South America.", "/en/structure/directories/parish-directory"),
         ("clergy", "Clergy directory", "Contact details for the clergy serving across the Metropolia.", "/en/structure/directories/clergy"),
         ("letters", "Pastoral letters", "Messages from the hierarchs for the great feasts and seasons.", "/en/hierarchs/metropolitan/pastoral-letters"),
         ("magazine", "The Faith Magazine", "The quarterly magazine and the annual Almanac, free to download.", "/en/publications/the-faith-magazine")],
  news_k="Headlines", news_h="News from the Metropolia",
  hier_k="Our hierarchs", hier_h="Shepherds of the Metropolia",
  hier=[("His Eminence", "Metropolitan Nicolae", "Archbishop of the Romanian Orthodox Archdiocese of the United States of America and Metropolitan of the Romanian Orthodox Metropolia of the Americas",
         "/images/site/hierarch-nicolae.jpg", [("Biography", "/en/hierarchs/metropolitan/biography"), ("Pastoral letters", "/en/hierarchs/metropolitan/pastoral-letters")]),
        ("His Grace", "Bishop Ioan Casian", "Bishop of the Romanian Orthodox Diocese of Canada",
         "/images/site/hierarch-ioan-casian.jpg", [("Biography", "/en/hierarchs/bishop/biography"), ("Pastoral letters", "/en/hierarchs/bishop/pastoral-letters")])],
  st_k="Structure", st_h="One Metropolia across the Americas",
  st_text="The Romanian Orthodox Metropolia of the Americas is an autonomous Metropolia of the Romanian Orthodox Church, with parishes, monasteries and missions in the United States, Canada and South America.",
  st=[("United States", "The Archdiocese", "Seat in Chicago, Illinois. Shepherded by Metropolitan Nicolae.", "/en/structure/church-bodies/archdiocese"),
      ("Canada", "The Episcopate", "Shepherded by Bishop Ioan Casian.", "/en/structure/church-bodies/canadian-diocese"),
      ("South America", "Iglesia Ortodoxa Rumana", "Parishes and missions in Spanish and Portuguese.", "/en/structure/directories/parish-directory")],
  don_k="Support", don_h="Sustain the life of the Church", don_t="Your offering supports the ministries, publications and missions of the Metropolia.", don_b="Give online", don="/en/donate",
  part="Partners and friends of the Metropolia",
  stay_h="Stay connected", stay_t="Receive pastoral letters, news and each new issue of Credința by email.", stay_b="Subscribe", stay="/en/contact"),
'es': dict(
  q=[("Historia", "/es/sobre-nosotros/historia"), ("La Fe Ortodoxa", "/es/sobre-nosotros/la-fe-ortodoxa"), ("Jerarcas", "/es/jerarcas"),
     ("Directorio de parroquias", "/es/estructura/directorios/directorio-de-parroquias"), ("Cartas pastorales", "/es/jerarcas/metropolita/cartas-pastorales"), ("Galería de fotos", "/es/galerias-de-fotos")],
  tiles=[("parish", "Encontrar una parroquia", "Parroquias, monasterios y misiones en los Estados Unidos, Canadá y América del Sur.", "/es/estructura/directorios/directorio-de-parroquias"),
         ("clergy", "Directorio del clero", "Datos de contacto del clero que sirve en la Metrópolis.", "/es/estructura/directorios/clero"),
         ("letters", "Cartas pastorales", "Mensajes de los jerarcas para las grandes fiestas y los tiempos litúrgicos.", "/es/jerarcas/metropolita/cartas-pastorales"),
         ("magazine", "Revista «La Fe»", "La revista trimestral y el Almanaque anual, de descarga gratuita.", "/es/publicaciones/revista-la-fe")],
  news_k="Titulares", news_h="Noticias de la Metrópolis",
  hier_k="Nuestros jerarcas", hier_h="Pastores de la Metrópolis",
  hier=[("Su Eminencia", "Metropolitano Nicolae", "Arzobispo de la Arquidiócesis Ortodoxa Rumana de los Estados Unidos de América y Metropolitano de la Metrópolis Ortodoxa Rumana de las Dos Américas",
         "/images/site/hierarch-nicolae.jpg", [("Biografía", "/es/jerarcas/metropolita/biografia"), ("Cartas pastorales", "/es/jerarcas/metropolita/cartas-pastorales")]),
        ("Su Gracia", "Obispo Ioan Casian", "Obispo de la Diócesis Ortodoxa Rumana de Canadá",
         "/images/site/hierarch-ioan-casian.jpg", [("Biografía", "/es/jerarcas/obispo/biografia"), ("Cartas pastorales", "/es/jerarcas/obispo/cartas-pastorales")])],
  st_k="Estructura", st_h="Una Metrópolis en las Américas",
  st_text="La Metrópolis Ortodoxa Rumana de las Dos Américas es una metrópolis autónoma de la Iglesia Ortodoxa Rumana, con parroquias, monasterios y misiones en los Estados Unidos, Canadá y América del Sur.",
  st=[("Estados Unidos", "La Arquidiócesis", "Sede en Chicago, Illinois. Bajo el pastoreo del Metropolitano Nicolae.", "/es/estructura/eparquias/arquidiocesis"),
      ("Canadá", "La Diócesis de Canadá", "Bajo el pastoreo del Obispo Ioan Casian.", "/es/estructura/eparquias/diocesis-de-canada"),
      ("América del Sur", "Iglesia Ortodoxa Rumana", "Parroquias y misiones en español y portugués.", "/es/estructura/directorios/directorio-de-parroquias")],
  don_k="Apoyo", don_h="Sostenga la vida de la Iglesia", don_t="Su ofrenda sostiene los ministerios, las publicaciones y las misiones de la Metrópolis.", don_b="Donar en línea", don="/es/donar",
  part="Socios y amigos de la Metrópolis",
  stay_h="Manténgase en contacto", stay_t="Reciba por correo electrónico las cartas pastorales, las noticias y cada nuevo número de la revista.", stay_b="Suscribirse", stay="/es/contacto"),
}
# Partner logos, in gold on the navy band. (image, small line, big line, link); a full logo has no lines.
PARTNERS = [("patriarhia-romana-gold.svg", "Patriarhia", "Română", "https://patriarhia.ro"),
            ("episcopia-canada.png", "", "", "https://www.episcopia.ca"),
            ("assembly-logo.png", "", "", "https://www.assemblyofbishops.org"),
            ("cross-ring.svg", "Saint Paraskeva", "Orthodox Charity", "https://www.spcharity.org"),
            ("radio-trinitas.svg", "", "", "https://radiotrinitas.ro"),
            ("trinitas-tv.svg", "", "", "https://trinitas.tv"),
            ("basilica-ro.svg", "", "", "https://basilica.ro"),
            ("ziarul-lumina.svg", "", "", "https://ziarullumina.ro")]


def el(t, props=None, children=None, **kw):
    d = {"type": t, "props": props or {}}
    if children is not None:
        d["children"] = children
    d.update(kw)
    return d


def section(name, cls, rows, **p):
    return el("section", dict({"style": "default", "width": "default", "padding": "", "class": cls}, **p), rows, name=name)


def row(*cols, **p):
    return el("row", dict(p), list(cols))


def col(children, w=None, **p):
    pr = dict(p)
    if w:
        pr["width_medium"] = w
    return el("column", pr, children)


def kick(t, extra=""):
    return el("text", {"content": "<p>" + t + "</p>", "margin": "remove", "class": ("mh-kick " + extra).strip()})


def h2(t, extra="", tag="h2"):
    return el("headline", {"content": t, "title_element": tag, "margin": "remove", "class": ("mh-h2 " + extra).strip()})


def lead(t):
    return el("text", {"content": "<p>" + t + "</p>", "margin": "remove", "class": "mh-lead"})


def grid(items, cols, cls, **p):
    g = {"grid_default": "1", "grid_small": cols[0], "grid_medium": cols[1], "grid_large": cols[1], "grid_xlarge": cols[1],
         "grid_column_gap": "medium", "grid_row_gap": "medium", "class": cls, "show_link": False, "title_element": "h3"}
    g.update(p)
    return el("grid", g, items)


def layout(l):
    c = L[l]
    S = []
    S.append(section("Hero · latest news", "mh-s-hero", [row(col([el("mitropolia_home_hero", {"count": 4})]))]))
    S.append(section("Quick links", "mh-s-ql", [row(col([el("subnav", {"subnav_style": "", "margin": "remove"},
        [el("subnav_item", {"content": t, "link": u}) for t, u in c["q"]])]))]))
    S.append(section("Quick access", "mh-s-tiles", [row(col([grid(
        [el("grid_item", {"image": ICON + i + ".svg", "image_alt": "", "title": t, "content": "<p>" + x + "</p>", "link": u}) for i, t, x, u in c["tiles"]],
        ("2", "4"), "mh-tiles", panel_style="card-default", panel_link=True, image_width="28", image_height="28", image_align="top", title_style="")]))]))
    S.append(section("News", "mh-s-news", [row(col([h2(c["news_h"], "mh-center"),
        el("mitropolia_home_news", {"offset": 4, "count": 3})]))]))
    S.append(section("Hierarchs and visits", "mh-s-hier", [
        row(col([h2(c["hier_k"])])),
        row(col([el("mitropolia_home_hierarchs", {})], "3-5"), col([el("mitropolia_home_itinerary", {})], "2-5"), gutter="large", margin="medium")]))
    S.append(section("Events", "mh-s-events", [row(col([el("mitropolia_home_events", {"count": 4})]))]))
    S.append(section("Structure", "mh-s-struct", [row(col([kick(c["st_k"]), h2(c["st_h"]), lead(c["st_text"]),
        grid([el("grid_item", {"meta": m, "title": t, "content": "<p>" + x + "</p>", "link": u}) for m, t, x, u in c["st"]],
             ("1", "3"), "mh-struct-grid", panel_link=True, meta_align="above-title", title_style="", meta_style="", panel_style="")]))]))
    S.append(section("Publications and support", "mh-s-pub", [row(
        col([el("mitropolia_home_publication", {"kind": "mag"})], "3-5"),
        col([el("panel", {"meta": c["don_k"], "title": c["don_h"], "content": "<p>" + c["don_t"] + "</p>", "link": c["don"], "link_text": c["don_b"],
                          "link_style": "default", "panel_style": "card-default", "title_element": "h2", "title_style": "", "meta_style": "", "meta_align": "above-title",
                          "class": "mh-donate"})], "2-5"), gutter="large")]))
    S.append(section("Partners", "mh-s-partners", [row(col([
        grid([el("grid_item", dict({"image": "/images/partners/" + f, "image_alt": (sm + " " + bg).strip() or f.split(".")[0].replace("-", " ").title(), "link": u},
                                   **({"meta": sm, "title": bg} if bg else {}))) for f, sm, bg, u in PARTNERS],
             ("2", "4"), "mh-logos", panel_link=True, grid_column_gap="large", grid_row_gap="large", image_align="left", image_grid_width="auto",
             image_vertical_align=True, meta_align="above-title", title_style="", meta_style="", panel_style="")]))]))
    S.append(section("Stay connected", "mh-s-stay", [row(
        col([h2(c["stay_h"]), lead(c["stay_t"])], "2-5", vertical_align="middle"),
        col([el("button", {"margin": "remove", "text_align": "right", "text_align_breakpoint": "m"},
                [el("button_item", {"content": c["stay_b"], "link": c["stay"], "button_style": "primary"})])], "3-5", vertical_align="middle"), gutter="large")]))
    return {"type": "layout", "version": V, "children": S}


here = os.path.dirname(os.path.abspath(__file__))
for l in L:
    with open(os.path.join(here, "home-%s.json" % l), "w") as f:
        json.dump(layout(l), f, ensure_ascii=False, separators=(",", ":"))
    print(l, "ok")
