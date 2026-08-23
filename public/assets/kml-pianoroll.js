/* global Midi, Tone, Soundfont */
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
  const SOUNDFONT_MASTER_GAIN = 0.82;
  const SOUNDFONT_NAME = 'FluidR3_GM';
  const SOUNDFONT_FORMAT = 'mp3';
  const SOUNDFONT_FALLBACK_INSTRUMENT = 'acoustic_grand_piano';
  const DRUM_CHANNEL = 9;
  const PIANO_PROGRAM_MAX = 7;
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
  const CHANNEL_COLORS = [
    '#2563eb',
    '#dc2626',
    '#16a34a',
    '#9333ea',
    '#ea580c',
    '#0891b2',
    '#be123c',
    '#65a30d',
    '#7c3aed',
    '#b45309',
    '#0f766e',
    '#c026d3',
    '#4b5563',
    '#f59e0b',
    '#0284c7',
    '#84cc16',
  ];
  const GM_SOUNDFONT_INSTRUMENTS = [
    'acoustic_grand_piano',
    'bright_acoustic_piano',
    'electric_grand_piano',
    'honkytonk_piano',
    'electric_piano_1',
    'electric_piano_2',
    'harpsichord',
    'clavinet',
    'celesta',
    'glockenspiel',
    'music_box',
    'vibraphone',
    'marimba',
    'xylophone',
    'tubular_bells',
    'dulcimer',
    'drawbar_organ',
    'percussive_organ',
    'rock_organ',
    'church_organ',
    'reed_organ',
    'accordion',
    'harmonica',
    'tango_accordion',
    'acoustic_guitar_nylon',
    'acoustic_guitar_steel',
    'electric_guitar_jazz',
    'electric_guitar_clean',
    'electric_guitar_muted',
    'overdriven_guitar',
    'distortion_guitar',
    'guitar_harmonics',
    'acoustic_bass',
    'electric_bass_finger',
    'electric_bass_pick',
    'fretless_bass',
    'slap_bass_1',
    'slap_bass_2',
    'synth_bass_1',
    'synth_bass_2',
    'violin',
    'viola',
    'cello',
    'contrabass',
    'tremolo_strings',
    'pizzicato_strings',
    'orchestral_harp',
    'timpani',
    'string_ensemble_1',
    'string_ensemble_2',
    'synth_strings_1',
    'synth_strings_2',
    'choir_aahs',
    'voice_oohs',
    'synth_choir',
    'orchestra_hit',
    'trumpet',
    'trombone',
    'tuba',
    'muted_trumpet',
    'french_horn',
    'brass_section',
    'synth_brass_1',
    'synth_brass_2',
    'soprano_sax',
    'alto_sax',
    'tenor_sax',
    'baritone_sax',
    'oboe',
    'english_horn',
    'bassoon',
    'clarinet',
    'piccolo',
    'flute',
    'recorder',
    'pan_flute',
    'blown_bottle',
    'shakuhachi',
    'whistle',
    'ocarina',
    'lead_1_square',
    'lead_2_sawtooth',
    'lead_3_calliope',
    'lead_4_chiff',
    'lead_5_charang',
    'lead_6_voice',
    'lead_7_fifths',
    'lead_8_bass__lead',
    'pad_1_new_age',
    'pad_2_warm',
    'pad_3_polysynth',
    'pad_4_choir',
    'pad_5_bowed',
    'pad_6_metallic',
    'pad_7_halo',
    'pad_8_sweep',
    'fx_1_rain',
    'fx_2_soundtrack',
    'fx_3_crystal',
    'fx_4_atmosphere',
    'fx_5_brightness',
    'fx_6_goblins',
    'fx_7_echoes',
    'fx_8_scifi',
    'sitar',
    'banjo',
    'shamisen',
    'koto',
    'kalimba',
    'bagpipe',
    'fiddle',
    'shanai',
    'tinkle_bell',
    'agogo',
    'steel_drums',
    'woodblock',
    'taiko_drum',
    'melodic_tom',
    'synth_drum',
    'reverse_cymbal',
    'guitar_fret_noise',
    'breath_noise',
    'seashore',
    'bird_tweet',
    'telephone_ring',
    'helicopter',
    'applause',
    'gunshot',
  ];

  let tonePiano = null;
  let tonePianoPromise = null;
  let soundfontContext = null;
  let soundfontMasterGain = null;
  const soundfontPlayers = new Map();
  const soundfontPromises = new Map();

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

  function titleCaseInstrumentName(name) {
    return String(name || 'Instrument')
      .replace(/__/g, ' + ')
      .replace(/_/g, ' ')
      .replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  function soundfontNameForProgram(program, percussion) {
    if (percussion) return 'synth_drum';

    const index = clamp(Math.round(Number(program) || 0), 0, GM_SOUNDFONT_INSTRUMENTS.length - 1);
    return GM_SOUNDFONT_INSTRUMENTS[index] || GM_SOUNDFONT_INSTRUMENTS[0];
  }

  function channelColor(channel) {
    const index = clamp(Math.round(Number(channel) || 0), 0, CHANNEL_COLORS.length - 1);
    return CHANNEL_COLORS[index] || CHANNEL_COLORS[0];
  }

  function hexToRgba(hex, alpha) {
    const clean = String(hex || '').replace('#', '');
    const value = /^[0-9a-f]{6}$/i.test(clean) ? clean : '2563eb';
    const r = parseInt(value.slice(0, 2), 16);
    const g = parseInt(value.slice(2, 4), 16);
    const b = parseInt(value.slice(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  function safeTrackChannel(track) {
    if (track && Number.isFinite(track.channel)) {
      return clamp(Math.round(track.channel), 0, 15);
    }
    return 0;
  }

  function safeTrackProgram(track) {
    const instrument = track && track.instrument;
    if (instrument && Number.isFinite(instrument.number)) {
      return clamp(Math.round(instrument.number), 0, 127);
    }
    return 0;
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
    const notes = [];
    midi.tracks.forEach((t, trackIndex) => {
      const channel = safeTrackChannel(t);
      const program = safeTrackProgram(t);
      const percussion = !!(t && t.instrument && t.instrument.percussion) || channel === DRUM_CHANNEL;
      const soundfontName = soundfontNameForProgram(program, percussion);
      const instrumentLabel = percussion ? 'Percussion' : titleCaseInstrumentName(soundfontName);
      const trackName = t && t.name ? String(t.name) : '';

      t.notes.forEach((n) =>
        notes.push({
          channel,
          channelColor: channelColor(channel),
          time: n.time,
          duration: n.duration,
          instrumentLabel,
          midi: n.midi,
          partKey: channel + ':' + program + ':' + soundfontName,
          percussion,
          program,
          soundfontName,
          trackIndex,
          trackName,
          velocity: n.velocity,
        })
      );
    });
    notes.sort((a, b) => a.time - b.time || a.channel - b.channel || a.midi - b.midi);
    return notes;
  }

  function collectChannelMetas(notes) {
    const channels = new Map();

    notes.forEach((note) => {
      const channel = Number.isFinite(note.channel) ? note.channel : 0;
      if (!channels.has(channel)) {
        channels.set(channel, {
          channel,
          color: channelColor(channel),
          instruments: [],
          instrumentKeys: new Set(),
          noteCount: 0,
          trackNames: [],
          trackNameKeys: new Set(),
        });
      }

      const meta = channels.get(channel);
      const instrumentKey = note.percussion ? 'percussion' : String(note.program) + ':' + note.soundfontName;
      meta.noteCount++;

      if (!meta.instrumentKeys.has(instrumentKey)) {
        meta.instrumentKeys.add(instrumentKey);
        meta.instruments.push({
          label: note.instrumentLabel,
          percussion: note.percussion,
          program: note.program,
          soundfontName: note.soundfontName,
        });
      }

      if (note.trackName && !meta.trackNameKeys.has(note.trackName)) {
        meta.trackNameKeys.add(note.trackName);
        meta.trackNames.push(note.trackName);
      }
    });

    return Array.from(channels.values()).sort((a, b) => a.channel - b.channel);
  }

  function activeChannelCount(channelMetas) {
    return channelMetas.filter((meta) => meta.noteCount > 0).length;
  }

  function isPianoFamilyInstrument(instrument) {
    return (
      instrument &&
      !instrument.percussion &&
      Number.isFinite(instrument.program) &&
      instrument.program <= PIANO_PROGRAM_MAX
    );
  }

  function allActiveChannelsArePiano(channelMetas) {
    return channelMetas
      .filter((meta) => meta.noteCount > 0)
      .every((meta) => meta.instruments.length > 0 && meta.instruments.every(isPianoFamilyInstrument));
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

        if (events.length) {
          tracks.push({
            channel: safeTrackChannel(t),
            events,
          });
        }
      });

      return Object.assign({}, lane, { tracks });
    });
  }

  function lastPedalEventTime(pedalEvents) {
    let end = 0;

    pedalEvents.forEach((lane) => {
      lane.tracks.forEach((track) => {
        track.events.forEach((event) => {
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

      lane.tracks.forEach((track) => {
        const events = track.events;
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

  function segmentsFromPedalEvents(eventTracks, duration) {
    const segments = [];

    eventTracks.forEach((events) => {
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

    return mergeSegments(segments);
  }

  function buildPedalSegmentsByChannel(pedalEvents, cc, duration) {
    const lane = pedalEvents.find((item) => item.cc === cc);
    const segmentsByChannel = new Map();

    if (!lane) return segmentsByChannel;

    lane.tracks.forEach((track) => {
      if (!segmentsByChannel.has(track.channel)) segmentsByChannel.set(track.channel, []);
      segmentsByChannel.get(track.channel).push(track.events);
    });

    segmentsByChannel.forEach((eventTracks, channel) => {
      segmentsByChannel.set(channel, segmentsFromPedalEvents(eventTracks, duration));
    });

    return segmentsByChannel;
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

  function buildPlaybackNotes(notes, sustainSegmentsByChannel) {
    const nextStartByPitch = new Map();
    const playbackNotes = new Array(notes.length);

    for (let i = notes.length - 1; i >= 0; i--) {
      const n = notes[i];
      const noteEnd = n.time + n.duration;
      const sustainSegments = sustainSegmentsByChannel.get(n.channel) || [];
      const sustainedEnd = sustainedEndForNote(noteEnd, sustainSegments);
      const pitchKey = n.partKey + ':' + n.midi;
      const nextStart = nextStartByPitch.get(pitchKey);
      let playbackEnd = sustainedEnd;

      if (Number.isFinite(nextStart) && nextStart > n.time) {
        playbackEnd = Math.min(playbackEnd, Math.max(n.time + 0.03, nextStart));
      }

      playbackNotes[i] = Object.assign({}, n, {
        playbackDuration: Math.max(0.02, playbackEnd - n.time),
      });

      nextStartByPitch.set(pitchKey, n.time);
    }

    return playbackNotes;
  }

  function toneVelocity(note) {
    return typeof note.velocity === 'number' ? clamp(note.velocity, 0, 1) : 0.8;
  }

  function canUseSoundfont() {
    return !!(
      window.Soundfont &&
      typeof Soundfont.instrument === 'function' &&
      (window.AudioContext || window.webkitAudioContext)
    );
  }

  function ensureSoundfontContext() {
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;

    if (!AudioContextCtor) {
      throw new Error('WebAudio is unavailable.');
    }

    if (!soundfontContext) {
      soundfontContext = new AudioContextCtor();
      soundfontMasterGain = soundfontContext.createGain();
      soundfontMasterGain.gain.value = SOUNDFONT_MASTER_GAIN;
      soundfontMasterGain.connect(soundfontContext.destination);
    }

    return soundfontContext;
  }

  function setSoundfontVolume(scale) {
    if (!soundfontMasterGain || !soundfontMasterGain.gain) return;

    const target = SOUNDFONT_MASTER_GAIN * Math.max(0, scale || 0);
    const gain = soundfontMasterGain.gain;
    const now = soundfontContext ? soundfontContext.currentTime : 0;

    try {
      if (typeof gain.cancelScheduledValues === 'function') gain.cancelScheduledValues(now);
      if (typeof gain.setTargetAtTime === 'function') {
        gain.setTargetAtTime(target, now, 0.02);
      } else {
        gain.value = target;
      }
    } catch (e) {
      try {
        gain.value = target;
      } catch (e1) {}
    }
  }

  function uniqueSoundfontRequests(playbackNotes) {
    const requests = new Map();

    playbackNotes.forEach((note) => {
      const name = note.soundfontName || GM_SOUNDFONT_INSTRUMENTS[0];
      if (!requests.has(name)) {
        requests.set(name, {
          name,
          notes: new Set(),
        });
      }
      requests.get(name).notes.add(midiToToneNote(clamp(Math.round(note.midi), 0, 127)));
    });

    return Array.from(requests.values()).map((request) => ({
      name: request.name,
      notes: Array.from(request.notes.values()).sort(),
    }));
  }

  async function ensureSoundfontPlayer(request) {
    const name = request.name || GM_SOUNDFONT_INSTRUMENTS[0];
    const key = name + ':' + request.notes.join(',');
    if (soundfontPlayers.has(key)) {
      return {
        name,
        player: soundfontPlayers.get(key),
      };
    }
    if (soundfontPromises.has(key)) {
      return {
        name,
        player: await soundfontPromises.get(key),
      };
    }

    const ac = ensureSoundfontContext();
    const loadInstrument = (instrumentName) =>
      Soundfont.instrument(ac, instrumentName, {
        destination: soundfontMasterGain,
        format: SOUNDFONT_FORMAT,
        notes: request.notes,
        soundfont: SOUNDFONT_NAME,
      });

    const promise = loadInstrument(name)
      .catch((error) => {
        if (name === SOUNDFONT_FALLBACK_INSTRUMENT) throw error;

        console.warn(
          'KML soundfont failed to load ' +
            name +
            '; using ' +
            SOUNDFONT_FALLBACK_INSTRUMENT +
            ' instead.',
          error
        );

        return loadInstrument(SOUNDFONT_FALLBACK_INSTRUMENT);
      })
      .then((player) => {
        soundfontPlayers.set(key, player);
        soundfontPromises.delete(key);
        return player;
      })
      .catch((error) => {
        soundfontPromises.delete(key);
        throw error;
      });

    soundfontPromises.set(key, promise);
    return {
      name,
      player: await promise,
    };
  }

  async function ensureSoundfontPlayback(playbackNotes) {
    if (!canUseSoundfont()) {
      throw new Error('Soundfont player was not loaded.');
    }

    const ac = ensureSoundfontContext();

    if (ac.state === 'suspended' && typeof ac.resume === 'function') {
      await ac.resume();
    }

    const loadedPlayers = await Promise.all(uniqueSoundfontRequests(playbackNotes).map(ensureSoundfontPlayer));
    const playersByName = new Map();

    loadedPlayers.forEach((loaded) => {
      playersByName.set(loaded.name, loaded.player);
    });

    return {
      context: ac,
      players: playersByName,
    };
  }

  function stopSoundfontPlayback(state, when) {
    if (!state || !state.players) return;

    state.players.forEach((player) => {
      try {
        if (player && typeof player.stop === 'function') player.stop(when);
      } catch (e) {}
    });
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
    const controls = el.querySelector('.kml-roll-controls');

    if (!canvas || !playBtn || !stopBtn || !tempo || !zoom || !timeEl || !controls) return;

    const playLabel = playBtn.textContent.trim() || 'Play';
    const stopLabel = stopBtn.textContent.trim() || 'Stop';

    const channelKey = document.createElement('div');
    const playerActions = document.createElement('div');
    const renderControls = document.createElement('div');
    const renderBtn = document.createElement('button');
    const downloadAudio = document.createElement('a');
    const renderStatus = document.createElement('span');
    const renderProgress = document.createElement('div');
    const renderProgressFill = document.createElement('span');

    channelKey.className = 'kml-channel-key';
    channelKey.hidden = true;
    channelKey.setAttribute('aria-label', 'Channel key');

    playerActions.className = 'kml-player-actions';
    playerActions.setAttribute('role', 'group');
    playerActions.setAttribute('aria-label', 'Playback and audio file actions');

    renderControls.className = 'kml-render-controls';
    renderControls.hidden = true;

    renderBtn.type = 'button';
    renderBtn.className = 'kml-btn kml-render-audio';
    renderBtn.disabled = true;

    downloadAudio.className = 'kml-btn kml-download-audio';
    downloadAudio.hidden = true;
    downloadAudio.rel = 'nofollow';
    downloadAudio.download = audioDownloadFilename(url, el);

    renderStatus.className = 'kml-render-status';
    renderStatus.setAttribute('aria-live', 'polite');

    renderProgress.className = 'kml-render-progress';
    renderProgress.hidden = true;
    renderProgress.setAttribute('role', 'progressbar');
    renderProgress.setAttribute('aria-label', 'WAV creation progress');
    renderProgress.setAttribute('aria-valuemin', '0');
    renderProgress.setAttribute('aria-valuemax', '100');
    renderProgress.setAttribute('aria-valuenow', '0');
    renderProgress.appendChild(renderProgressFill);

    setPlayButtonMode('loading');
    setActionButtonLabel(stopBtn, '', stopLabel);
    stopBtn.disabled = true;
    stopBtn.setAttribute('aria-label', 'Stop playback and return to the beginning');
    stopBtn.title = 'Stop playback and return to the beginning';
    setRenderButtonMode('render');
    setActionButtonLabel(downloadAudio, '\u2193', 'Download WAV');

    controls.insertBefore(playerActions, playBtn);
    playerActions.appendChild(playBtn);
    playerActions.appendChild(stopBtn);
    playerActions.appendChild(renderBtn);

    renderControls.appendChild(downloadAudio);
    renderControls.appendChild(renderStatus);
    renderControls.appendChild(renderProgress);
    playerActions.insertAdjacentElement('afterend', renderControls);
    canvas.insertAdjacentElement('beforebegin', channelKey);

    const ctx = canvas.getContext('2d');

    let midi = null;
    let notes = [];
    let playbackNotes = [];
    let channelMetas = [];
    let pedalLanes = PEDAL_LANES.map((lane) => Object.assign({}, lane, { segments: [] }));
    let durTotal = 0;
    let audioMode = 'piano';
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
    let activeSoundfont = null;
    let playbackRequestId = 0;
    let tempoDebounce = null;
    const mutedChannels = new Set();

    function setStatus(msg) {
      // Keep this subtle; time text will overwrite during draw
      timeEl.textContent = msg;
    }

    function setActionButtonLabel(button, iconText, labelText) {
      const icon = document.createElement('span');
      const label = document.createElement('span');

      icon.className = 'kml-btn-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = iconText;
      label.className = 'kml-btn-label';
      label.textContent = labelText;

      button.textContent = '';
      button.appendChild(icon);
      button.appendChild(label);
    }

    function setPlayButtonMode(mode) {
      const states = {
        loading: { icon: '\u2026', label: 'Loading', ariaLabel: 'Loading MIDI playback' },
        pause: { icon: '', label: 'Pause', ariaLabel: 'Pause MIDI playback' },
        play: { icon: '', label: playLabel, ariaLabel: 'Play MIDI' },
      };
      const state = states[mode] || states.play;

      setActionButtonLabel(playBtn, state.icon, state.label);
      playBtn.dataset.state = mode in states ? mode : 'play';
      playBtn.setAttribute('aria-label', state.ariaLabel);
      playBtn.removeAttribute('aria-pressed');
    }

    function updateRenderControlsVisibility() {
      renderControls.hidden =
        downloadAudio.hidden && renderProgress.hidden && !renderStatus.textContent.trim();
    }

    function setRenderStatus(msg) {
      renderStatus.textContent = msg || '';
      updateRenderControlsVisibility();
    }

    function instrumentSummary(meta) {
      const labels = meta.instruments.map((instrument) => instrument.label);
      if (!labels.length) return 'Instrument';
      if (labels.length <= 2) return labels.join(', ');
      return labels.slice(0, 2).join(', ') + ' +' + (labels.length - 2);
    }

    function isChannelMuted(channel) {
      return mutedChannels.has(channel);
    }

    function activePlaybackNotes() {
      return playbackNotes.filter((note) => !isChannelMuted(note.channel));
    }

    function refreshRenderAvailability() {
      renderBtn.disabled = activeRender ? false : !canRenderAudio();
      if (audioMode === 'soundfont' && playbackNotes.length) {
        setRenderStatus('WAV render unavailable for multichannel playback');
      } else if (audioMode === 'piano') {
        setRenderStatus('');
      }
    }

    function updateChannelKey() {
      channelKey.textContent = '';
      channelKey.hidden = channelMetas.length === 0;

      channelMetas.forEach((meta) => {
        const item = document.createElement('button');
        const swatch = document.createElement('span');
        const label = document.createElement('span');
        const trackNames = meta.trackNames.slice(0, 2).join(', ');
        const suffix = trackNames ? ' - ' + trackNames : '';
        const muted = isChannelMuted(meta.channel);

        item.type = 'button';
        item.className = 'kml-channel-key-item' + (muted ? ' is-muted' : '');
        item.setAttribute('aria-pressed', muted ? 'true' : 'false');
        swatch.className = 'kml-channel-swatch';
        swatch.style.backgroundColor = meta.color;
        label.className = 'kml-channel-key-label';
        label.textContent =
          'Ch ' +
          (meta.channel + 1) +
          ' - ' +
          instrumentSummary(meta) +
          ' - ' +
          meta.noteCount +
          ' notes' +
          suffix;
        label.title = label.textContent;
        item.setAttribute(
          'aria-label',
          (muted ? 'Unmute ' : 'Mute ') + 'channel ' + (meta.channel + 1)
        );
        item.title = muted ? 'Unmute channel' : 'Mute channel';
        item.addEventListener('click', () => toggleChannelMute(meta.channel));

        item.appendChild(swatch);
        item.appendChild(label);
        channelKey.appendChild(item);
      });
    }

    function chooseAudioMode() {
      if (activeChannelCount(channelMetas) <= 1) return 'piano';
      if (allActiveChannelsArePiano(channelMetas)) return 'piano';
      if (canUseSoundfont()) return 'soundfont';

      console.warn('KML soundfont player is unavailable; using piano playback for multichannel MIDI.');
      return 'piano';
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
      updateRenderControlsVisibility();
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
      else updateRenderControlsVisibility();
    }

    function canRenderAudio() {
      return !!midi && activePlaybackNotes().length > 0 && audioMode === 'piano';
    }

    function setRenderControlLock(locked) {
      playBtn.disabled = locked || !midi;
      stopBtn.disabled = locked || !midi;
      tempo.disabled = locked || !midi;
      zoom.disabled = locked || !midi;
      if (volume) volume.disabled = locked || !midi;
      channelKey.querySelectorAll('.kml-channel-key-item').forEach((button) => {
        button.disabled = locked || !midi;
      });
      renderBtn.disabled = activeRender ? false : !canRenderAudio();
    }

    function setRenderButtonMode(mode) {
      if (mode === 'cancel') {
        setActionButtonLabel(renderBtn, '\u00d7', 'Cancel');
        renderBtn.classList.add('is-cancel');
        renderBtn.setAttribute('aria-label', 'Cancel WAV render');
        renderBtn.title = 'Cancel WAV creation';
      } else {
        setActionButtonLabel(renderBtn, '\u2193', 'Create WAV');
        renderBtn.classList.remove('is-cancel');
        renderBtn.setAttribute('aria-label', 'Create a downloadable WAV audio file');
        renderBtn.title = 'Create a downloadable WAV audio file';
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

    function applyPlaybackVolume() {
      applyPianoVolume();
      setSoundfontVolume(volumeScale);
    }

    function setVolume() {
      if (!volume) {
        volumeScale = PIANO_VOLUME_DEFAULT / 100;
        applyPlaybackVolume();
        return;
      }

      const pct = clamp(Number(volume.value || PIANO_VOLUME_DEFAULT), 0, 200);
      volumeScale = pct / 100;
      if (volumeVal) volumeVal.textContent = Math.round(pct) + '%';
      applyPlaybackVolume();
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

      try {
        if (activeSoundfont && activeSoundfont.context) {
          stopSoundfontPlayback(activeSoundfont, activeSoundfont.context.currentTime);
        }
      } catch (e) {}
    }

    function restartPlaybackAtCurrentTime() {
      if (!isPlaying) return;

      startAt = currentT();
      cancelScheduled();
      startPerf = performance.now();
      resetScheduleIndex();
      schedulePlaybackWindow();
      scheduleTimer = window.setInterval(schedulePlaybackWindow, NOTE_SCHEDULE_INTERVAL_MS);
    }

    function toggleChannelMute(channel) {
      if (activeRender) return;

      if (mutedChannels.has(channel)) {
        mutedChannels.delete(channel);
      } else {
        mutedChannels.add(channel);
      }

      updateChannelKey();
      clearRenderedAudio('');
      refreshRenderAvailability();
      restartPlaybackAtCurrentTime();
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

    function scheduleSoundfontNote(n, tNow, audioNow) {
      if (!activeSoundfont || !activeSoundfont.players) return;
      if (!Number.isFinite(n.midi) || n.midi < 0 || n.midi > 127) return;

      const player = activeSoundfont.players.get(n.soundfontName);
      if (!player || typeof player.play !== 'function') return;

      const end = n.time + n.playbackDuration;
      const struckEnd = n.time + n.duration;
      if (end <= tNow || struckEnd <= tNow) return;

      const audibleStart = Math.max(n.time, tNow);
      const when = audioNow + Math.max(0, (audibleStart - tNow) / tempoScale);
      const duration = Math.max(0.03, (end - audibleStart) / tempoScale);

      player.play(midiToToneNote(clamp(Math.round(n.midi), 0, 127)), when, {
        gain: toneVelocity(n),
        duration,
      });
    }

    function schedulePlaybackWindow() {
      if (!isPlaying) return;
      if (audioMode === 'soundfont' && !activeSoundfont) return;
      if (audioMode !== 'soundfont' && !activePiano) return;

      const tNow = currentT();
      const audioNow =
        audioMode === 'soundfont' && activeSoundfont.context
          ? activeSoundfont.context.currentTime
          : Tone.now();
      const windowEnd = Math.min(durTotal, tNow + NOTE_SCHEDULE_LOOKAHEAD * tempoScale);

      while (nextNoteIndex < playbackNotes.length) {
        const n = playbackNotes[nextNoteIndex];
        if (n.time > windowEnd) break;

        if (isChannelMuted(n.channel)) {
          nextNoteIndex++;
          continue;
        } else if (audioMode === 'soundfont') {
          scheduleSoundfontNote(n, tNow, audioNow);
        } else {
          scheduleToneNote(n, tNow, audioNow);
        }
        nextNoteIndex++;
      }
    }

    function beginPlaybackRequest() {
      playbackRequestId += 1;
      return playbackRequestId;
    }

    function cancelPendingPlaybackStart() {
      playbackRequestId += 1;
      if (tempoDebounce !== null) {
        window.clearTimeout(tempoDebounce);
        tempoDebounce = null;
      }
    }

    function isCurrentPlaybackRequest(requestId) {
      return requestId === playbackRequestId && !activeRender;
    }

    async function play(requestId) {
      if (!midi || !isCurrentPlaybackRequest(requestId)) return false;

      setTempoScale();

      let piano = null;
      let soundfont = null;

      if (audioMode === 'soundfont') {
        try {
          setStatus('Loading instruments...');
          soundfont = await ensureSoundfontPlayback(playbackNotes);
        } catch (error) {
          if (!isCurrentPlaybackRequest(requestId)) return false;
          console.warn('KML soundfont playback failed; falling back to piano playback.', error);
          audioMode = 'piano';
          refreshRenderAvailability();
          renderBtn.disabled = true;
          setStatus('Loading piano...');
          piano = await ensureTonePiano({ resumeCtx: true });
        }
      } else {
        setStatus('Loading piano...');
        piano = await ensureTonePiano({ resumeCtx: true });
      }

      if (!isCurrentPlaybackRequest(requestId)) return false;

      // Cancel anything from a prior run/pause
      cancelScheduled();

      activePiano = piano;
      activeSoundfont = soundfont;
      applyPlaybackVolume();
      isPlaying = true;
      startPerf = performance.now();
      resetScheduleIndex();
      schedulePlaybackWindow();
      scheduleTimer = window.setInterval(schedulePlaybackWindow, NOTE_SCHEDULE_INTERVAL_MS);
      return true;
    }

    function pause() {
      const t = currentT();
      cancelScheduled();
      isPlaying = false;
      startAt = t;
    }

    function stop() {
      cancelPendingPlaybackStart();
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

        const muted = isChannelMuted(n.channel);
        const a = (0.25 + 0.70 * clamp(n.velocity || 0.5, 0, 1)) * (muted ? 0.18 : 1);
        drawTimedRect(
          ctx,
          nStart,
          noteEnd,
          viewLeftTime,
          viewRightTime,
          pxPerSec,
          y + laneH * 0.08,
          nh,
          hexToRgba(n.channelColor, a.toFixed(3))
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
        setPlayButtonMode('play');
      }

      requestAnimationFrame(draw);
    }

    // ---- UI wiring ----
    playBtn.addEventListener('click', async () => {
      if (!midi) return;

      if (isPlaying) {
        pause();
        setPlayButtonMode('play');
        return;
      }

      const requestId = beginPlaybackRequest();

      try {
        playBtn.disabled = true;
        renderBtn.disabled = true;
        setPlayButtonMode('loading');
        const started = await play(requestId);
        if (!started) return;
        setPlayButtonMode('pause');
      } catch (e) {
        if (!isCurrentPlaybackRequest(requestId)) return;
        console.error('KML piano roll failed during play:', e);
        stop();
        setStatus('Play failed');
        setPlayButtonMode('play');
        playBtn.disabled = false;
        stopBtn.disabled = false;
        renderBtn.disabled = !canRenderAudio();
      } finally {
        if (isCurrentPlaybackRequest(requestId)) {
          playBtn.disabled = false;
          renderBtn.disabled = !canRenderAudio();
        }
      }
    });

    stopBtn.addEventListener('click', () => {
      stop();
      setPlayButtonMode('play');
      playBtn.disabled = !midi;
      stopBtn.disabled = !midi;
      renderBtn.disabled = !canRenderAudio();
    });

    // Debounce tempo changes so we do not restart 60 times/sec while dragging
    tempo.addEventListener('input', () => {
      clearRenderedAudio('');
      setTempoScale();
      const shouldRestart = isPlaying || tempoDebounce !== null;
      if (!shouldRestart) return;

      if (isPlaying) {
        const t = currentT();
        pause();
        startAt = t;
      }

      if (tempoDebounce !== null) window.clearTimeout(tempoDebounce);
      playbackRequestId += 1;
      playBtn.disabled = true;
      renderBtn.disabled = true;
      setPlayButtonMode('loading');

      tempoDebounce = window.setTimeout(async () => {
        tempoDebounce = null;
        const requestId = beginPlaybackRequest();

        try {
          const started = await play(requestId);
          if (!started) return;
          setPlayButtonMode('pause');
        } catch (e) {
          if (!isCurrentPlaybackRequest(requestId)) return;
          console.error('KML tempo restart failed:', e);
          stop();
          setPlayButtonMode('play');
          playBtn.disabled = false;
          stopBtn.disabled = false;
          renderBtn.disabled = !canRenderAudio();
        } finally {
          if (isCurrentPlaybackRequest(requestId)) {
            playBtn.disabled = false;
            renderBtn.disabled = !canRenderAudio();
          }
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

      if (!midi || !activePlaybackNotes().length) return;

      cancelPendingPlaybackStart();
      if (isPlaying) pause();
      setPlayButtonMode('play');

      const renderState = createRenderState();
      activeRender = renderState;

      try {
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
          activePlaybackNotes(),
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
      stop();
      cancelActiveRender('');
      clearRenderedAudio('');
    });

    // ---- Load MIDI and start drawing ----
    (async () => {
      try {
        playBtn.disabled = true;
        setPlayButtonMode('loading');
        setZoom();
        setTempoScale();
        setVolume();

        const buf = await loadArrayBuffer(url);
        midi = new Midi(buf);
        notes = collectNotes(midi);
        channelMetas = collectChannelMetas(notes);
        updateChannelKey();
        audioMode = chooseAudioMode();

        const pedalEvents = collectPedalEvents(midi);
        const sustainSegmentsByChannel = buildPedalSegmentsByChannel(
          pedalEvents,
          SUSTAIN_CC,
          Math.max(midi.duration || 0, noteEndTime(notes), lastPedalEventTime(pedalEvents))
        );

        durTotal = Math.max(midi.duration || 0, noteEndTime(notes), lastPedalEventTime(pedalEvents));
        pedalLanes = buildPedalSegments(pedalEvents, durTotal);
        playbackNotes = buildPlaybackNotes(notes, sustainSegmentsByChannel);
        durTotal = Math.max(durTotal, noteEndTime(playbackNotes, 'playbackDuration'));

        playBtn.disabled = false;
        stopBtn.disabled = false;
        setPlayButtonMode('play');
        refreshRenderAvailability();

        if (audioMode === 'piano') warmPianoSoon();

        requestAnimationFrame(draw);
      } catch (e) {
        console.error('KML piano roll failed:', e);
        setStatus('Preview unavailable');
        setPlayButtonMode('play');
        playBtn.setAttribute('aria-label', 'MIDI playback unavailable');
        playBtn.title = 'MIDI playback unavailable';
        playBtn.disabled = true;
        stopBtn.disabled = true;
        renderBtn.disabled = true;
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
