import json
V="5.0.50"
L={
'ro':dict(cat="99",news="/ro/stiri",more="Citește",kick_news="Știri",h_news="Din viața Mitropoliei",all_news="Toate știrile",
  q=[("Istorie","/ro/despre/istorie"),("Credința Ortodoxă","/ro/despre/credinta-ortodoxa"),("Ierarhi","/ro/ierarhi"),("Director parohii","/ro/structura/directoare/parohii"),("Scrisori pastorale","/ro/ierarhi/mitropolit/pastorale"),("Galerii foto","/ro/galerii-foto")],
  tiles=[("location","Găsește o parohie","Parohii, mănăstiri și misiuni în Statele Unite, Canada și America de Sud.","/ro/structura/directoare/parohii"),
         ("users","Directorul clerului","Datele de contact ale clericilor care slujesc în Mitropolie.","/ro/structura/directoare/cler"),
         ("file-text","Scrisori pastorale","Cuvântul ierarhilor la marile praznice și în timpul anului.","/ro/ierarhi/mitropolit/pastorale"),
         ("bookmark","Revista Credința","Revista trimestrială și Almanahul anual, de citit online sau descărcat.","/ro/publicatii/revista-credinta")],
  kick_h="Ierarhii noștri",h_h="Păstorii Mitropoliei",
  hier=[("Înaltpreasfinția Sa","Mitropolitul Nicolae","Arhiepiscop al Arhiepiscopiei Ortodoxe Române a Statelor Unite ale Americii și Mitropolit al Mitropoliei Ortodoxe Române a celor Două Americi","/images/site/hierarch-nicolae.jpg","/ro/ierarhi/mitropolit/biografia"),
        ("Preasfinția Sa","Episcopul Ioan Casian","Episcop al Episcopiei Ortodoxe Române a Canadei","/images/site/hierarch-ioan-casian.jpg","/ro/ierarhi/episcop/biografia")],
  bio="Biografia",kick_it="Itinerar pastoral",h_it="Vizite arhierești",
  kick_ev="Evenimente",h_ev="Întâlniri în toată Mitropolia",
  kick_st="Structura",h_st="O singură Mitropolie în cele două Americi",
  st_text="Mitropolia Ortodoxă Română a celor Două Americi este o mitropolie autonomă a Bisericii Ortodoxe Române, cu parohii în Statele Unite, Canada și America de Sud.",
  st=[("Statele Unite","Arhiepiscopia","Reședința la Chicago, Illinois. Păstorită de Mitropolitul Nicolae.","/ro/structura/eparhii/arhiepiscopia"),
      ("Canada","Episcopia Canadei","Păstorită de Episcopul Ioan Casian.","/ro/structura/eparhii/episcopia"),
      ("America de Sud","Parohii și misiuni","Comunități în limbile spaniolă și portugheză.","/ro/structura/directoare/parohii")],
  kick_pub="Publicații",pub_intro="Revista Mitropoliei apare în fiecare trimestru.",
  kick_don="Sprijin",h_don="Susțineți viața Bisericii",don_text="Darul dumneavoastră susține slujirile, publicațiile și misiunile Mitropoliei.",don_btn="Donează online",don="/ro/doneaza",
  partners="În comuniune și în presă"),
'en':dict(cat="100",news="/en/news",more="Read more",kick_news="News",h_news="From the life of the Metropolia",all_news="All news",
  q=[("History","/en/about/history"),("The Orthodox Faith","/en/about/the-orthodox-faith"),("Hierarchs","/en/hierarchs"),("Parish directory","/en/structure/directories/parish-directory"),("Pastoral letters","/en/hierarchs/metropolitan/pastoral-letters"),("Photo galleries","/en/photo-galleries")],
  tiles=[("location","Find a parish","Parishes, monasteries and missions in the United States, Canada and South America.","/en/structure/directories/parish-directory"),
         ("users","Clergy directory","Contact details for the clergy serving across the Metropolia.","/en/structure/directories/clergy"),
         ("file-text","Pastoral letters","Messages from the hierarchs for the great feasts and seasons.","/en/hierarchs/metropolitan/pastoral-letters"),
         ("bookmark","The Faith Magazine","The quarterly magazine and the yearly Almanac, to read online or download.","/en/publications/the-faith-magazine")],
  kick_h="Our hierarchs",h_h="Shepherds of the Metropolia",
  hier=[("His Eminence","Metropolitan Nicolae","Archbishop of the Romanian Orthodox Archdiocese of the United States of America and Metropolitan of the Romanian Orthodox Metropolia of the Americas","/images/site/hierarch-nicolae.jpg","/en/hierarchs/metropolitan/biography"),
        ("His Grace","Bishop Ioan Casian","Bishop of the Romanian Orthodox Diocese of Canada","/images/site/hierarch-ioan-casian.jpg","/en/hierarchs/bishop/biography")],
  bio="Biography",kick_it="Pastoral itinerary",h_it="Hierarchal visits",
  kick_ev="Events",h_ev="Gatherings across the Metropolia",
  kick_st="Structure",h_st="One Metropolia across the Americas",
  st_text="The Romanian Orthodox Metropolia of the Americas is an autonomous Metropolia of the Romanian Orthodox Church, with parishes in the United States, Canada and South America.",
  st=[("United States","The Archdiocese","Seat in Chicago, Illinois. Shepherded by Metropolitan Nicolae.","/en/structure/church-bodies/archdiocese"),
      ("Canada","The Diocese of Canada","Shepherded by Bishop Ioan Casian.","/en/structure/church-bodies/canadian-diocese"),
      ("South America","Parishes and missions","Communities in Spanish and Portuguese.","/en/structure/directories/parish-directory")],
  kick_pub="Publications",pub_intro="The magazine of the Metropolia, published each quarter.",
  kick_don="Support",h_don="Sustain the life of the Church",don_text="Your offering supports the ministries, publications and missions of the Metropolia.",don_btn="Give online",don="/en/donate",
  partners="In communion and in the media"),
'es':dict(cat="101",news="/es/noticias",more="Leer más",kick_news="Noticias",h_news="De la vida de la Metropolía",all_news="Todas las noticias",
  q=[("Historia","/es/sobre-nosotros/historia"),("La Fe Ortodoxa","/es/sobre-nosotros/la-fe-ortodoxa"),("Jerarcas","/es/jerarcas"),("Directorio de parroquias","/es/estructura/directorios/directorio-de-parroquias"),("Cartas pastorales","/es/jerarcas/metropolita/cartas-pastorales"),("Galerías de fotos","/es/galerias-de-fotos")],
  tiles=[("location","Encontrar una parroquia","Parroquias, monasterios y misiones en los Estados Unidos, Canadá y América del Sur.","/es/estructura/directorios/directorio-de-parroquias"),
         ("users","Directorio del clero","Datos de contacto del clero que sirve en la Metropolía.","/es/estructura/directorios/clero"),
         ("file-text","Cartas pastorales","Mensajes de los jerarcas para las grandes fiestas y los tiempos litúrgicos.","/es/jerarcas/metropolita/cartas-pastorales"),
         ("bookmark","Revista «La Fe»","La revista trimestral y el Almanaque anual, para leer en línea o descargar.","/es/publicaciones/revista-la-fe")],
  kick_h="Nuestros jerarcas",h_h="Pastores de la Metropolía",
  hier=[("Su Eminencia","Metropolita Nicolae","Arzobispo de la Arquidiócesis Ortodoxa Rumana de los Estados Unidos de América y Metropolita de la Metropolía Ortodoxa Rumana de las Américas","/images/site/hierarch-nicolae.jpg","/es/jerarcas/metropolita/biografia"),
        ("Su Excelencia","Obispo Ioan Casian","Obispo de la Diócesis Ortodoxa Rumana de Canadá","/images/site/hierarch-ioan-casian.jpg","/es/jerarcas/obispo/biografia")],
  bio="Biografía",kick_it="Itinerario pastoral",h_it="Visitas jerárquicas",
  kick_ev="Eventos",h_ev="Encuentros en toda la Metropolía",
  kick_st="Estructura",h_st="Una Metropolía en las Américas",
  st_text="La Metropolía Ortodoxa Rumana de las Américas es una metropolía autónoma de la Iglesia Ortodoxa Rumana, con parroquias en los Estados Unidos, Canadá y América del Sur.",
  st=[("Estados Unidos","La Arquidiócesis","Sede en Chicago, Illinois. Bajo el pastoreo del Metropolita Nicolae.","/es/estructura/eparquias/arquidiocesis"),
      ("Canadá","La Diócesis de Canadá","Bajo el pastoreo del Obispo Ioan Casian.","/es/estructura/eparquias/diocesis-de-canada"),
      ("América del Sur","Parroquias y misiones","Comunidades en español y portugués.","/es/estructura/directorios/directorio-de-parroquias")],
  kick_pub="Publicaciones",pub_intro="La revista de la Metropolía, publicada cada trimestre.",
  kick_don="Apoyo",h_don="Sostenga la vida de la Iglesia",don_text="Su ofrenda sostiene los ministerios, las publicaciones y las misiones de la Metropolía.",don_btn="Donar en línea",don="/es/donar",
  partners="En comunión y en los medios"),
}
PARTNERS=[("Patriarhia Română","https://patriarhia.ro"),("Episcopia Canadei","https://www.episcopia.ca"),("Assembly of Bishops","https://www.assemblyofbishops.org"),
 ("St Vladimir’s Seminary","https://www.svots.edu"),("Trinitas TV","https://www.trinitastv.ro"),("Radio Trinitas","https://www.radiotrinitas.ro"),("Basilica","https://www.basilica.ro"),("Ziarul Lumina","https://ziarullumina.ro"),("St. Parascheva Charity","https://www.spcharity.org")]

def el(t,props=None,children=None,**kw):
    d={"type":t,"props":props or {}}
    if children is not None: d["children"]=children
    d.update(kw); return d
def section(name,children,**p):
    return el("section",dict({"style":"default","width":"default","padding":""},**p),children,name=name)
def row(*cols): return el("row",{},list(cols))
def col(children,w=None,**p):
    pr=dict(p)
    if w: pr["width_medium"]=w
    return el("column",pr,children)
def kicker(t): return el("text",{"content":"<p>"+t+"</p>","text_style":"meta","margin":"remove","class":"mh-kicker"})
def head(t,tag="h2",style="h2",**p): return el("headline",dict({"content":t,"title_element":tag,"title_style":style},**p))
def button(t,link,style="default",**p): return el("button",dict({"margin":"medium"},**p),[el("button_item",{"content":t,"link":link,"button_style":style})])
def src(cat,limit,offset=0,props=None):
    a={"catid":[cat],"limit":limit,"order":"publish_up","order_direction":"DESC"}
    if offset: a["offset"]=offset
    return {"query":{"name":"customArticles","arguments":a},"props":props}

def layout(l):
    c=L[l]
    S=[]
    # 1 hero: latest news slideshow
    S.append(section("Hero · latest news",[row(col([
        el("slideshow",{"slideshow_ratio":"16:7","slideshow_min_height":"480","slideshow_animation":"fade","slideshow_autoplay":True,"slideshow_autoplay_interval":"7",
            "nav":"dotnav","nav_position":"bottom-left","nav_position_margin":"medium","slidenav":"default","slidenav_hover":True,"slidenav_breakpoint":"m",
            "overlay_container":"default","overlay_position":"bottom-left","overlay_style":"overlay-primary","overlay_padding":"","overlay_width":"large","text_color":"light",
            "show_title":True,"show_meta":True,"show_content":False,"show_link":True,"link_text":c["more"],"link_style":"default",
            "title_element":"h1","title_style":"h3","meta_style":"text-meta","meta_align":"above-title","content_style":"text-lead","image_loading":"eager","media_overlay":"rgba(23,46,92,0.15)"},
            [el("slideshow_item",{},source=src(c["cat"],4,0,{"title":{"name":"title"},"meta":{"name":"publish_up","filters":{"date":"j F Y"}},"content":{"name":"teaser","filters":{"limit":"130"}},"image":{"name":"images.image_intro"},"link":{"name":"link"}}))])
    ]))],padding="small",padding_remove_bottom=True,width="default"))
    # 2 quick links
    S.append(section("Quick links",[row(col([el("subnav",{"subnav_style":"divider","text_align":"center"},[el("subnav_item",{"content":t,"link":u}) for t,u in c["q"]])]))],style="muted",padding="xsmall"))
    # 3 tiles
    S.append(section("Quick access",[row(col([el("grid",{"grid_default":"1","grid_small":"2","grid_medium":"4","grid_large":"4","grid_xlarge":"4","grid_column_gap":"medium","grid_row_gap":"medium",
        "panel_style":"card-default","panel_padding":"default","panel_link":True,"show_link":False,"title_style":"h3","title_element":"h3","icon_width":"36","icon_color":"primary"},
        [el("grid_item",{"icon":i,"title":t,"content":"<p>"+x+"</p>","link":u}) for i,t,x,u in c["tiles"]])]))]))
    # 4 news
    S.append(section("News",[row(col([kicker(c["kick_news"]),head(c["h_news"],margin="small")])),
        row(col([el("grid",{"grid_default":"1","grid_small":"1","grid_medium":"1","grid_large":"1","grid_xlarge":"1","panel_style":"card-default","panel_card_image":True,"panel_link":True,"show_link":False,"image_align":"top","image_width":"960","image_height":"540",
                "title_style":"h3","title_element":"h3","meta_style":"text-meta","meta_align":"above-title","content_style":""},
                [el("grid_item",{},source=src(c["cat"],1,4,{"title":{"name":"title"},"meta":{"name":"publish_up","filters":{"date":"j F Y"}},"content":{"name":"teaser","filters":{"limit":"180"}},"image":{"name":"images.image_intro"},"link":{"name":"link"}}))])],"1-2"),
            col([el("grid",{"grid_default":"1","grid_small":"1","grid_medium":"1","grid_large":"1","grid_xlarge":"1","grid_row_gap":"small","panel_link":True,"show_link":False,"show_content":False,"image_align":"left","image_grid_width":"1-3","image_width":"240","image_height":"170","image_vertical_align":True,
                "title_style":"text-large","title_element":"h3","meta_style":"text-meta","meta_align":"above-title","divider":True},
                [el("grid_item",{},source=src(c["cat"],4,5,{"title":{"name":"title"},"meta":{"name":"publish_up","filters":{"date":"j F Y"}},"image":{"name":"images.image_intro"},"link":{"name":"link"}}))]),
                button(c["all_news"]+" →",c["news"],"default")],"1-2")),
        ],padding="large"))
    # 5 hierarchs + itinerary
    S.append(section("Hierarchs and itinerary",[row(
        col([kicker(c["kick_h"]),head(c["h_h"],margin="small"),
             el("grid",{"grid_default":"1","grid_small":"2","grid_medium":"2","grid_large":"2","grid_xlarge":"2","grid_column_gap":"medium","panel_link":False,"show_link":True,"link_style":"text","image_align":"top","image_width":"480","image_height":"560","image_border":"rounded",
                "title_style":"h4","title_element":"h3","meta_style":"text-meta","meta_align":"above-title","content_style":"text-small"},
                [el("grid_item",{"meta":m,"title":t,"content":"<p>"+x+"</p>","image":im,"image_alt":t,"link":u,"link_text":c["bio"]}) for m,t,x,im,u in c["hier"]])],"1-2"),
        col([kicker(c["kick_it"]),head(c["h_it"],margin="small"),el("mitropolia_home_itinerary",{"count":4})],"1-2"))],style="muted",padding="large"))
    # 6 events
    S.append(section("Events",[row(col([kicker(c["kick_ev"]),head(c["h_ev"],margin="small"),el("mitropolia_home_events",{"count":4,"columns":"4"})]))],padding="large"))
    # 7 structure
    S.append(section("Structure",[row(col([kicker(c["kick_st"]),head(c["h_st"],margin="small"),el("text",{"content":"<p>"+c["st_text"]+"</p>","text_style":"lead","max_width":"xlarge"}),
        el("grid",{"grid_default":"1","grid_small":"1","grid_medium":"3","grid_large":"3","grid_xlarge":"3","grid_column_gap":"medium","panel_style":"card-default","panel_padding":"default","panel_link":True,"show_link":False,"title_style":"h3","title_element":"h3","meta_style":"text-meta","meta_align":"above-title"},
           [el("grid_item",{"meta":m,"title":t,"content":"<p>"+x+"</p>","link":u}) for m,t,x,u in c["st"]])]))],style="muted",padding="large"))
    # 8 publication + donate
    S.append(section("Publications and support",[row(
        col([kicker(c["kick_pub"]),el("mitropolia_home_publication",{"kind":"mag","intro":c["pub_intro"]})],"2-3"),
        col([el("panel",{"panel_style":"card-secondary","title":c["h_don"],"meta":c["kick_don"],"content":"<p>"+c["don_text"]+"</p>","link":c["don"],"link_text":c["don_btn"],"link_style":"primary",
             "title_style":"h3","title_element":"h2","meta_style":"text-meta","meta_align":"above-title","panel_padding":"large"})],"1-3",vertical_align="middle"))],padding="large"))
    # 9 partners
    S.append(section("Partners",[row(col([el("text",{"content":"<p>"+c["partners"]+"</p>","text_style":"meta","text_align":"center","margin":"remove"}),
        el("subnav",{"subnav_style":"divider","text_align":"center","margin":"small"},[el("subnav_item",{"content":t,"link":u,"link_target":True}) for t,u in PARTNERS])]))],style="muted",padding="small"))
    return {"type":"layout","version":V,"children":S}

for l in L:
    json.dump(layout(l),open(f"/home/claude/work/home/{l}.json","w"),ensure_ascii=False)
    print(l,len(json.dumps(layout(l))))
