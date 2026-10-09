/* global testPlayer */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', async () => {
    const output = document.querySelector('#results');
    const roll = document.querySelector('.kml-roll');
    const q = selector => roll.querySelector(selector);
    const results = [];
    const h = testPlayer;
    let failures = 0;
    const assert = (condition, message) => { if (!condition) throw new Error(message); };
    const position = () => Number(q('.kml-seek').value);
    const title = () => q('.kml-file-title').textContent;
    const input = (element, value, event = 'input') => {
      element.value = String(value);
      element.dispatchEvent(new Event(event, { bubbles: true }));
      h.frame();
    };
    const seek = value => input(q('.kml-seek'), value);
    const mode = async value => {
      input(q('.kml-sound-mode'), value, 'change');
      await settle();
    };
    const close = (actual, expected, message, tolerance = 0.07) => {
      assert(Math.abs(actual - expected) < tolerance, message + ': expected ' + expected + ', got ' + actual);
    };
    const settle = async () => {
      for (let i = 0; i < 4; i++) await h.wait();
      h.frame();
    };
    const play = async () => {
      q('.kml-play').click();
      await settle();
      assert(q('.kml-play').dataset.state === 'pause', 'Play starts playback');
    };
    const stop = () => { q('.kml-stop').click(); h.frame(); };
    const file = name => new File([h.fixture(name)], name + '.mid', { type: 'audio/midi' });
    const softPedalFile = (changes = [[1, 1], [3, 0]]) => {
      const midi = new Midi();
      midi.header.name = 'Soft pedal passage';
      const first = midi.addTrack();
      first.channel = 0;
      first.addNote({ midi: 60, time: 0, duration: 6, velocity: 0.8 });
      changes.forEach(([time, value]) => first.addCC({ number: 67, time, value }));
      const second = midi.addTrack();
      second.channel = 1;
      second.addNote({ midi: 48, time: 0, duration: 6, velocity: 0.8 });
      return new File([midi.toArray()], 'soft-pedal.mid');
    };
    const channelGate = record => {
      const gate = h.gainPath(record)[0];
      assert(gate && gate.gain, 'Each audible channel has a gain node');
      return gate;
    };
    async function test(name, run) {
      try { await run(); results.push('PASS ' + name); }
      catch (error) { failures++; results.push('FAIL ' + name + ': ' + error.message); console.error(error); }
      output.textContent = results.join('\n');
    }

    await settle();
    await test('Idle player makes no MIDI request', async () => {
      assert(typeof roll.kmlLoadFile === 'function', 'Local file API is ready');
      assert(h.requests.length === 0, 'No archive or local file fetched on initial load');
      assert(q('.kml-play').disabled, 'Play disabled before a file is chosen');
    });

    await test('File picker parses a native browser File locally', async () => {
      const transfer = new DataTransfer();
      transfer.items.add(file('Local ensemble'));
      const picker = document.querySelector('.kml-upload-input');
      picker.files = transfer.files;
      picker.dispatchEvent(new Event('change', { bubbles: true }));
      await settle();
      assert(title().includes('Local ensemble'), 'Title comes from the chosen MIDI');
      assert(q('.kml-file-details').textContent.includes('5 notes'), 'Metadata reports all five notes');
      close(Number(q('.kml-seek').max), 12, 'Metadata duration');
      assert(!q('.kml-play').disabled, 'Play is ready without a Load step');
      assert(q('.kml-play').dataset.state === 'play', 'Selection does not autoplay');
      assert(h.requests.length === 0, 'Selected file caused no fetch/upload');
    });

    await test('Dragging a file parses it without an upload', async () => {
      const transfer = new DataTransfer();
      transfer.items.add(file('Dropped ensemble'));
      const wrapper = document.querySelector('.kml-midi-player-tool');
      wrapper.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: transfer }));
      wrapper.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: transfer }));
      assert(wrapper.classList.contains('is-dragover'), 'Drop area highlights');
      wrapper.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }));
      await settle();
      assert(title().includes('Dropped ensemble'), 'Dropped MIDI replaces the file');
      assert(!wrapper.classList.contains('is-dragover'), 'Drop highlight resets');
      assert(h.requests.length === 0, 'Dropped file caused no fetch/upload');
    });

    await test('Explicit sound choice controls WAV availability', async () => {
      await mode('soundfont');
      assert(q('.kml-render-audio').disabled, 'File instruments explain unavailable WAV export');
      await mode('piano');
      assert(!q('.kml-render-audio').disabled, 'Piano mode enables WAV export');
    });

    await test('Seeking preserves paused state and resumes sustained notes', async () => {
      stop();
      await mode('piano');
      seek(2);
      close(position(), 2, 'Paused seek lands at two seconds');
      assert(q('.kml-play').dataset.state === 'play', 'Seek does not autoplay');
      h.audio.length = 0;
      await play();
      assert(h.audio.filter(record => record.mode === 'piano').length >= 2,
        'Both held string and released but sustained piano notes resume');
      h.advance(0.5);
      q('.kml-play').click();
      h.frame();
      close(position(), 2.5, 'Pause captures current position');
      h.advance(1);
      close(position(), 2.5, 'Paused position remains fixed');
    });

    await test('Tempo changes use position at the previous tempo', async () => {
      stop();
      input(q('.kml-tempo'), 100);
      seek(4);
      await play();
      h.advance(2);
      close(position(), 6, 'Position before tempo adjustment');
      input(q('.kml-tempo'), 160);
      close(position(), 6, 'Tempo change preserves position immediately');
      await h.wait(200);
      await settle();
      h.advance(0.5);
      close(position(), 6.8, 'New tempo changes subsequent elapsed time');
      stop();
      input(q('.kml-tempo'), 100);
    });

    await test('Playing seek cancels old voices and continues at the target', async () => {
      seek(0);
      h.audio.length = 0;
      await play();
      const oldVoices = h.audio.slice();
      h.advance(0.2);
      seek(7);
      await settle();
      close(position(), 7, 'Playing seek lands at target');
      assert(q('.kml-play').dataset.state === 'pause', 'Playback continues after seek');
      assert(oldVoices.filter(record => record.mode === 'piano').every(record => record.disposed || record.stoppedAt !== undefined),
        'Previously scheduled piano voices were canceled');
      assert(oldVoices.every(record => record.source.fadeOut === 0 && record.stoppedAt === h.now()),
        'Canceled voices replace long release timers with an immediate stop');
      h.advance(0.2);
      close(position(), 7.2, 'Playback advances from seek target');
      stop();
    });

    await test('Natural completion preserves release tails and Play replays from the beginning', async () => {
      seek(11.5);
      h.audio.length = 0;
      await play();
      assert(h.intervalCount() === 1, 'Playback has one scheduler');
      const finalVoice = h.audio[0];
      assert(finalVoice && finalVoice.mode === 'piano', 'Last note is scheduled');
      h.advance(0.6);
      close(position(), 12, 'Natural completion holds the final position');
      assert(q('.kml-play').dataset.state === 'play', 'Completed piece is ready to replay');
      assert(h.intervalCount() === 0, 'Completion clears its scheduler');
      assert(!finalVoice.disposed && finalVoice.stoppedAt === undefined,
        'Completion allows the already scheduled release tail to finish');
      await play();
      close(position(), 0, 'Replay restarts at the beginning');
      assert(h.intervalCount() === 1, 'Replay creates exactly one scheduler');
      assert(finalVoice.disposed, 'Replay clears any remaining voice from the previous run');
      stop();
    });

    await test('Seeking to the end leaves no idle scheduler', async () => {
      await play();
      assert(h.intervalCount() === 1, 'Playing starts one scheduler');
      seek(Number(q('.kml-seek').max));
      assert(q('.kml-play').dataset.state === 'play', 'Seek to end finishes playback');
      assert(h.intervalCount() === 0, 'Seek to end creates no lingering interval');
      h.advance(0.5);
      close(position(), 12, 'End position remains stable');
      await play();
      assert(h.intervalCount() === 1, 'Replay after seeking to end has one scheduler');
      stop();
    });

    await test('Stop cancels an asynchronous Play request', async () => {
      const originalStart = Tone.start;
      let resumeAudio;
      Tone.start = () => new Promise(resolve => { resumeAudio = resolve; });
      h.audio.length = 0;
      q('.kml-play').click();
      assert(q('.kml-play').dataset.state === 'loading', 'Audio startup is pending');
      stop();
      resumeAudio();
      await settle();
      Tone.start = originalStart;
      assert(q('.kml-play').dataset.state === 'play', 'Late audio startup cannot restart playback');
      assert(h.audio.length === 0, 'Canceled Play schedules no notes');
      close(position(), 0, 'Stop returns to the beginning');
    });

    await test('Piano loops retain note releases and schedule the next pass before B', async () => {
      seek(2);
      q('.kml-loop-a').click();
      seek(5);
      q('.kml-loop-b').click();
      const toggle = q('.kml-loop-toggle');
      assert(!toggle.disabled, 'Valid A–B range can be enabled');
      if (!toggle.checked) toggle.click();
      seek(4.9);
      h.audio.length = 0;
      const wallStart = h.now();
      await play();
      const crossingNote = h.audio.find(record => record.when > wallStart + 0.02);
      assert(crossingNote, 'Lookahead schedules the note at 4.95 seconds');
      assert(crossingNote.source.options.fadeOut === 0.45, 'Looping preserves normal piano note releases');
      const boundary = wallStart + 0.1;
      const envelope = channelGate(crossingNote).destinations[0].gain;
      close(envelope.valueAt(boundary - 0.004), 0.5, 'Pass output fades smoothly before B', 0.005);
      close(envelope.valueAt(boundary), 0, 'Old pass is silent at B', 0.005);
      const upcoming = h.audio.filter(record => Math.abs(record.when - boundary) < 0.00001);
      assert(upcoming.length >= 2, 'Next pass notes are queued before the audio clock reaches B');
      const nextEnvelope = channelGate(upcoming[0]).destinations[0].gain;
      close(nextEnvelope.valueAt(boundary), 0, 'Next pass begins without an abrupt gain jump', 0.005);
      close(nextEnvelope.valueAt(boundary + 0.004), 0.5, 'Next pass fades in on the audio clock', 0.005);
      const end = Number.isFinite(crossingNote.stoppedAt)
        ? crossingNote.stoppedAt : crossingNote.when + crossingNote.duration;
      assert(end <= wallStart + 0.101, 'Audio from before B ends at B');
      h.advance(0.3);
      assert(position() >= 2 && position() < 2.3, 'Loop wraps to A and keeps playing');
      assert(q('.kml-play').dataset.state === 'pause', 'Loop remains playing');
      assert(h.intervalCount() === 1, 'Loop wrap retains exactly one scheduler');
      assert(upcoming.every(record => !record.disposed), 'Wrapping retains the already queued next pass');
      stop();
      q('.kml-loop-clear').click();
      assert(!toggle.checked, 'Clear disables looping');
    });

    await test('Soundfont seeks also restore sustained notes', async () => {
      await mode('soundfont');
      seek(2);
      h.audio.length = 0;
      await play();
      assert(h.audio.some(record => record.mode === 'soundfont' && record.note === 'C4'),
        'Sustain tail resumes in file instrument mode');
      stop();
    });

    await test('Instrument loops retain natural releases with a separate boundary fade', async () => {
      seek(2);
      q('.kml-loop-a').click();
      seek(5);
      q('.kml-loop-b').click();
      seek(4.9);
      h.audio.length = 0;
      const wallStart = h.now();
      await play();
      const crossingNote = h.audio.find(record => record.mode === 'soundfont' && record.note === 'E4');
      assert(crossingNote, 'Instrument note before B is scheduled');
      assert(crossingNote.adsr && crossingNote.adsr[3] === 0.1, 'Instrument notes keep their natural release');
      const envelope = channelGate(crossingNote).destinations[0].gain;
      close(envelope.valueAt(wallStart + 0.096), 0.5, 'Instrument output fades before B', 0.005);
      close(envelope.valueAt(wallStart + 0.1), 0, 'Instrument pass is silent at B', 0.005);
      assert(h.audio.some(record => Math.abs(record.when - wallStart - 0.1) < 0.00001),
        'Next instrument pass is queued at B before the timer wraps');
      assert(crossingNote.when + crossingNote.duration <= wallStart + 0.101, 'Instrument note ends at B');
      const scheduled = h.audio.slice();
      seek(3);
      assert(scheduled.every(record => record.disconnected), 'Seek disconnects active and future instrument voices');
      stop();
      q('.kml-loop-clear').click();
    });

    await test('A and B draw labeled vertical markers when set, even with looping off', async () => {
      const canvas = q('.kml-canvas');
      const ctx = canvas.getContext('2d');
      input(q('.kml-zoom'), 30);
      const coloredPixels = (time, color) => {
        const zoom = Number(q('.kml-zoom').value);
        const left = Math.max(0, position() - canvas.width / (2 * zoom));
        const x = Math.round((time - left) * zoom);
        const data = ctx.getImageData(x, 30, 1, canvas.height - 30).data;
        let count = 0;
        for (let i = 0; i < data.length; i += 4) {
          if (color.every((value, index) => data[i + index] === value)) count++;
        }
        return count;
      };
      seek(2);
      q('.kml-loop-a').click();
      h.frame();
      assert(coloredPixels(2, [8, 127, 91]) > 50, 'A appears as a vertical line immediately');
      seek(5);
      q('.kml-loop-b').click();
      h.frame();
      assert(coloredPixels(5, [124, 58, 237]) > 50, 'B appears as a vertical line immediately');
      q('.kml-loop-toggle').click();
      h.frame();
      assert(coloredPixels(2, [8, 127, 91]) > 50 && coloredPixels(5, [124, 58, 237]) > 50,
        'Both marks stay visible with looping disabled');
      input(q('.kml-zoom'), 50);
      assert(coloredPixels(2, [8, 127, 91]) > 50 && coloredPixels(5, [124, 58, 237]) > 50,
        'Markers track the musical positions after zooming');
      q('.kml-loop-clear').click();
      h.frame();
      assert(coloredPixels(2, [8, 127, 91]) === 0 && coloredPixels(5, [124, 58, 237]) === 0,
        'Clearing the loop removes both marker lines');
      input(q('.kml-zoom'), 90);
    });

    await test('Short fast loops queue successive passes and keep position when disabled', async () => {
      await mode('piano');
      seek(2);
      q('.kml-loop-a').click();
      seek(2.2);
      q('.kml-loop-b').click();
      input(q('.kml-tempo'), 160);
      h.audio.length = 0;
      const start = h.now();
      await play();
      h.advance(0.39);
      const starts = h.audio.map(record => record.when);
      for (const offset of [0.125, 0.25, 0.375]) {
        assert(starts.some(time => Math.abs(time - start - offset) < 0.00001),
          'Repeated pass is scheduled exactly at its audio-clock boundary');
      }
      const at = position();
      q('.kml-loop-toggle').click();
      h.frame();
      close(position(), at, 'Disabling a repeated loop keeps the displayed musical position', 0.01);
      assert(h.intervalCount() === 1, 'Changing loop mode leaves one scheduler');
      h.advance(0.1);
      close(position(), at + 0.16, 'Playback continues normally from that position', 0.01);
      stop();
      q('.kml-loop-clear').click();
      input(q('.kml-tempo'), 100);
    });

    await test('Sostenuto holds only notes already down on its channel', async () => {
      const midi = new Midi();
      midi.header.name = 'Sostenuto passage';
      const first = midi.addTrack();
      first.channel = 0;
      first.addNote({ midi: 60, time: 0, duration: 1, velocity: 0.8 });
      first.addNote({ midi: 64, time: 1.5, duration: 0.2, velocity: 0.8 });
      first.addCC({ number: 66, time: 0.5, value: 1 });
      first.addCC({ number: 66, time: 3, value: 0 });
      const second = midi.addTrack();
      second.channel = 1;
      second.addNote({ midi: 48, time: 0, duration: 1, velocity: 0.8 });
      await roll.kmlLoadFile(new File([midi.toArray()], 'sostenuto.mid'));
      await mode('soundfont');
      seek(2);
      h.audio.length = 0;
      await play();
      const sounded = h.audio.filter(record => record.mode === 'soundfont');
      assert(sounded.length === 1 && sounded[0].note === 'C4', 'Only captured channel-zero note remains held');
      close(sounded[0].duration, 1, 'Sostenuto releases at pedal up');
      stop();
    });

    for (const sound of ['piano', 'soundfont']) {
      await test(sound + ': soft pedal smoothly lowers held notes by 20% on its own channel', async () => {
        await roll.kmlLoadFile(softPedalFile());
        await mode(sound);
        input(q('.kml-tempo'), 100);
        h.audio.length = 0;
        const start = h.now();
        await play();
        const voices = h.audio.filter(record => record.mode === sound);
        assert(voices.length === 2, 'Both channels sound before the pedal');
        const affected = channelGate(voices[0]);
        const unchanged = channelGate(voices[1]);
        assert(affected !== unchanged, 'Identical instruments on different channels have independent gain');
        const gain = affected.gain;
        close(gain.valueAt(start + 0.99), 1, 'Held note starts at full volume', 0.005);
        close(gain.valueAt(start + 1), 1, 'Pedal down starts without a gain discontinuity', 0.005);
        close(gain.valueAt(start + 1.06), 0.9, 'Pedal down ramps fluidly through its midpoint', 0.005);
        close(gain.valueAt(start + 1.2), 0.8, 'Soft pedal settles at 80% volume', 0.005);
        close(gain.valueAt(start + 3), 0.8, 'Pedal release starts from the current volume', 0.005);
        close(gain.valueAt(start + 3.06), 0.9, 'Pedal release smoothly restores volume', 0.005);
        close(gain.valueAt(start + 3.2), 1, 'Pedal release restores full volume', 0.005);
        close(unchanged.gain.valueAt(start + 2), 1, 'Other channel remains at full volume', 0.005);
        assert(gain.events.some(event => event.kind === 'linear' && event.value === 0.8),
          'Attenuation uses WebAudio linear gain automation');
        h.advance(3.3);
        assert(h.audio.length === 2, 'Pedal changes affect existing voices without retriggering notes');
        stop();
      });

      await test(sound + ': seek restores the exact soft-pedal ramp and cancels old automation', async () => {
        h.audio.length = 0;
        seek(0);
        await play();
        const oldGate = channelGate(h.audio[0]);
        seek(1.06);
        await settle();
        const resumed = h.audio[h.audio.length - 2];
        const newGate = channelGate(resumed);
        assert(newGate !== oldGate, 'Seek replaces the previous gain route');
        assert(oldGate.disposed || oldGate.destinations.length === 0, 'Seek disconnects the old gain route');
        close(newGate.gain.valueAt(h.now()), 0.9, 'Seeking inside ramp restores its current value', 0.005);
        close(newGate.gain.valueAt(h.now() + 0.06), 0.8, 'Seek completes only the remaining ramp', 0.005);
        seek(2);
        await settle();
        const heldGate = channelGate(h.audio[h.audio.length - 2]);
        close(heldGate.gain.valueAt(h.now()), 0.8, 'Seek into depressed pedal resumes softly', 0.005);
        seek(0.5);
        await settle();
        const resetGate = channelGate(h.audio[h.audio.length - 2]);
        close(resetGate.gain.valueAt(h.now()), 1, 'Seek before the pedal restores full volume', 0.005);
        stop();
      });

      await test(sound + ': tempo scales pedal timing and A–B loop restores pedal state at A', async () => {
        input(q('.kml-tempo'), 50);
        seek(0);
        h.audio.length = 0;
        const start = h.now();
        await play();
        const gain = channelGate(h.audio[0]).gain;
        close(gain.valueAt(start + 2), 1, 'Half tempo delays pedal down to two wall seconds', 0.005);
        close(gain.valueAt(start + 2.12), 0.9, 'Half tempo scales the smoothing interval', 0.005);
        close(gain.valueAt(start + 2.24), 0.8, 'Half tempo completes the fluid drop', 0.005);
        stop();
        input(q('.kml-tempo'), 100);
        seek(0.5);
        q('.kml-loop-a').click();
        seek(2);
        q('.kml-loop-b').click();
        seek(1.9);
        h.audio.length = 0;
        await play();
        const previousGate = channelGate(h.audio[0]);
        close(previousGate.gain.valueAt(h.now()), 0.8, 'Loop begins with pedal down before B', 0.005);
        h.advance(0.15);
        const wrappedGate = channelGate(h.audio[h.audio.length - 2]);
        assert(wrappedGate !== previousGate, 'Loop wrap creates a fresh gain route');
        close(wrappedGate.gain.valueAt(h.now()), 1, 'Wrap restores released pedal at A', 0.005);
        stop();
        q('.kml-loop-clear').click();
      });
    }

    await test('Rapid soft-pedal reversal starts from the in-flight volume', async () => {
      await roll.kmlLoadFile(softPedalFile([[1, 1], [1.06, 0]]));
      await mode('piano');
      h.audio.length = 0;
      const start = h.now();
      await play();
      const gain = channelGate(h.audio[0]).gain;
      close(gain.valueAt(start + 1.06), 0.9, 'Early release keeps the partially reduced volume', 0.008);
      close(gain.valueAt(start + 1.12), 0.95, 'Reversal ramps smoothly back up', 0.008);
      close(gain.valueAt(start + 1.2), 1, 'Rapid release reaches full volume', 0.005);
      stop();
    });

    await test('Sound loading failures remain visible after animation frames', async () => {
      await mode('piano');
      const originalStart = Tone.start;
      Tone.start = async () => { throw new Error('Audio startup failed'); };
      q('.kml-play').click();
      await settle();
      Tone.start = originalStart;
      const message = q('.kml-status').textContent;
      assert(q('.kml-status').dataset.kind === 'error', 'Playback error is marked as an error');
      h.advance(1);
      assert(q('.kml-status').textContent === message, 'Playback error persists');
      assert(!q('.kml-play').disabled, 'Visitor can retry Play');
    });

    await test('Invalid files report persistent errors and retain the previous MIDI', async () => {
      const previousTitle = title();
      const result = await roll.kmlLoadFile(new File(['not a MIDI file'], 'broken.mid'));
      await settle();
      assert(result === false, 'Invalid parse reports failure');
      const message = q('.kml-status').textContent;
      assert(message.length > 0, 'Error is visible');
      h.advance(2);
      assert(q('.kml-status').textContent === message, 'Animation frames do not erase errors');
      assert(title() === previousTitle, 'Previous playable file remains available');
    });

    await test('Archive files with instantaneous note-off pairs still load', async () => {
      const midi = new Midi();
      midi.header.name = 'Short percussion notes';
      const track = midi.addTrack();
      track.channel = 9;
      track.addNote({ midi: 36, time: 0, duration: 0, velocity: 0.8 });
      track.addNote({ midi: 42, time: 1, duration: 0.2, velocity: 0.6 });
      const loaded = await roll.kmlLoadFile(new File([midi.toArray()], 'short-drums.mid'));
      assert(loaded, 'A playable file is not rejected for a zero-length note');
      assert(q('.kml-file-details').textContent.includes('2 notes'), 'Both percussion hits remain in the preview');
    });

    await test('A slower earlier file read cannot replace a newer selection', async () => {
      const slow = file('Stale file');
      let resolveRead;
      slow.arrayBuffer = () => new Promise(resolve => { resolveRead = resolve; });
      const pending = roll.kmlLoadFile(slow);
      await roll.kmlLoadFile(file('Latest file'));
      resolveRead(h.fixture('Stale file').buffer);
      await pending;
      await settle();
      assert(title().includes('Latest file'), 'Newest selected file wins');
      assert(h.requests.length === 0, 'All local file operations stayed off the network');
    });

    await test('A slow archive response cannot replace a newer local file', async () => {
      const originalFetch = window.fetch;
      let respond;
      window.fetch = async (url, options) => {
        h.requests.push({ url, options });
        return await new Promise(resolve => { respond = resolve; });
      };
      const pending = roll.kmlLoadUrl('slow-archive.mid', 'Stale archive title');
      await roll.kmlLoadFile(file('Local file wins'));
      respond({ ok: true, headers: new Headers(), arrayBuffer: async () => h.fixture('Stale archive').buffer });
      await pending;
      window.fetch = originalFetch;
      await settle();
      assert(title().includes('Local file wins'), 'Late archive fetch cannot overwrite the local selection');
      assert(h.requests[0].options.signal.aborted, 'Replacing an archive load aborts its fetch');
    });

    await test('Dropping several files reports an error without replacing the piece', async () => {
      const previousTitle = title();
      const transfer = new DataTransfer();
      transfer.items.add(file('First drop'));
      transfer.items.add(file('Second drop'));
      document.querySelector('.kml-midi-player-tool').dispatchEvent(new DragEvent('drop', {
        bubbles: true, cancelable: true, dataTransfer: transfer,
      }));
      await settle();
      const message = document.querySelector('.kml-file-message');
      assert(!message.hidden && message.textContent.includes('one MIDI file'), 'Multiple-file error is visible');
      assert(title() === previousTitle, 'Multiple-file drop retains the current piece');
    });

    await test('Try a demo loads a performance and waits for Play', async () => {
      const before = h.requests.length;
      document.querySelector('.kml-demo').click();
      await settle();
      assert(title().includes('Archive demo'), 'Demo button loads the selected performance');
      assert(h.requests.length === before + 1 && h.requests[before].url.endsWith('demo.mid'), 'Only demo MIDI was fetched');
      assert(q('.kml-play').dataset.state === 'play', 'Demo waits for visitor to press Play');
      assert(!q('.kml-play').disabled, 'Demo is playable');
    });

    await test('Player controls fit the viewport', async () => {
      const wrapper = document.querySelector('.kml-midi-player-tool');
      assert(wrapper.scrollWidth <= wrapper.clientWidth + 1, 'Player has no horizontal overflow');
    });

    output.textContent = results.join('\n') + '\n\n' + (failures ? failures + ' failed' : results.length + ' passed');
    document.body.dataset.testResult = failures ? 'failed' : 'passed';
  });
})();
