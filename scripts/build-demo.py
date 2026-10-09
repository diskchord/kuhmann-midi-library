#!/usr/bin/env python3
"""Reproduce the bundled Cloud Whisper excerpt from the user's archive source.

Usage: python3 scripts/build-demo.py /path/to/original.mid
Requires mido. The source is intentionally kept outside the plugin repository.
"""

from collections import Counter
import hashlib
from pathlib import Path
import sys

import mido


SOURCE_SHA256 = "62931e0098da4bc1e76be42b89252d447ac3c9fcb520306d38387ac125a27875"
START_TICK = 1920
END_TICK = 32640


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 scripts/build-demo.py /path/to/original.mid")
    source = Path(sys.argv[1])
    if hashlib.sha256(source.read_bytes()).hexdigest() != SOURCE_SHA256:
        raise SystemExit("Source does not match the archived Cloud Whisper MIDI.")
    original = mido.MidiFile(source)
    if original.type != 0 or original.ticks_per_beat != 480:
        raise SystemExit("Unexpected source MIDI format.")

    excerpt = mido.MidiFile(type=0, ticks_per_beat=original.ticks_per_beat)
    track = mido.MidiTrack()
    excerpt.tracks.append(track)
    active = Counter()
    channels = set()
    tick = previous = 0
    for message in original.tracks[0]:
        tick += message.time
        if tick >= END_TICK:
            break
        if message.type == "end_of_track":
            continue
        position = max(0, tick - START_TICK)
        event = message.copy(time=position - previous)
        if event.type == "track_name":
            event.name = "Cloud Whisper - Bennie Gunn (demo excerpt)"
        track.append(event)
        previous = position
        if message.type == "note_on" and message.velocity:
            channels.add(message.channel)
            active[(message.channel, message.note)] += 1
        elif message.type == "note_off" or (message.type == "note_on" and not message.velocity):
            key = (message.channel, message.note)
            active[key] = max(0, active[key] - 1)

    # End the excerpt cleanly even when a note or pedal spans its boundary.
    remaining = END_TICK - START_TICK - previous
    for (channel, note), count in sorted(active.items()):
        for _ in range(count):
            track.append(mido.Message("note_off", channel=channel, note=note, velocity=0, time=remaining))
            remaining = 0
    for channel in sorted(channels):
        for control in (64, 66, 67):
            track.append(mido.Message("control_change", channel=channel, control=control, value=0, time=remaining))
            remaining = 0
    track.append(mido.MetaMessage("end_of_track", time=remaining))
    output = Path(__file__).resolve().parents[1] / "public/assets/demo-cloud-whisper.mid"
    excerpt.save(output)
    print(f"Wrote {output.name}: {excerpt.length:.1f} seconds, {output.stat().st_size} bytes")


if __name__ == "__main__":
    main()
