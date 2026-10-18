// Test-only entry: exposes the real Phase 2 primitives and presentation helpers to
// tests/redesign-primitives.mjs. No API access, no application routes.
import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { RankBadge } from '../src/components/ui.jsx';
import { CharacterArt, ConfirmDialog, HudPanel, ManaAmount, ProgressMeter, RankEmblem } from '../src/components/hud.jsx';
import * as presentation from '../src/config/presentation.js';

let root;
const mount = (node) => { root?.unmount(); document.getElementById('root').innerHTML = ''; root = createRoot(document.getElementById('root')); root.render(node); };

function DialogHarness({ pending }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button type="button" id="opener" onClick={() => setOpen(true)}>Buy</button>
      <ConfirmDialog open={open} title="Confirm purchase" confirmLabel="Spend 10 Mana" pending={pending}
                     onConfirm={() => { window.__confirmed = (window.__confirmed ?? 0) + 1; setOpen(false); }}
                     onCancel={() => { window.__cancelled = (window.__cancelled ?? 0) + 1; setOpen(false); }}>
        <p>Spend 10 Mana on Shadow Recruit?</p>
      </ConfirmDialog>
    </>
  );
}

window.presentation = presentation;
window.show = {
  ranks: (values) => mount(<ul>{values.map((v, i) => <li key={i}><RankBadge rank={v} /></li>)}</ul>),
  themeRank: (code) => mount(<RankBadge rank={code} variant="theme" />),
  mana: (amount) => mount(<ManaAmount amount={amount} />),
  meter: (props) => mount(<ProgressMeter {...props} />),
  panel: () => mount(<HudPanel title="Status" eyebrow="System"><p>Body</p></HudPanel>),
  emblem: (rank, title) => mount(<RankEmblem rank={rank} title={title} />),
  art: (rank) => mount(<CharacterArt rank={rank} />),
  dialog: (pending = false) => mount(<DialogHarness pending={pending} />),
};
