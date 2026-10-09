# Third-Party Notices

The Apache License 2.0 in [`LICENSE`](LICENSE) applies to Kuhmann MIDI Library's original source code. It does not replace the licenses of third-party components.

## Musical demo

`public/assets/demo-cloud-whisper.mid` is a 38.4-second excerpt of **Cloud Whisper (Bennie Gunn)** from the user's Kuhmann / Disklavier World archive, `Bennie Gunn/04-Cloud Whisper (Bennie Gunn).mid`.

- [Original archive entry and full MIDI](https://www.alexanderpeppe.com/midi/04-cloud-whisper-bennie-gunn-ce57643e/)
- Source SHA-256: `62931e0098da4bc1e76be42b89252d447ac3c9fcb520306d38387ac125a27875`
- Excerpt: original ticks 1920–32640 at 480 ticks per quarter note, 100 BPM (the first sixteen musical bars, after the initial silent bar).
- Parts: electric piano, jazz guitar, vibraphone, fretless bass, and drums. Notes, timing, velocity, instrument assignments, controllers, and pitch bends within the excerpt retain the original performance data. Initial setup is moved to the start; sounding notes and pedals are released at the excerpt's end.

The composition and MIDI performance are third-party musical material and are not covered by the plugin's Apache License. The archive supplies no separate license for this file. This excerpt is included as the user's requested archive demo, with its existing attribution retained.

To reproduce the excerpt from the original archive file, run `python3 scripts/build-demo.py /path/to/original.mid` (requires the Python `mido` package).

## Bundled MIDI parser

`public/assets/Midi.js` is a browser bundle of [`@tonejs/midi` 2.0.28](https://github.com/Tonejs/Midi). The bundle includes `midi-file` and `array-flatten`. These components are distributed under the MIT License with the following copyright notices:

- @tonejs/midi: Copyright © 2016 Yotam Mann
- midi-file: Copyright © 2016 Carter Thaxton
- array-flatten: Copyright © 2014 Blake Embrey (hello@blakeembrey.com)

### MIT License

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
