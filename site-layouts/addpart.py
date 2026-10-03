"""Adds the "Show" (part) select to a Mitropolia element: python3 addpart.py element 'Label=value' ..."""
import json, sys
name, opts = sys.argv[1], sys.argv[2:]
p = f'/home/claude/mitropolia-plugin/src/Source/elements/{name}/element.json'
d = json.load(open(p))
o = {"Everything (header included)": ""}
for x in opts:
    k, v = x.split('=', 1)
    o[k] = v
d['fields']['part'] = {"label": "Show", "type": "select", "options": o,
                       "description": "The page header (breadcrumb, title, intro) is built with YOOtheme elements; this block then shows only the part chosen here."}
fs = d['fieldset']['default']['fields'][0]['fields']
if 'part' not in fs:
    fs.insert(0, 'part')
json.dump(d, open(p, 'w'), ensure_ascii=False, indent=1)
print(name, list(o.values()))
