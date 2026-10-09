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

    await test('A–B loop clips lookahead notes and wraps at B', async () => {
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
      assert(crossingNote.source.options.fadeOut === 0, 'Piano release cannot spill beyond B');
      const end = Number.isFinite(crossingNote.stoppedAt)
        ? crossingNote.stoppedAt : crossingNote.when + crossingNote.duration;
      assert(end <= wallStart + 0.101, 'Audio from before B ends at B');
      h.advance(0.3);
      assert(position() >= 2 && position() < 2.3, 'Loop wraps to A and keeps playing');
      assert(q('.kml-play').dataset.state === 'pause', 'Loop remains playing');
      assert(h.intervalCount() === 1, 'Loop wrap retains exactly one scheduler');
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

    await test('Instrument loops use zero release and disconnect canceled voices', async () => {
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
      assert(crossingNote.adsr && crossingNote.adsr[3] === 0, 'Instrument loop has an explicit zero release');
      assert(crossingNote.when + crossingNote.duration <= wallStart + 0.101, 'Instrument note ends at B');
      const scheduled = h.audio.slice();
      seek(3);
      assert(scheduled.every(record => record.disconnected), 'Seek disconnects active and future instrument voices');
      stop();
      q('.kml-loop-clear').click();
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
