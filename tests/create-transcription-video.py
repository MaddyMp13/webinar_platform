"""Encode a small synthetic QA MP4 from locally generated test speech."""
from fractions import Fraction
from pathlib import Path
import sys
import av
import numpy as np

source, target = map(Path,sys.argv[1:3])
with av.open(str(source)) as audio:
    frames = list(audio.decode(audio=0))
duration = sum(frame.samples / frame.sample_rate for frame in frames)
with av.open(str(target), "w") as output:
    video = output.add_stream("libx264", rate=1)
    video.width, video.height, video.pix_fmt = 160, 90, "yuv420p"
    sound = output.add_stream("aac", rate=44100)
    sound.layout = "mono"
    for second in range(int(duration) + 1):
        frame = av.VideoFrame.from_ndarray(np.zeros((90,160,3),dtype=np.uint8),format="rgb24")
        frame.pts, frame.time_base = second, Fraction(1,1)
        for packet in video.encode(frame): output.mux(packet)
    for packet in video.encode(): output.mux(packet)
    resampler = av.AudioResampler(format="fltp",layout="mono",rate=44100)
    for frame in frames:
        for converted in resampler.resample(frame):
            for packet in sound.encode(converted): output.mux(packet)
    for converted in resampler.resample(None):
        for packet in sound.encode(converted): output.mux(packet)
    for packet in sound.encode(): output.mux(packet)
print(f"Created synthetic speech recording ({duration:.1f}s).")
