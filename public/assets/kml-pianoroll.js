/* global Midi, Tone */
(function () {
  'use strict';

  // ---- Tone.js piano sampler ----
  const PIANO_LOW_MIDI = 21; // A0
  const PIANO_HIGH_MIDI = 108; // C8
  const PIANO_KEY_COUNT = PIANO_HIGH_MIDI - PIANO_LOW_MIDI + 1;
  const PEDAL_ROW_WEIGHT = 3;
  const SUSTAIN_CC = 64;
  const PEDAL_ON_THRESHOLD = 0.5;
  const PIANO_NOTE_ATTACK = 0.012;
  const PIANO_NOTE_RELEASE = 0.45;
  const PIANO_OUTPUT_VOLUME_DB = -7;
  const PIANO_MASTER_GAIN = 0.72;
  const PIANO_VOLUME_DEFAULT = 100;
  const PIANO_LOWPASS_CUTOFF_HZ = 12000;
  const PIANO_SAMPLE_BASE_URL = 'https://tambien.github.io/Piano/audio/';
  const PIANO_SAMPLE_VELOCITY = 8;
  const AUDIO_RENDER_CHANNELS = 2;
  const AUDIO_RENDER_SAMPLE_RATE = 44100;
  const AUDIO_RENDER_TAIL_SECONDS = 1.2;
  const NOTE_SCHEDULE_LOOKAHEAD = 0.1;
  const NOTE_SCHEDULE_INTERVAL_MS = 25;
  const PIANO_SAMPLE_ROOTS = [
    21, 24, 27, 30, 33, 36, 39, 42, 45, 48, 51, 54, 57, 60, 63, 66, 69, 72, 75, 78, 81, 84,
    87, 90, 93, 96, 99, 102, 105, 108,
  ];
  const PEDAL_LANES = [
    { cc: 67, label: 'Soft' },
    { cc: 66, label: 'Sostenuto' },
    { cc: SUSTAIN_CC, label: 'Sustain' },
  ];

  let tonePiano = null;
  let tonePianoPromise = null;

  function midiToSampleNote(midi) {
    const names = ['C', 'Cs', 'D', 'Ds', 'E', 'F', 'Fs', 'G', 'Gs', 'A', 'As', 'B'];
    return names[midi % 12] + String(Math.floor(midi / 12) - 1);
  }

  function midiToToneNote(midi) {
    const names = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    return names[midi % 12] + String(Math.floor(midi / 12) - 1);
  }

  function buildToneSamplerUrls() {
    const urls = {};

    PIANO_SAMPLE_ROOTS.forEach((midi) => {
      urls[midi] = midiToSampleNote(midi) + 'v' + PIANO_SAMPLE_VELOCITY + '.[mp3|ogg]';
    });

    return urls;
  }

  async function ensureTonePiano(opts) {
    const options = opts || {};

    if (!window.Tone || typeof Tone.Sampler !== 'function') {
      throw new Error('Tone.js was not loaded.');
    }

    if (options.resumeCtx !== false && typeof Tone.start === 'function') {
      await Tone.start();
    }

    if (tonePiano) return tonePiano;
    if (tonePianoPromise) return await tonePianoPromise;

    tonePianoPromise = new Promise((resolve, reject) => {
      let piano = null;
      const sampler = new Tone.Sampler({
        attack: PIANO_NOTE_ATTACK,
        baseUrl: PIANO_SAMPLE_BASE_URL,
        curve: 'exponential',
        onerror: reject,
        onload: () => {
          tonePiano = piano;
          resolve(piano);
        },
        release: PIANO_NOTE_RELEASE,
        urls: buildToneSamplerUrls(),
        volume: PIANO_OUTPUT_VOLUME_DB,
      });
      const filter = new Tone.Filter({
        frequency: PIANO_LOWPASS_CUTOFF_HZ,
        rolloff: -12,
        type: 'lowpass',
      });
      const compressor = new Tone.Compressor({
        attack: 0.03,
        knee: 18,
        ratio: 3,
        release: 0.25,
        threshold: -10,
      });
      const masterGain = new Tone.Gain(PIANO_MASTER_GAIN);

      piano = { sampler, filter, compressor, masterGain };
      sampler.chain(filter, compressor, masterGain, Tone.Destination);
    }).catch((e) => {
      tonePianoPromise = null;
      throw e;
    });

    return await tonePianoPromise;
  }

  function shouldWarmPiano() {
    const c = navigator.connection;
    if (!c) return true;
    if (c.saveData) return false;
    return c.effectiveType !== 'slow-2g' && c.effectiveType !== '2g';
  }

  function warmPianoSoon() {
    if (!shouldWarmPiano()) return;
    const warm = () => {
      ensureTonePiano({ resumeCtx: false }).catch(() => {});
    };

    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(warm, { timeout: 1800 });
    } else {
      window.setTimeout(warm, 600);
    }
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
    const r = await fetch(url);
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

  function noteEndTime(notes, durationKey) {
    const key = durationKey || 'duration';
    let end = 0;
    for (const n of notes) {
      end = Math.max(end, n.time + (n[key] || n.duration || 0));
    }
    return end;
  }

  function collectPedalEvents(midi) {
    return PEDAL_LANES.map((lane) => {
      const tracks = [];

      midi.tracks.forEach((t) => {
        const changes = t.controlChanges && t.controlChanges[lane.cc];
        if (!Array.isArray(changes) || !changes.length) return;

        const events = changes
          .filter((cc) => cc && Number.isFinite(cc.time) && Number.isFinite(cc.value))
          .map((cc) => ({
            time: cc.time,
            value: cc.value,
          }))
          .sort((a, b) => a.time - b.time);

        if (events.length) tracks.push(events);
      });

      return Object.assign({}, lane, { tracks });
    });
  }

  function lastPedalEventTime(pedalEvents) {
    let end = 0;

    pedalEvents.forEach((lane) => {
      lane.tracks.forEach((events) => {
        events.forEach((event) => {
          end = Math.max(end, event.time);
        });
      });
    });

    return end;
  }

  function mergeSegments(segments) {
    const merged = [];
    const sorted = segments
      .filter((segment) => segment.duration > 0)
      .sort((a, b) => a.time - b.time);

    sorted.forEach((segment) => {
      const last = merged[merged.length - 1];
      const end = segment.time + segment.duration;

      if (last && segment.time <= last.time + last.duration + 0.001) {
        last.duration = Math.max(last.time + last.duration, end) - last.time;
      } else {
        merged.push({
          time: segment.time,
          duration: segment.duration,
        });
      }
    });

    return merged;
  }

  function buildPedalSegments(pedalEvents, duration) {
    return pedalEvents.map((lane) => {
      const segments = [];

      lane.tracks.forEach((events) => {
        let activeStart = null;

        events.forEach((event) => {
          const time = clamp(event.time, 0, duration);
          const isPressed = event.value >= PEDAL_ON_THRESHOLD;

          if (isPressed && activeStart === null) {
            activeStart = time;
          } else if (!isPressed && activeStart !== null) {
            if (time > activeStart) {
              segments.push({
                time: activeStart,
                duration: time - activeStart,
              });
            }
            activeStart = null;
          }
        });

        if (activeStart !== null && duration > activeStart) {
          segments.push({
            time: activeStart,
            duration: duration - activeStart,
          });
        }
      });

      return Object.assign({}, lane, {
        segments: mergeSegments(segments),
      });
    });
  }

  function isBlackKey(midi) {
    const pitchClass = midi % 12;
    return (
      pitchClass === 1 ||
      pitchClass === 3 ||
      pitchClass === 6 ||
      pitchClass === 8 ||
      pitchClass === 10
    );
  }

  function getPedalSegments(pedalLanes, cc) {
    const lane = pedalLanes.find((item) => item.cc === cc);
    return lane ? lane.segments : [];
  }

  function segmentEnd(segment) {
    return segment.time + segment.duration;
  }

  function sustainedEndForNote(noteEnd, sustainSegments) {
    for (const segment of sustainSegments) {
      if (noteEnd < segment.time) break;
      if (noteEnd >= segment.time && noteEnd < segmentEnd(segment)) {
        return segmentEnd(segment);
      }
    }
    return noteEnd;
  }

  function buildPlaybackNotes(notes, sustainSegments) {
    const nextStartByPitch = new Map();
    const playbackNotes = new Array(notes.length);

    for (let i = notes.length - 1; i >= 0; i--) {
      const n = notes[i];
      const noteEnd = n.time + n.duration;
      const sustainedEnd = sustainedEndForNote(noteEnd, sustainSegments);
      const nextStart = nextStartByPitch.get(n.midi);
      let playbackEnd = sustainedEnd;

      if (Number.isFinite(nextStart) && nextStart > n.time) {
        playbackEnd = Math.min(playbackEnd, Math.max(n.time + 0.03, nextStart));
      }

      playbackNotes[i] = Object.assign({}, n, {
        playbackDuration: Math.max(0.02, playbackEnd - n.time),
      });

      nextStartByPitch.set(n.midi, n.time);
    }

    return playbackNotes;
  }

  function toneVelocity(note) {
    return typeof note.velocity === 'number' ? clamp(note.velocity, 0, 1) : 0.8;
  }

  function audioBufferFromToneBuffer(buffer) {
    if (buffer && typeof buffer.getChannelData === 'function') return buffer;
    if (buffer && typeof buffer.get === 'function') return buffer.get();
    if (buffer && buffer._buffer && typeof buffer._buffer.getChannelData === 'function') {
      return buffer._buffer;
    }
    throw new Error('Rendered audio buffer is unavailable.');
  }

  function writeAscii(view, offset, text) {
    for (let i = 0; i < text.length; i++) {
      view.setUint8(offset + i, text.charCodeAt(i));
    }
  }

  function yieldToBrowser() {
    return new Promise((resolve) => {
      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(() => resolve());
      } else {
        window.setTimeout(resolve, 0);
      }
    });
  }

  function makeAbortError() {
    if (typeof DOMException === 'function') {
      return new DOMException('Render canceled', 'AbortError');
    }

    const error = new Error('Render canceled');
    error.name = 'AbortError';
    return error;
  }

  function throwIfAborted(signal) {
    if (signal && signal.aborted) {
      throw makeAbortError();
    }
  }

  function isAbortError(error) {
    return error && error.name === 'AbortError';
  }

  async function encodeWav(audioBuffer, onProgress, signal) {
    throwIfAborted(signal);

    const channelCount = Math.max(1, Math.min(AUDIO_RENDER_CHANNELS, audioBuffer.numberOfChannels || 1));
    const length = audioBuffer.length;
    const sampleRate = audioBuffer.sampleRate;
    const bytesPerSample = 2;
    const blockAlign = channelCount * bytesPerSample;
    const dataSize = length * blockAlign;
    const wav = new ArrayBuffer(44 + dataSize);
    const view = new DataView(wav);
    const channels = [];

    for (let channel = 0; channel < channelCount; channel++) {
      channels.push(audioBuffer.getChannelData(Math.min(channel, audioBuffer.numberOfChannels - 1)));
    }

    writeAscii(view, 0, 'RIFF');
    view.setUint32(4, 36 + dataSize, true);
    writeAscii(view, 8, 'WAVE');
    writeAscii(view, 12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, channelCount, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * blockAlign, true);
    view.setUint16(32, blockAlign, true);
    view.setUint16(34, bytesPerSample * 8, true);
    writeAscii(view, 36, 'data');
    view.setUint32(40, dataSize, true);

    let offset = 44;
    const chunkFrames = 16384;

    for (let i = 0; i < length; i++) {
      throwIfAborted(signal);

      for (let channel = 0; channel < channelCount; channel++) {
        const value = Number.isFinite(channels[channel][i]) ? channels[channel][i] : 0;
        const sample = clamp(value, -1, 1);
        const intSample = sample < 0 ? sample * 0x8000 : sample * 0x7fff;
        view.setInt16(offset, intSample, true);
        offset += bytesPerSample;
      }

      if (i > 0 && i % chunkFrames === 0) {
        if (typeof onProgress === 'function') onProgress(i / length);
        await yieldToBrowser();
      }
    }

    throwIfAborted(signal);
    if (typeof onProgress === 'function') onProgress(1);
    return new Blob([view], { type: 'audio/wav' });
  }

  function basenameFromUrl(url) {
    try {
      const parsed = new URL(url, window.location.href);
      const parts = parsed.pathname.split('/').filter(Boolean);
      return decodeURIComponent(parts[parts.length - 1] || '');
    } catch (e) {
      const clean = String(url || '').split(/[?#]/)[0];
      const parts = clean.split('/').filter(Boolean);
      try {
        return decodeURIComponent(parts[parts.length - 1] || '');
      } catch (e1) {
        return parts[parts.length - 1] || '';
      }
    }
  }

  function titleFallbackName(el) {
    const containers = [el.closest('.kml-player'), el.closest('article'), document];

    for (const container of containers) {
      if (!container) continue;
      const title = container.querySelector('.kml-player-title, .entry-title, h1, h2');
      if (title && title.textContent.trim()) return title.textContent.trim();
    }

    return '';
  }

  function audioDownloadFilename(url, el) {
    const sourceName = basenameFromUrl(url) || titleFallbackName(el) || 'midi-render';
    const baseName = sourceName
      .replace(/^MIDI Player:\s*/i, '')
      .replace(/\.(midi?|kar)$/i, '')
      .replace(/[\\/:*?"<>|]+/g, '-')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 180);

    return (baseName || 'midi-render') + '.wav';
  }

  async function renderPianoAudio(renderNotes, renderDuration, renderTempoScale, renderVolumeScale, signal) {
    if (!window.Tone || typeof Tone.Offline !== 'function') {
      throw new Error('Tone.js offline rendering is unavailable.');
    }

    throwIfAborted(signal);

    const safeTempoScale = Math.max(0.05, renderTempoScale || 1);
    const safeVolumeScale = Math.max(0, renderVolumeScale || 0);
    const offlineDuration = Math.max(
      0.25,
      renderDuration / safeTempoScale + AUDIO_RENDER_TAIL_SECONDS
    );

    const renderedBuffer = await Tone.Offline(
      async () => {
        let sampler = null;
        let filter = null;
        let compressor = null;
        let masterGain = null;

        const disposeOfflineNodes = () => {
          [sampler, filter, compressor, masterGain].forEach((node) => {
            try {
              if (node && typeof node.dispose === 'function') node.dispose();
            } catch (e) {}
          });
        };

        await new Promise((resolve, reject) => {
          let settled = false;
          const cleanup = () => {
            if (signal) signal.removeEventListener('abort', abort);
          };
          const finish = (fn, value) => {
            if (settled) return;
            settled = true;
            cleanup();
            fn(value);
          };
          const abort = () => {
            disposeOfflineNodes();
            finish(reject, makeAbortError());
          };

          if (signal) {
            if (signal.aborted) {
              abort();
              return;
            }
            signal.addEventListener('abort', abort, { once: true });
          }

          sampler = new Tone.Sampler({
            attack: PIANO_NOTE_ATTACK,
            baseUrl: PIANO_SAMPLE_BASE_URL,
            curve: 'exponential',
            onerror: (error) => finish(reject, error),
            onload: () => finish(resolve),
            release: PIANO_NOTE_RELEASE,
            urls: buildToneSamplerUrls(),
            volume: PIANO_OUTPUT_VOLUME_DB,
          });

          filter = new Tone.Filter({
            frequency: PIANO_LOWPASS_CUTOFF_HZ,
            rolloff: -12,
            type: 'lowpass',
          });
          compressor = new Tone.Compressor({
            attack: 0.03,
            knee: 18,
            ratio: 3,
            release: 0.25,
            threshold: -10,
          });
          masterGain = new Tone.Gain(PIANO_MASTER_GAIN * safeVolumeScale);

          sampler.chain(filter, compressor, masterGain);
          masterGain.toDestination();
        });

        throwIfAborted(signal);

        for (const note of renderNotes) {
          throwIfAborted(signal);
          if (!Number.isFinite(note.midi) || note.midi < 0 || note.midi > 127) continue;

          const start = note.time / safeTempoScale;
          const duration = Math.max(0.03, (note.playbackDuration || note.duration || 0.03) / safeTempoScale);
          if (start > offlineDuration) continue;

          sampler.triggerAttackRelease(
            midiToToneNote(note.midi),
            duration,
            start,
            toneVelocity(note)
          );
        }
      },
      offlineDuration,
      AUDIO_RENDER_CHANNELS,
      AUDIO_RENDER_SAMPLE_RATE
    );

    throwIfAborted(signal);

    return audioBufferFromToneBuffer(renderedBuffer);
  }

  function drawTimedRect(ctx, start, end, viewLeftTime, viewRightTime, pxPerSec, y, height, fillStyle) {
    if (end < viewLeftTime || start > viewRightTime) return;

    const clippedStart = Math.max(start, viewLeftTime);
    const clippedEnd = Math.min(end, viewRightTime);
    const x = (clippedStart - viewLeftTime) * pxPerSec;
    const width = Math.max(1, (clippedEnd - clippedStart) * pxPerSec);

    ctx.fillStyle = fillStyle;
    ctx.fillRect(x, y, width, height);
  }

  function initRoll(el) {
    const url = el.getAttribute('data-midi-url');
    if (!url) return;

    const canvas = el.querySelector('.kml-canvas');
    const playBtn = el.querySelector('.kml-play');
    const stopBtn = el.querySelector('.kml-stop');
    const tempo = el.querySelector('.kml-tempo');
    const tempoVal = el.querySelector('.kml-tempo-val');
    const volume = el.querySelector('.kml-volume');
    const volumeVal = el.querySelector('.kml-volume-val');
    const zoom = el.querySelector('.kml-zoom');
    const zoomVal = el.querySelector('.kml-zoom-val');
    const timeEl = el.querySelector('.kml-time');

    if (!canvas || !playBtn || !stopBtn || !tempo || !zoom || !timeEl) return;

    const renderControls = document.createElement('div');
    const renderBtn = document.createElement('button');
    const downloadAudio = document.createElement('a');
    const renderStatus = document.createElement('span');
    const renderProgress = document.createElement('div');
    const renderProgressFill = document.createElement('span');

    renderControls.className = 'kml-render-controls';

    renderBtn.type = 'button';
    renderBtn.className = 'kml-btn kml-render-audio';
    renderBtn.textContent = 'Render WAV';
    renderBtn.disabled = true;

    downloadAudio.className = 'kml-btn kml-download-audio';
    downloadAudio.hidden = true;
    downloadAudio.rel = 'nofollow';
    downloadAudio.textContent = 'Download WAV';
    downloadAudio.download = audioDownloadFilename(url, el);

    renderStatus.className = 'kml-render-status';
    renderStatus.setAttribute('aria-live', 'polite');

    renderProgress.className = 'kml-render-progress';
    renderProgress.hidden = true;
    renderProgress.setAttribute('role', 'progressbar');
    renderProgress.setAttribute('aria-valuemin', '0');
    renderProgress.setAttribute('aria-valuemax', '100');
    renderProgress.setAttribute('aria-valuenow', '0');
    renderProgress.appendChild(renderProgressFill);

    renderControls.appendChild(renderBtn);
    renderControls.appendChild(downloadAudio);
    renderControls.appendChild(renderStatus);
    renderControls.appendChild(renderProgress);
    canvas.insertAdjacentElement('afterend', renderControls);

    const ctx = canvas.getContext('2d');

    let midi = null;
    let notes = [];
    let playbackNotes = [];
    let pedalLanes = PEDAL_LANES.map((lane) => Object.assign({}, lane, { segments: [] }));
    let durTotal = 0;
    const range = { lo: PIANO_LOW_MIDI, hi: PIANO_HIGH_MIDI };

    // Visual params
    let pxPerSec = Number(zoom.value || 90);
    let volumeScale = PIANO_VOLUME_DEFAULT / 100;

    // Playback state
    let tempoScale = 1.0; // 1.0 = normal
    let isPlaying = false;
    let startPerf = 0; // performance.now() when playback started
    let startAt = 0; // song time offset (seconds) when playback started
    let activePiano = null;
    let scheduleTimer = null;
    let nextNoteIndex = 0;
    let renderedAudioUrl = '';
    let renderProgressTimer = null;
    let activeRender = null;

    function setStatus(msg) {
      // Keep this subtle; time text will overwrite during draw
      timeEl.textContent = msg;
    }

    function setRenderStatus(msg) {
      renderStatus.textContent = msg || '';
    }

    function stopRenderProgressTimer() {
      if (renderProgressTimer) {
        window.clearInterval(renderProgressTimer);
        renderProgressTimer = null;
      }
    }

    function setRenderProgress(value, status) {
      const pct = clamp(value, 0, 1);

      renderProgress.hidden = false;
      renderProgressFill.style.width = (pct * 100).toFixed(1) + '%';
      renderProgress.setAttribute('aria-valuenow', String(Math.round(pct * 100)));
      if (status !== undefined) setRenderStatus(status);
    }

    function hideRenderProgress() {
      stopRenderProgressTimer();
      renderProgress.hidden = true;
      renderProgressFill.style.width = '0%';
      renderProgress.setAttribute('aria-valuenow', '0');
    }

    function startEstimatedRenderProgress(start, end, estimatedSeconds, status) {
      stopRenderProgressTimer();
      setRenderProgress(start, status);

      const startMs = performance.now();
      const span = Math.max(0, end - start);
      const estimateMs = Math.max(1200, estimatedSeconds * 1000);

      renderProgressTimer = window.setInterval(() => {
        const elapsed = performance.now() - startMs;
        const eased = 1 - Math.exp(-elapsed / estimateMs);
        const next = start + span * eased;
        setRenderProgress(Math.min(end - 0.01, next), status);
      }, 250);
    }

    function clearRenderedAudio(status) {
      if (status === '') hideRenderProgress();

      if (renderedAudioUrl) {
        URL.revokeObjectURL(renderedAudioUrl);
        renderedAudioUrl = '';
      }

      downloadAudio.hidden = true;
      downloadAudio.removeAttribute('href');
      if (status !== undefined) setRenderStatus(status);
    }

    function canRenderAudio() {
      return !!midi && playbackNotes.length > 0;
    }

    function setRenderControlLock(locked) {
      playBtn.disabled = locked || !midi;
      stopBtn.disabled = locked || !midi;
      tempo.disabled = locked || !midi;
      zoom.disabled = locked || !midi;
      if (volume) volume.disabled = locked || !midi;
      renderBtn.disabled = !canRenderAudio();
    }

    function setRenderButtonMode(mode) {
      if (mode === 'cancel') {
        renderBtn.textContent = 'Cancel';
        renderBtn.classList.add('is-cancel');
        renderBtn.setAttribute('aria-label', 'Cancel WAV render');
      } else {
        renderBtn.textContent = 'Render WAV';
        renderBtn.classList.remove('is-cancel');
        renderBtn.removeAttribute('aria-label');
      }
    }

    function createRenderState() {
      const controller = typeof AbortController === 'function' ? new AbortController() : null;
      return {
        canceled: false,
        controller,
        signal: controller ? controller.signal : null,
      };
    }

    function cancelActiveRender(status) {
      if (!activeRender) return false;

      activeRender.canceled = true;
      if (activeRender.controller) activeRender.controller.abort();
      activeRender = null;
      stopRenderProgressTimer();
      hideRenderProgress();
      clearRenderedAudio(status || 'Render canceled');
      setRenderControlLock(false);
      setRenderButtonMode('render');
      return true;
    }

    function ensureCurrentRender(renderState) {
      if (!renderState || renderState.canceled || activeRender !== renderState) {
        throw makeAbortError();
      }

      throwIfAborted(renderState.signal);
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

    function applyPianoVolume() {
      if (!activePiano || !activePiano.masterGain || !activePiano.masterGain.gain) return;

      const target = PIANO_MASTER_GAIN * volumeScale;
      const gain = activePiano.masterGain.gain;

      try {
        if (typeof gain.rampTo === 'function') {
          gain.rampTo(target, 0.03);
        } else {
          gain.value = target;
        }
      } catch (e) {
        try {
          gain.value = target;
        } catch (e1) {}
      }
    }

    function setVolume() {
      if (!volume) {
        volumeScale = PIANO_VOLUME_DEFAULT / 100;
        applyPianoVolume();
        return;
      }

      const pct = clamp(Number(volume.value || PIANO_VOLUME_DEFAULT), 0, 200);
      volumeScale = pct / 100;
      if (volumeVal) volumeVal.textContent = Math.round(pct) + '%';
      applyPianoVolume();
    }

    function currentT() {
      if (!isPlaying) return startAt;
      const elapsed = (performance.now() - startPerf) / 1000;
      const t = startAt + elapsed * tempoScale;
      return clamp(t, 0, durTotal);
    }

    function cancelScheduled() {
      if (scheduleTimer) {
        window.clearInterval(scheduleTimer);
        scheduleTimer = null;
      }

      nextNoteIndex = 0;

      try {
        if (
          activePiano &&
          activePiano.sampler &&
          typeof activePiano.sampler.releaseAll === 'function'
        ) {
          activePiano.sampler.releaseAll(Tone.now());
        }
      } catch (e) {}
    }

    function resetScheduleIndex() {
      nextNoteIndex = 0;

      while (nextNoteIndex < playbackNotes.length) {
        const n = playbackNotes[nextNoteIndex];
        if (n.time + n.duration > startAt) break;
        nextNoteIndex++;
      }
    }

    function scheduleToneNote(n, tNow, toneNow) {
      if (!activePiano || !activePiano.sampler) return;
      if (!Number.isFinite(n.midi) || n.midi < 0 || n.midi > 127) return;

      const end = n.time + n.playbackDuration;
      const struckEnd = n.time + n.duration;
      if (end <= tNow || struckEnd <= tNow) return;

      const noteName = midiToToneNote(n.midi);
      const audibleStart = Math.max(n.time, tNow);
      const when = toneNow + Math.max(0, (audibleStart - tNow) / tempoScale);
      const releaseAt = toneNow + Math.max(0.03, (end - tNow) / tempoScale);

      activePiano.sampler.triggerAttack(noteName, when, toneVelocity(n));
      activePiano.sampler.triggerRelease(noteName, releaseAt);
    }

    function scheduleToneWindow() {
      if (!isPlaying || !activePiano) return;

      const tNow = currentT();
      const toneNow = Tone.now();
      const windowEnd = Math.min(durTotal, tNow + NOTE_SCHEDULE_LOOKAHEAD * tempoScale);

      while (nextNoteIndex < playbackNotes.length) {
        const n = playbackNotes[nextNoteIndex];
        if (n.time > windowEnd) break;

        scheduleToneNote(n, tNow, toneNow);
        nextNoteIndex++;
      }
    }

    async function play() {
      if (!midi) return;

      setTempoScale();

      setStatus('Loading piano...');
      const piano = await ensureTonePiano({ resumeCtx: true });

      // Cancel anything from a prior run/pause
      cancelScheduled();

      activePiano = piano;
      applyPianoVolume();
      isPlaying = true;
      startPerf = performance.now();
      resetScheduleIndex();
      scheduleToneWindow();
      scheduleTimer = window.setInterval(scheduleToneWindow, NOTE_SCHEDULE_INTERVAL_MS);
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
      const noteUnitH = h / (PIANO_KEY_COUNT + PEDAL_LANES.length * PEDAL_ROW_WEIGHT);
      const laneH = noteUnitH;
      const noteAreaH = laneH * pitchCount;
      const pedalLaneH = laneH * PEDAL_ROW_WEIGHT;
      const pedalTop = noteAreaH;
      const dpr = window.devicePixelRatio || 1;

      const tNow = currentT();
      const viewLeftTime = Math.max(0, tNow - w / (2 * pxPerSec));
      const viewRightTime = viewLeftTime + w / pxPerSec;

      ctx.lineWidth = 1;

      // Piano key row shading.
      for (let p = pitchLo; p <= pitchHi; p++) {
        if (!isBlackKey(p)) continue;
        const y = (pitchHi - p) * laneH;
        ctx.fillStyle = 'rgba(0,0,0,0.025)';
        ctx.fillRect(0, y, w, laneH);
      }

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
      for (const n of playbackNotes) {
        if (n.midi < pitchLo || n.midi > pitchHi) continue;

        const nStart = n.time;
        const noteEnd = n.time + n.duration;

        if (noteEnd < viewLeftTime) continue;
        if (nStart > viewRightTime) break;

        const y = (pitchHi - n.midi) * laneH;
        const nh = Math.max(1, laneH * 0.85);

        const a = 0.25 + 0.70 * clamp(n.velocity || 0.5, 0, 1);
        drawTimedRect(
          ctx,
          nStart,
          noteEnd,
          viewLeftTime,
          viewRightTime,
          pxPerSec,
          y + laneH * 0.08,
          nh,
          'rgba(20,40,55,' + a.toFixed(3) + ')'
        );
      }

      // Pedal rows sit below the 88-key piano range.
      pedalLanes.forEach((lane, index) => {
        const y = pedalTop + index * pedalLaneH;
        const rowBottom = y + pedalLaneH;

        ctx.fillStyle = index % 2 ? 'rgba(0,0,0,0.045)' : 'rgba(0,0,0,0.03)';
        ctx.fillRect(0, y, w, pedalLaneH);

        for (const segment of lane.segments) {
          const segmentStart = segment.time;
          const segmentEnd = segment.time + segment.duration;

          if (segmentEnd < viewLeftTime) continue;
          if (segmentStart > viewRightTime) break;

          drawTimedRect(
            ctx,
            segmentStart,
            segmentEnd,
            viewLeftTime,
            viewRightTime,
            pxPerSec,
            y + pedalLaneH * 0.18,
            pedalLaneH * 0.64,
            'rgba(20,40,55,0.58)'
          );
        }

        ctx.strokeStyle = 'rgba(0,0,0,0.09)';
        ctx.beginPath();
        ctx.moveTo(0, rowBottom);
        ctx.lineTo(w, rowBottom);
        ctx.stroke();

        ctx.font = Math.max(10 * dpr, Math.min(13 * dpr, pedalLaneH * 0.45)) + 'px sans-serif';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = 'rgba(20,40,55,0.72)';
        ctx.fillText(lane.label, 8 * dpr, y + pedalLaneH / 2);
      });

      ctx.strokeStyle = 'rgba(0,0,0,0.14)';
      ctx.beginPath();
      ctx.moveTo(0, pedalTop);
      ctx.lineTo(w, pedalTop);
      ctx.stroke();

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
      clearRenderedAudio('');
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
    if (volume) {
      volume.addEventListener('input', () => {
        clearRenderedAudio('');
        setVolume();
      });
    }

    renderBtn.addEventListener('click', async () => {
      if (activeRender) {
        cancelActiveRender('Render canceled');
        return;
      }

      if (!midi || !playbackNotes.length) return;

      const renderState = createRenderState();
      activeRender = renderState;

      try {
        if (isPlaying) {
          pause();
          playBtn.textContent = 'Play';
        }

        clearRenderedAudio('Preparing WAV...');
        setTempoScale();
        setVolume();

        setRenderControlLock(true);
        setRenderButtonMode('cancel');
        setRenderProgress(0.03, 'Preparing WAV...');
        await yieldToBrowser();
        ensureCurrentRender(renderState);

        startEstimatedRenderProgress(
          0.08,
          0.82,
          Math.max(4, (durTotal / Math.max(tempoScale, 0.05)) * 0.12),
          'Rendering WAV...'
        );

        const audioBuffer = await renderPianoAudio(
          playbackNotes,
          durTotal,
          tempoScale,
          volumeScale,
          renderState.signal
        );
        ensureCurrentRender(renderState);
        stopRenderProgressTimer();
        setRenderProgress(0.84, 'Encoding WAV...');
        await yieldToBrowser();
        ensureCurrentRender(renderState);

        const wav = await encodeWav(audioBuffer, (progress) => {
          if (activeRender !== renderState || renderState.canceled) return;
          setRenderProgress(0.84 + progress * 0.15, 'Encoding WAV...');
        }, renderState.signal);
        ensureCurrentRender(renderState);

        renderedAudioUrl = URL.createObjectURL(wav);
        downloadAudio.href = renderedAudioUrl;
        downloadAudio.download = audioDownloadFilename(url, el);
        downloadAudio.hidden = false;
        setRenderProgress(1, 'WAV ready');
        setRenderStatus('WAV ready');
      } catch (e) {
        if (activeRender !== renderState || renderState.canceled) return;

        if (isAbortError(e)) {
          if (activeRender === renderState) cancelActiveRender('Render canceled');
          return;
        }

        console.error('KML audio render failed:', e);
        stopRenderProgressTimer();
        hideRenderProgress();
        clearRenderedAudio('Render failed');
      } finally {
        if (activeRender === renderState) {
          activeRender = null;
          setRenderControlLock(false);
          setRenderButtonMode('render');
        }
      }
    });

    window.addEventListener('pagehide', () => {
      cancelActiveRender('');
      clearRenderedAudio('');
    });

    // ---- Load MIDI and start drawing ----
    (async () => {
      try {
        playBtn.disabled = true;
        playBtn.textContent = 'Loading...';
        setZoom();
        setTempoScale();
        setVolume();

        const buf = await loadArrayBuffer(url);
        midi = new Midi(buf);
        notes = collectNotes(midi);
        const pedalEvents = collectPedalEvents(midi);

        durTotal = Math.max(midi.duration || 0, noteEndTime(notes), lastPedalEventTime(pedalEvents));
        pedalLanes = buildPedalSegments(pedalEvents, durTotal);
        playbackNotes = buildPlaybackNotes(notes, getPedalSegments(pedalLanes, SUSTAIN_CC));
        durTotal = Math.max(durTotal, noteEndTime(playbackNotes, 'playbackDuration'));

        playBtn.disabled = false;
        playBtn.textContent = 'Play';
        renderBtn.disabled = playbackNotes.length === 0;

        warmPianoSoon();

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
