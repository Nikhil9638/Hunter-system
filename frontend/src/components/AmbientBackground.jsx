import { useEffect, useRef, useState } from 'react';

/**
 * Decorative only: no course data, network requests or input listeners.
 * variant="motes": sparse drifting "mana motes" (spec §1.6) on a night gradient.
 * variant="quiet": static gradient only, no canvas (administrator console).
 * Guards kept from the original: ~30fps cap, paused while the tab is hidden, a single
 * static frame under prefers-reduced-motion, backing store capped at ~1.8M pixels and
 * DPR 1 on mobile, one resize/visibility/motion listener set, CSS fallback without canvas.
 */
export function AmbientBackground({ variant = 'motes' }) {
  if (variant === 'quiet') return <div className="ambient-background ambient-quiet" aria-hidden="true" />;
  return <Motes />;
}

// Deterministic pseudo-random in [0, 1): stable layout, so the reduced-motion frame never changes.
const rand = (n) => { const x = Math.sin(n * 127.1 + 311.7) * 43758.5453; return x - Math.floor(x); };

function Motes() {
  const canvasRef = useRef(null);
  const [fallback, setFallback] = useState(true);
  useEffect(() => {
    const canvas = canvasRef.current;
    let ctx;
    try { ctx = canvas?.getContext('2d'); } catch { /* Static CSS fallback remains. */ }
    if (!ctx) return;
    setFallback(false);
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let frame = 0, last = 0, clock = 0, width = 0, height = 0, motes = [];
    const draw = () => {
      ctx.clearRect(0, 0, width, height);
      for (const m of motes) {
        const twinkle = 0.65 + 0.35 * Math.sin(clock * m.speed * 0.9 + m.phase);
        ctx.globalAlpha = m.alpha * twinkle;
        ctx.fillStyle = m.color;
        ctx.shadowColor = m.color;
        ctx.shadowBlur = 8;
        ctx.beginPath();
        ctx.arc(m.x, m.y, m.r, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      ctx.shadowBlur = 0;
    };
    const stop = () => { cancelAnimationFrame(frame); frame = 0; last = 0; };
    const animate = (time) => {
      if (document.hidden || motion.matches) { stop(); return; }
      if (!last || time - last >= 33) {
        const delta = last ? Math.min(time - last, 80) / 1000 : 0;
        clock += delta;
        for (const m of motes) {
          m.y -= delta * m.speed;
          m.x += delta * m.drift;
          if (m.y < -10) { m.y = height + 10; m.x = rand(m.seed + clock) * width; }
          if (m.x < -10) m.x = width + 10; else if (m.x > width + 10) m.x = -10;
        }
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
      motes = Array.from({ length: mobile ? 14 : 36 }, (_, i) => ({
        seed: i + 1,
        x: rand(i + 1) * width,
        y: rand(i + 101) * height,
        r: 0.7 + rand(i + 201) * 1.1,
        speed: 4 + rand(i + 301) * 7,
        drift: (rand(i + 401) - 0.5) * 3,
        alpha: 0.22 + rand(i + 501) * 0.4,
        phase: rand(i + 601) * Math.PI * 2,
        color: i % 3 === 0 ? '#b69cff' : '#4cc9ff',
      }));
      clock = 0;
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
    {fallback && <div className="ambient-static"><span /><span /><span /><span /></div>}
  </div>;
}
