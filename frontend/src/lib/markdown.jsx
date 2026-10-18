// Lesson bodies are Markdown (api-contract §5). This renderer builds React elements
// for a small, documented subset and NEVER inserts HTML: any HTML in the source is
// shown as literal text (React escapes it). Supported:
//   # / ## / ### headings, paragraphs, "- " / "* " and "1. " lists,
//   ``` fenced code blocks (indentation preserved), `inline code`, **bold**, *italic*.
// Links are shown as their text followed by the address in brackets (not clickable),
// so lesson content can never navigate a student elsewhere.
import { Fragment } from 'react';

function inline(text, keyBase) {
  const out = [];
  const re = /(`[^`]+`)|(\*\*[^*]+\*\*)|(\*[^*\s][^*]*\*)|(\[[^\]]+\]\([^)\s]+\))/g;
  let last = 0;
  let m;
  let i = 0;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const t = m[0];
    const key = `${keyBase}-${i++}`;
    if (m[1]) out.push(<code key={key} className="inline-code">{t.slice(1, -1)}</code>);
    else if (m[2]) out.push(<strong key={key}>{t.slice(2, -2)}</strong>);
    else if (m[3]) out.push(<em key={key}>{t.slice(1, -1)}</em>);
    else {
      const lm = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(t);
      out.push(<Fragment key={key}>{lm[1]} <span className="muted">[{lm[2]}]</span></Fragment>);
    }
    last = m.index + t.length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

export function Markdown({ source }) {
  const lines = String(source ?? '').replace(/\r\n?/g, '\n').split('\n');
  const blocks = [];
  let i = 0;
  let k = 0;
  while (i < lines.length) {
    const line = lines[i];
    const fence = /^```\s*([\w+-]*)\s*$/.exec(line);
    if (fence) {
      const code = [];
      i++;
      while (i < lines.length && !/^```\s*$/.test(lines[i])) code.push(lines[i++]);
      i++;                                                      // closing fence (or end of input)
      blocks.push(<CodeBlock key={k++} code={code.join('\n')} language={fence[1] || null} />);
      continue;
    }
    const h = /^(#{1,3})\s+(.*)$/.exec(line);
    if (h) {
      const Tag = `h${h[1].length + 1}`;                        // the page title is the h1
      blocks.push(<Tag key={k++}>{inline(h[2], `h${k}`)}</Tag>);
      i++;
      continue;
    }
    if (/^\s*([-*])\s+/.test(line) || /^\s*\d+\.\s+/.test(line)) {
      const ordered = /^\s*\d+\.\s+/.test(line);
      const items = [];
      const itemRe = ordered ? /^\s*\d+\.\s+(.*)$/ : /^\s*[-*]\s+(.*)$/;
      while (i < lines.length && itemRe.test(lines[i])) items.push(itemRe.exec(lines[i++])[1]);
      const List = ordered ? 'ol' : 'ul';
      blocks.push(<List key={k++}>{items.map((it, j) => <li key={j}>{inline(it, `l${k}-${j}`)}</li>)}</List>);
      continue;
    }
    if (line.trim() === '') {
      i++;
      continue;
    }
    const para = [];
    while (i < lines.length && lines[i].trim() !== '' && !/^```/.test(lines[i]) && !/^#{1,3}\s/.test(lines[i])
           && !/^\s*([-*]|\d+\.)\s+/.test(lines[i])) para.push(lines[i++]);
    blocks.push(<p key={k++}>{inline(para.join(' '), `p${k}`)}</p>);
  }
  return <div className="prose">{blocks}</div>;
}

/** Python (or any) code as plain text: indentation preserved, never executed. */
export function CodeBlock({ code, language = 'python' }) {
  if (code === null || code === undefined || code === '') return null;
  return (
    <pre className="code-block" tabIndex={0} aria-label={`${language ?? 'code'} example`}>
      <code>{code}</code>
    </pre>
  );
}
