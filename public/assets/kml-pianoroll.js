/* global Midi */
(function () {
  'use strict';

  // ---- smplr piano (SplendidGrandPiano) ----
  let audioCtx = null;
  let piano = null;
  let pianoLoadPromise = null;

  async function ensurePiano() {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }

    // Must be resumed after a user gesture (we call this from the Play button handler)
    if (audioCtx.state !== 'running') {
      await audioCtx.resume();
    }

    if (piano) return piano;
    if (pianoLoadPromise) return pianoLoadPromise;

    pianoLoadPromise = (async () => {
      // Load smplr as an ESM module (browser-friendly)
      const mod = await import('https://unpkg.com/smplr@0.18.1/dist/index.mjs');
      const SplendidGrandPiano = mod && mod.SplendidGrandPiano;

      if (!SplendidGrandPiano) {
        throw new Error('smplr loaded, but SplendidGrandPiano export was not found.');
      }

      // smplr instruments expose a .load promise (see smplr docs)
      const inst = await new SplendidGrandPiano(audioCtx).load;
      return inst;
    })();

    piano = await pianoLoadPromise;
    return piano;
  }

  function clamp(v, a, b) {
    return Math.max(a, Math.min(b, v));
  }

  function fmtTime(sec) {
    if (!isFinite(sec)) return '--:--';
    sec = Math.max(0, sec);
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return m + ':' + String(s).padStart(2, '0');
  }

  async function loadArrayBuffer(url) {
    const r = await fetch(url, { cache: 'no-store' });
    if (!r.ok) throw new Error('Fetch failed: ' + r.status);
    return await r.arrayBuffer();
  }

  function resizeCanvasToDisplaySize(canvas) {
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const w = Math.max(1, Math.floor(rect.width * dpr));
    const h = Math.max(1, Math.floor(rect.height * dpr));
    if (canvas.width !== w || canvas.height !== h) {
      canvas.width = w;
      canvas.height = h;
      return true;
    }
    return false;
  }

  function collectNotes(midi) {
    // Flatten notes across tracks.
    // Each note: time (s), duration (s), midi pitch, velocity (0-1)
    const notes = [];
    midi.tracks.forEach((t) => {
      t.notes.forEach((n) =>
        notes.push({
          time: n.time,
          duration: n.duration,
          midi: n.midi,
          velocity: n.velocity,
        })
      );
    });
    notes.sort((a, b) => a.time - b.time);
    return notes;
  }

  function noteRange(notes) {
    let lo = 127,
      hi = 0;
    for (const n of notes) {
      lo = Math.min(lo, n.midi);
      hi = Math.max(hi, n.midi);
    }
    if (lo > hi) {
      lo = 36;
      hi = 84;
    }
    // add a little padding
    lo = clamp(lo - 2, 0, 127);
    hi = clamp(hi + 2, 0, 127);
    return { lo, hi };
  }

  function initRoll(el) {
    const url = el.getAttribute('data-midi-url');
    if (!url) return;

    const canvas = el.querySelector('.kml-canvas');
    const playBtn = el.querySelector('.kml-play');
    const stopBtn = el.querySelector('.kml-stop');
    const tempo = el.querySelector('.kml-tempo');
    const tempoVal = el.querySelector('.kml-tempo-val');
    const zoom = el.querySelector('.kml-zoom');
    const zoomVal = el.querySelector('.kml-zoom-val');
    const timeEl = el.querySelector('.kml-time');

    if (!canvas || !playBtn || !stopBtn || !tempo || !zoom || !timeEl) return;

    const ctx = canvas.getContext('2d');

    let midi = null;
    let notes = [];
    let durTotal = 0;
    let range = { lo: 36, hi: 84 };

    // Visual params
    let pxPerSec = Number(zoom.value || 90);
    let pitchPadding = 2;

    // Playback state
    let tempoScale = 1.0; // 1.0 = normal
    let isPlaying = false;
    let startPerf = 0; // performance.now() when playback started
    let startAt = 0; // song time offset (seconds) when playback started

    // Track per-note stop functions returned by smplr.start()
    let scheduledStops = [];

    function setStatus(msg) {
      // Keep this subtle; time text will overwrite during draw
      timeEl.textContent = msg;
    }

    function setTempoScale() {
      const pct = Number(tempo.value || 100);
      tempoVal.textContent = pct + '%';
      tempoScale = pct / 100;
    }

    function setZoom() {
      pxPerSec = Number(zoom.value || 90);
      zoomVal.textContent = String(pxPerSec);
    }

    function currentT() {
      if (!isPlaying) return startAt;
      const elapsed = (performance.now() - startPerf) / 1000;
      const t = startAt + elapsed * tempoScale;
      return clamp(t, 0, durTotal);
    }

    function cancelScheduled() {
      // Stop everything currently ringing
      try {
        if (piano) piano.stop();
      } catch (e) {}

      // Stop any notes that returned per-note stop fns
      for (const stopFn of scheduledStops) {
        try {
          // Some versions accept options; some accept nothing. Try both safely.
          stopFn({ time: audioCtx ? audioCtx.currentTime : undefined });
        } catch (e1) {
          try {
            stopFn();
          } catch (e2) {}
        }
      }
      scheduledStops = [];
    }

    async function play() {
      if (!midi) return;

      setTempoScale();

      setStatus('Loading piano...');
      const inst = await ensurePiano();

      // Cancel anything from a prior run/pause
      cancelScheduled();

      // Schedule relative to AudioContext time
      const base = audioCtx.currentTime + 0.06;

      // Schedule notes
      for (const n of notes) {
        const end = n.time + n.duration;
        if (end <= startAt) continue;

        const when = base + (n.time - startAt) / tempoScale;
        const dur = Math.max(0.02, n.duration / tempoScale);

        // smplr velocity expects 0..127 (docs)
        const vel01 = typeof n.velocity === 'number' ? n.velocity : 0.8;
        const vel = Math.max(1, Math.min(127, Math.round(clamp(vel01, 0, 1) * 127)));

        const stopFn = inst.start({
          note: n.midi, // MIDI note numbers are supported by smplr
          velocity: vel,
          time: when, // AudioContext time seconds
          duration: dur,
        });

        if (typeof stopFn === 'function') scheduledStops.push(stopFn);
      }

      isPlaying = true;
      startPerf = performance.now();
    }

    function pause() {
      const t = currentT();
      cancelScheduled();
      isPlaying = false;
      startAt = t;
    }

    function stop() {
      cancelScheduled();
      isPlaying = false;
      startAt = 0;
    }

    // Smooth drawing loop (independent of audio scheduling)
    function draw() {
      resizeCanvasToDisplaySize(canvas);
      const w = canvas.width;
      const h = canvas.height;

      ctx.clearRect(0, 0, w, h);

      const pitchLo = range.lo;
      const pitchHi = range.hi;
      const pitchCount = pitchHi - pitchLo + 1;
      const laneH = h / pitchCount;

      const tNow = currentT();
      const viewLeftTime = Math.max(0, tNow - w / (2 * pxPerSec));
      const viewRightTime = viewLeftTime + w / pxPerSec;

      ctx.lineWidth = 1;

      // Vertical time grid
      ctx.strokeStyle = 'rgba(0,0,0,0.06)';
      const secStart = Math.floor(viewLeftTime);
      const secEnd = Math.ceil(viewRightTime);
      for (let s = secStart; s <= secEnd; s++) {
        const x = (s - viewLeftTime) * pxPerSec;
        ctx.beginPath();
        ctx.moveTo(x, 0);
        ctx.lineTo(x, h);
        ctx.stroke();
      }

      // Horizontal pitch grid (octaves slightly stronger)
      for (let p = pitchLo; p <= pitchHi; p++) {
        const y = (pitchHi - p) * laneH;
        const isOct = p % 12 === 0;
        ctx.strokeStyle = isOct ? 'rgba(0,0,0,0.08)' : 'rgba(0,0,0,0.04)';
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(w, y);
        ctx.stroke();
      }

      // Draw notes in view
      for (const n of notes) {
        const nStart = n.time;
        const nEnd = n.time + n.duration;

        if (nEnd < viewLeftTime) continue;
        if (nStart > viewRightTime) break;

        const x = (nStart - viewLeftTime) * pxPerSec;
        const width = Math.max(1, n.duration * pxPerSec);

        const y = (pitchHi - n.midi) * laneH;
        const nh = Math.max(1, laneH * 0.85);

        const a = 0.25 + 0.70 * clamp(n.velocity || 0.5, 0, 1);
        ctx.fillStyle = 'rgba(20,40,55,' + a.toFixed(3) + ')';
        ctx.fillRect(x, y + laneH * 0.08, width, nh);
      }

      // Playhead
      const playX = (tNow - viewLeftTime) * pxPerSec;
      ctx.strokeStyle = 'rgba(220, 0, 60, 0.9)';
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(playX, 0);
      ctx.lineTo(playX, h);
      ctx.stroke();

      // Time label
      timeEl.textContent = fmtTime(tNow) + ' / ' + fmtTime(durTotal);

      // Auto-finish when reaching end
      if (isPlaying && tNow >= durTotal - 0.01) {
        stop();
        playBtn.textContent = 'Play';
      }

      requestAnimationFrame(draw);
    }

    // ---- UI wiring ----
    playBtn.addEventListener('click', async () => {
      if (!midi) return;

      if (isPlaying) {
        pause();
        playBtn.textContent = 'Play';
        return;
      }

      try {
        playBtn.disabled = true;
        playBtn.textContent = 'Loading...';
        await play();
        playBtn.textContent = 'Pause';
      } catch (e) {
        console.error('KML piano roll failed during play:', e);
        setStatus('Play failed');
        playBtn.textContent = 'Play';
      } finally {
        playBtn.disabled = false;
      }
    });

    stopBtn.addEventListener('click', () => {
      stop();
      playBtn.textContent = 'Play';
    });

    // Debounce tempo changes so we do not restart 60 times/sec while dragging
    let tempoDebounce = null;
    tempo.addEventListener('input', () => {
      setTempoScale();
      if (!isPlaying) return;

      const t = currentT();
      pause();
      startAt = t;

      if (tempoDebounce) clearTimeout(tempoDebounce);
      tempoDebounce = setTimeout(async () => {
        try {
          playBtn.disabled = true;
          await play();
          playBtn.textContent = 'Pause';
        } catch (e) {
          console.error('KML tempo restart failed:', e);
          stop();
          playBtn.textContent = 'Play';
        } finally {
          playBtn.disabled = false;
        }
      }, 120);
    });

    zoom.addEventListener('input', () => setZoom());

    // ---- Load MIDI and start drawing ----
    (async () => {
      try {
        playBtn.disabled = true;
        playBtn.textContent = 'Loading...';
        setZoom();
        setTempoScale();

        const buf = await loadArrayBuffer(url);
        midi = new Midi(buf);
        notes = collectNotes(midi);

        durTotal =
          midi.duration ||
          (notes.length ? notes[notes.length - 1].time + notes[notes.length - 1].duration : 0);

        range = noteRange(notes);

        // Tighten range slightly
        range.lo = clamp(range.lo + pitchPadding, 0, 127);
        range.hi = clamp(range.hi - pitchPadding, 0, 127);

        playBtn.disabled = false;
        playBtn.textContent = 'Play';

        requestAnimationFrame(draw);
      } catch (e) {
        console.error('KML piano roll failed:', e);
        setStatus('Preview unavailable');
        playBtn.disabled = true;
      }
    })();
  }

  function init() {
    document.querySelectorAll('.kml-roll[data-midi-url]').forEach(initRoll);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
