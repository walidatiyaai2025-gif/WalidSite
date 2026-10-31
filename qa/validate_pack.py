#!/usr/bin/env python3
import json,hashlib,sys,xml.etree.ElementTree as ET
from pathlib import Path
root=Path(__file__).resolve().parents[1]
m=json.loads((root/'MANIFEST.json').read_text(encoding='utf-8'))
errors=[]
for rec in m['integrityItems']:
 p=root/rec['path']
 if not p.exists(): errors.append('MISSING '+rec['path']);continue
 b=p.read_bytes()
 if len(b)!=rec['size_bytes'] or hashlib.sha256(b).hexdigest()!=rec['sha256']:errors.append('HASH '+rec['path'])
s=json.loads((root/'contracts/screens.json').read_text(encoding='utf-8'))['screens']
if len(s)!=40 or len({x['id'] for x in s})!=40:errors.append('screen count/ids')
for x in s:
 if not (root/x['reference_image']).is_file():errors.append('MISSING IMAGE '+x['id'])
for p in (root/'assets').rglob('*.svg'):
 try:ET.parse(p)
 except Exception:errors.append('SVG '+p.name)
tasks=json.loads((root/'tasks/master-task-register.json').read_text(encoding='utf-8'))['tasks']
ids={t['id'] for t in tasks}
for t in tasks:
 for dep in t['dependsOn']:
  if dep not in ids:errors.append('DEPENDENCY '+dep)
for lang in ['ar','en']:
 tr=json.loads((root/'locales'/f'{lang}.json').read_text(encoding='utf-8'))
 if set(tr['screenTitles'])!={x['id'] for x in s}:errors.append('SCREEN LOCALE '+lang)
 if set(tr['actions'])!={a for x in s for a in x['actions']}:errors.append('ACTION LOCALE '+lang)
if len(list((root/'github/workflows').glob('*.yml')))!=2:errors.append('WORKFLOW COUNT')
if errors:print('FAILED',errors);sys.exit(1)
print('PASS',len(m['integrityItems']),'files',len(s),'screens',len(tasks),'tasks')
