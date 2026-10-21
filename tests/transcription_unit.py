import importlib.util
from pathlib import Path
import tempfile
import unittest

path=Path(__file__).resolve().parent.parent/'tools'/'transcription_worker.py'
spec=importlib.util.spec_from_file_location('worker',path)
worker=importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)

class TranscriptionBoundaries(unittest.TestCase):
    def test_timestamp_and_unicode_captions(self):
        self.assertEqual(worker.timestamp(3723.456),'01:02:03.456')
        text=worker.make_vtt([{'start':0,'end':3.123,'text':'सुरक्षित डेटा & recovery <example>'}],4)
        self.assertIn('00:00:00.000 --> 00:00:03.123',text)
        self.assertIn('सुरक्षित डेटा &amp; recovery &lt;example&gt;',text)

    def test_no_speech_and_invalid_timestamps_are_rejected(self):
        for segments in [[],[{'start':5,'end':2,'text':'Speech'}],[{'start':0,'end':9,'text':'Speech'}],[{'start':float('nan'),'end':2,'text':'Speech'}]]:
            with self.assertRaises(worker.WorkerError):worker.make_vtt(segments,6)

    def test_never_silently_truncates_long_transcripts(self):
        with self.assertRaises(worker.WorkerError):worker.make_vtt([{'start':0,'end':2,'text':'a'*120001}],3)

    def test_source_stays_in_private_media_directory(self):
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary)/'videos';root.mkdir()
            outside=Path(temporary)/'outside.mp4';outside.write_bytes(b'fixture')
            with self.assertRaises(worker.WorkerError):worker.source_file({'videoRoot':str(root),'file':str(outside)})

if __name__=='__main__':unittest.main()
