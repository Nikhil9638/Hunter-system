// Test helper: the app stylesheet as ONE string, with local @import rules inlined in order
// (src/styles.css is an @import entry since the Phase 2 split). Used by component-render
// harnesses that inject CSS with page.setContent instead of running Vite.
import { readFileSync } from 'node:fs';
import path from 'node:path';

export function readAppCss(entry = path.resolve('src/styles.css'), seen = new Set()) {
  if (seen.has(entry)) return '';
  seen.add(entry);
  return readFileSync(entry, 'utf8').replace(/@import\s+['"]([^'"]+)['"]\s*;/g,
    (_, rel) => readAppCss(path.resolve(path.dirname(entry), rel), seen));
}
