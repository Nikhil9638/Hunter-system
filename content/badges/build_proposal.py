"""Original geometric badge icons; editable SVG stays private, PNG exports are public."""
import html
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parent
SOURCE=ROOT/'sources'
SOURCE.mkdir(exist_ok=True)
names=['Shadow Recruit','Shadow Soldier','Shadow Knight','Elite Shadow Knight','Shadow Commander','Shadow Monarch']
prices=[10,15,20,30,45,60]
motifs=['An angular cyan spark in a metallic frame.','A violet compass with cyan guide points.','A branching cyan path joining three violet nodes.','Two open orbital arcs around a violet core.','A cyan shield holding two abstract linked curves.','An open metallic book beneath a violet crescent.']
shapes=[
 '<path d="M142 57 94 128h31l-11 65 51-82h-33z" fill="#72e8f2"/><path d="m76 83 8 9m89 70 9 8m-112-34h12m90-56-8 9" stroke="#a49afb"/>',
 '<circle cx="128" cy="128" r="49" stroke="#8999b1"/><path d="m153 91-11 51-39 23 11-51z" fill="#a49afb"/><path d="m153 91-39 23 28 28z" fill="#72e8f2"/><path d="M128 63v12m0 106v12M63 128h12m106 0h12" stroke="#72e8f2"/>',
 '<path d="M128 180v-46L91 97m37 37 37-37" stroke="#72e8f2" stroke-width="10"/><circle cx="91" cy="90" r="15" fill="#a49afb"/><circle cx="165" cy="90" r="15" fill="#a49afb"/><circle cx="128" cy="180" r="15" fill="#72e8f2"/>',
 '<path d="M82 151a50 50 0 0 1 73-62m19 16a50 50 0 0 1-73 62" stroke="#72e8f2" stroke-width="10"/><path d="m144 69 14 22-25 4m-21 90-14-22 25-4" stroke="#72e8f2"/><circle cx="128" cy="128" r="20" fill="#a49afb"/>',
 '<path d="m128 64 48 20v48c0 26-25 48-48 62-23-14-48-36-48-62V84z" stroke="#72e8f2" stroke-width="8" fill="#152b3c"/><path d="M117 106h-8a14 14 0 0 0 0 28h19m11 16h8a14 14 0 0 0 0-28h-19" stroke="#a49afb" stroke-width="9"/>',
 '<path d="M128 124c-18-12-33-15-51-12v63c18-3 33 0 51 12 18-12 33-15 51-12v-63c-18-3-33 0-51 12z" fill="#213044" stroke="#b5c4d8"/><path d="M128 126v58m-38-55 24 7m-24 12 24 7m28-19 24-7m-24 26 24-7" stroke="#72e8f2" stroke-width="5"/><path d="M141 58a28 28 0 1 0 18 47 28 28 0 0 1-18-47z" fill="#a49afb"/>'
]
badges=[]
cards=[]
for name,price,motif,shape in zip(names,prices,motifs,shapes):
    slug=name.lower().replace(' ','-')
    filename=f'm8b-{slug}-v2.png'
    description=f'{motif} Purchased cosmetic, not an earned achievement or Python mastery certificate. Never unlocks lessons or changes E–S Hunter rank.'
    assert len(description)<=255
    svg=f'''<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 256 256">
<title>{html.escape(name)} cosmetic badge proposal</title>
<g stroke-linecap="round" stroke-linejoin="round" stroke-width="6" fill="none">
<path d="m128 18 94 54v112l-94 54-94-54V72z" fill="#101a2a" stroke="#778ba7"/>
<path d="m128 29 84 49v100l-84 49-84-49V78z" stroke="#344a64" stroke-width="3"/>
<path d="m55 80 26-15m94 127 26-15" stroke="#72e8f2" stroke-width="4"/>
{shape}</g></svg>'''
    (SOURCE/(slug+'.svg')).write_text(svg,encoding='utf-8')
    badges.append(dict(proposal_key=slug,name=name,description=description,proposed_price=price,approved_price=price,
                       image_ref='badges/'+filename,source='sources/'+slug+'.svg',status='off-sale preparation',user_names_prices_approved=True,price_approved=False))
    url='../../assets/badges/'+filename
    cards.append(f'''<article><div class="art"><img src="{url}" width="160" height="160" alt="{name}: {html.escape(motif)}"></div>
<h2>{name}</h2><p class="price">Approved: {price} coins <span>Administrator price-approval event pending</span></p>
<p>{html.escape(description)}</p><div class="sizes"><img src="{url}" width="64" height="64" alt="{name}, 64 pixel preview"><img src="{url}" width="32" height="32" alt="{name}, 32 pixel preview"><span>64px / 32px</span></div></article>''')
manifest=dict(milestone='8B revision',status='User-approved names/prices; artwork shown for review; not on sale',original_artwork=True,
              method='Original code-authored SVG geometry rendered to transparent PNG using installed Chrome',
              max_first_completion_coins=180,approved_total=180,sample_retirement_approved=True,badges=badges)
(ROOT/'manifest.json').write_text(json.dumps(manifest,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
(ROOT/'review.html').write_text('''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hunter System — approved badge titles and prices</title><style>
*{box-sizing:border-box}body{margin:0;background:#0b1220;color:#e4edf7;font:16px/1.55 system-ui,sans-serif}main{max-width:1120px;margin:auto;padding:28px}h1{font-size:32px;line-height:1.2}h2{font-size:22px;margin:16px 0 4px}.label{color:#72e8f2;letter-spacing:2px;font-size:13px}.intro{max-width:850px;color:#b5c4d8}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin-top:28px}article{border:1px solid #344a64;border-radius:18px;padding:22px;background:#101a2a}.art{display:grid;place-items:center;border-radius:12px;background:repeating-conic-gradient(#142238 0% 25%,#1b2e45 0% 50%) 50%/20px 20px}.price{color:#72e8f2;margin-top:0}.price span{display:block;color:#a49afb;font-size:14px}.sizes{display:flex;align-items:center;gap:12px;border-top:1px solid #344a64;padding-top:12px}.sizes span{font-size:12px;color:#b5c4d8}footer{margin-top:28px;color:#b5c4d8}@media(max-width:850px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){main{padding:18px}.grid{grid-template-columns:1fr}h1{font-size:28px}}
</style><main><div class="label">NAMES & PRICES APPROVED / ARTWORK REVIEW / OFF SALE</div><h1>Choose your collection</h1><p class="intro">Six original cosmetic designs with Solo Leveling-inspired application titles, not an official anime ranking system. These are purchased cosmetics, not earned achievements. Buying one never certifies Python mastery or changes E–S Hunter rank.</p><p class="intro">Approved prices: 10 / 15 / 20 / 30 / 45 / 60 = 180 coins. With 18 topics × 10 first-completion coins, students can buy all six only if they save every completion coin. Rewards and repeat-earning rules stay unchanged.</p><div class="grid">'''+''.join(cards)+'''</div><footer>Names, prices and audited retirement of the four sample badges are user-approved. Review the updated titles with the retained original artwork. Authentic administrator import and sample retirement remain pending; the six new badges stay off sale. This local review page and editable sources are private.</footer></main></html>''',encoding='utf-8')
print('Six private SVG sources, manifest and proposal review page written. PNG export is separate.')
