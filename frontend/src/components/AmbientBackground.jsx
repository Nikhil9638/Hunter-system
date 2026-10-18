import { useEffect, useRef, useState } from 'react';

/** Decorative only: no course data, network requests or input listeners. */
export function AmbientBackground() {
  const canvasRef = useRef(null);
  const [fallback, setFallback] = useState(true);
  useEffect(() => {
    const canvas = canvasRef.current;
    let ctx;
    try { ctx = canvas?.getContext('2d'); } catch { /* Static CSS fallback remains. */ }
    if (!ctx) return;
    setFallback(false);
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let frame = 0, last = 0, width = 0, height = 0, marks = [];
    const snippets = ['def learn():', 'print("hello")', 'for item in topics:', 'return', 'class Hunter:', 'import math'];
    const draw = () => {
      ctx.clearRect(0, 0, width, height);
      ctx.font = '14px Consolas, monospace';
      marks.forEach((mark, i) => {
        ctx.fillStyle = i % 2 ? 'rgba(255,212,59,.075)' : 'rgba(56,126,184,.12)';
        ctx.fillText(snippets[i], mark.x, mark.y);
      });
    };
    const stop = () => { cancelAnimationFrame(frame); frame = 0; last = 0; };
    const animate = (time) => {
      if (document.hidden || motion.matches) { stop(); return; }
      if (!last || time - last >= 33) {
        const delta = last ? Math.min(time - last, 80) / 1000 : 0;
        marks.forEach(mark => { mark.y -= delta * 3; if (mark.y < -20) mark.y = height + 20; });
        draw(); last = time;
      }
      frame = requestAnimationFrame(animate);
    };
    const resume = () => { stop(); draw(); if (!document.hidden && !motion.matches) frame = requestAnimationFrame(animate); };
    const resize = () => {
      width = window.innerWidth; height = window.innerHeight;
      const mobile = width <= 760;
      const ratio = Math.min(window.devicePixelRatio || 1, mobile ? 1 : 1.5, Math.sqrt(1800000 / Math.max(1, width * height)));
      canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      marks = Array.from({ length: mobile ? 3 : 6 }, (_, i) => ({ x: width * ((i * .19 + .09) % .86), y: height * ((i * .27 + .17) % .94) }));
      resume();
    };
    resize();
    window.addEventListener('resize', resize);
    document.addEventListener('visibilitychange', resume);
    motion.addEventListener('change', resume);
    return () => { stop(); window.removeEventListener('resize', resize); document.removeEventListener('visibilitychange', resume); motion.removeEventListener('change', resume); };
  }, []);
  return <div className="ambient-background" aria-hidden="true">
    <canvas ref={canvasRef} className="ambient-canvas" />
    {fallback && <div className="ambient-static"><span>def learn():</span><span>print("hello")</span><span>return</span></div>}
  </div>;
}
