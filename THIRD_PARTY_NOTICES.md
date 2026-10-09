# Third-Party Notices

The Apache License 2.0 in [`LICENSE`](LICENSE) applies to Kuhmann MIDI Library's original source code. It does not replace the licenses of third-party components.

## Musical demo

`public/assets/demo-the-man-that-got-away.mid` is the complete original **The Man That Got Away** MIDI from the user's Kuhmann / Disklavier World archive, `Disklavier Jazz 3/266-The Man That Got Away.mid`.

- Original and bundled SHA-256: `e693d173f1e509876f11bdb847cbecb291637ee9ce66b2abddaa7be30a727d6b` (13,738 bytes).
- Duration: about 3 minutes 9 seconds (188.52 seconds through the final note; 189.56 seconds through the file's end).
- Parts: acoustic grand piano and acoustic bass on separate MIDI channels, with 1,620 piano notes and 299 bass notes.
- The bundled file is byte-for-byte identical to the source. All performance events, the original title, and attribution metadata are retained.

The composition and MIDI performance are third-party musical material and are not covered by the plugin's Apache License. The archive supplies no separate license for this file. This performance is included as the user's requested archive demo, with its existing attribution retained.

To copy and verify the original archive file, run `python3 scripts/build-demo.py /path/to/original.mid`. The script uses Python's standard library and rejects any source whose hash does not match.

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
