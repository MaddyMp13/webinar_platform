"""Local CPU speech worker. PHP owns authentication, media selection and MySQL writes."""
from __future__ import annotations

import argparse
import hashlib
import importlib.metadata
import json
import logging
import math
import os
from pathlib import Path
import subprocess
import threading
import time
import uuid

LOG = logging.getLogger("webinar-transcription")
MODELS = ("tiny", "base", "small", "medium", "large-v3", "turbo")


class WorkerError(Exception):
    pass


class BridgeError(WorkerError):
    pass


def timestamp(seconds: float) -> str:
    value = max(0, round(seconds * 1000))
    hours, value = divmod(value, 3600000)
    minutes, value = divmod(value, 60000)
    whole, millis = divmod(value, 1000)
    return f"{hours:02}:{minutes:02}:{whole:02}.{millis:03}"


def source_file(job: dict) -> Path:
    root = Path(job["videoRoot"]).resolve(strict=True)
    file = Path(job["file"]).resolve(strict=True)
    if not file.is_relative_to(root) or not file.is_file():
        raise WorkerError("Recording is outside the configured private videos folder.")
    stat = file.stat()
    if stat.st_size != job["sourceSize"] or int(stat.st_mtime) != job["sourceMtime"]:
        raise WorkerError("The video changed after queueing. Queue the current recording again.")
    return file


def file_hash(file: Path, cancelled: threading.Event) -> str:
    digest = hashlib.sha256()
    with file.open("rb") as stream:
        while piece := stream.read(4 * 1024 * 1024):
            if cancelled.is_set():
                raise WorkerError("Transcription cancelled.")
            digest.update(piece)
    return digest.hexdigest()


def make_vtt(segments: list[dict], duration: float) -> str:
    cues = []
    previous_start = -1.0
    for segment in segments:
        start, end = float(segment["start"]), float(segment["end"])
        text = " ".join(str(segment["text"]).split()).replace("-->", "→")
        if not math.isfinite(start) or not math.isfinite(end) or start < 0 or end <= start or end > duration + 1 or start < previous_start:
            raise WorkerError("The speech model returned invalid timestamps.")
        if not text:
            continue
        previous_start = start
        # Prevent caption markup interpretation from changing recognized speech.
        text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
        cues.append(f"{timestamp(start)} --> {timestamp(end)}\n{text}")
    if not cues:
        raise WorkerError("No confident speech was detected. The existing transcript was retained.")
    result = "WEBVTT\n\n" + "\n\n".join(cues) + "\n"
    if len(result) < 20 or len(result) > 120000:
        raise WorkerError("Transcript exceeds the import limit. Split the recording into shorter sessions.")
    return result


class Worker:
    def __init__(self, root: Path, php: Path, cache: Path):
        self.root, self.php, self.cache = root.resolve(), php.resolve(), cache.resolve()
        self.id = str(uuid.uuid4())
        self.version = importlib.metadata.version("faster-whisper")
        self.progress_lock = threading.Lock()
        self.progress: dict = {}
        self.cancelled = threading.Event()
        self.done = threading.Event()
        self.models: dict = {}

    def bridge(self, action: str, **payload) -> dict:
        request = {"action": action, "workerId": self.id, **payload}
        process = subprocess.run(
            [str(self.php), str(self.root / "tools" / "transcription-bridge.php")],
            input=json.dumps(request, ensure_ascii=False), encoding="utf-8", capture_output=True,
            timeout=120 if action == "complete" else 20,
            creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
        )
        try:
            response = json.loads(process.stdout.lstrip("\ufeff"))
        except (ValueError, TypeError) as error:
            raise BridgeError("PHP worker bridge is unavailable. Check XAMPP and the database migration.") from error
        if not response.get("ok"):
            raise BridgeError(response.get("message", "The PHP worker bridge failed."))
        return response["result"]

    def heartbeat(self):
        while not self.done.is_set():
            try:
                with self.progress_lock:
                    progress = dict(self.progress)
                response = self.bridge("heartbeat", version=self.version, **progress)
                if progress.get("jobId") and not response.get("active"):
                    with self.progress_lock:
                        if self.progress.get("jobId") == progress["jobId"]:
                            self.cancelled.set()
            except Exception as error:
                LOG.warning("Heartbeat unavailable (%s)", type(error).__name__)
            self.done.wait(10)

    def transcribe(self, job: dict):
        import av
        from faster_whisper import WhisperModel

        if job["model"] not in MODELS:
            raise WorkerError("Configure a supported faster-whisper model.")
        file = source_file(job)
        with av.open(str(file)) as container:
            if not container.streams.audio:
                raise WorkerError("This recording has no audio track. No transcript was created.")
            if container.duration and container.duration / av.time_base > job["maxDuration"]:
                raise WorkerError("Recording exceeds the configured duration limit. Split it into shorter sessions.")
        original_hash = file_hash(file, self.cancelled)
        key = (job["model"], job["cpuThreads"])
        if key not in self.models:
            # Downloads happen explicitly during setup. Transcription itself uses local files only.
            self.models.clear()
            try:
                self.models[key] = WhisperModel(job["model"], device="cpu", compute_type="int8",
                    cpu_threads=job["cpuThreads"], num_workers=1, download_root=str(self.cache), local_files_only=True)
            except Exception as error:
                raise WorkerError("Speech model is not ready. Run tools/setup-transcription.ps1 -Model " + job["model"] + " and retry.") from error
        if self.cancelled.is_set():
            return
        stream, info = self.models[key].transcribe(str(file), beam_size=5,
            language=None if job["language"] == "auto" else job["language"], task="transcribe",
            vad_filter=True, vad_parameters={"min_silence_duration_ms": 500},
            condition_on_previous_text=False, no_speech_threshold=0.6, log_prob_threshold=-1.0,
            compression_ratio_threshold=2.4)
        duration = float(info.duration)
        if not math.isfinite(duration) or not 0 < duration <= job["maxDuration"]:
            raise WorkerError("The audio is empty or exceeds the configured duration limit.")
        with self.progress_lock:
            self.progress.update(phase="transcribing", duration=duration)
        segments, characters = [], 0
        for part in stream:  # Iteration performs the actual inference.
            if self.cancelled.is_set():
                return
            if part.no_speech_prob > 0.6 or part.avg_logprob < -1.0 or part.compression_ratio > 2.4:
                continue
            body = part.text.strip()
            if not body:
                continue
            start, end = max(0.0, float(part.start)), min(duration, float(part.end))
            if end <= start:
                continue
            segments.append({"start": start, "end": end, "text": body})
            characters += len(body) + 45
            if characters > 120000:
                raise WorkerError("Transcript exceeds the import limit. Split the recording into shorter sessions.")
            with self.progress_lock:
                self.progress.update(processedSeconds=end, segments=len(segments))
        vtt = make_vtt(segments, duration)
        if file_hash(source_file(job), self.cancelled) != original_hash:
            raise WorkerError("The recording content changed during transcription. Queue the new recording.")
        if self.cancelled.is_set():
            return
        with self.progress_lock:
            self.progress.update(phase="saving")
        response = self.bridge("complete", jobId=job["id"], lease=job["lease"], vtt=vtt,
            sourceSha256=original_hash, duration=duration, language=info.language,
            languageProbability=float(info.language_probability), segments=len(segments))
        LOG.info("Job %s: %s", job["id"], "completed" if response.get("completed") else "no longer active")

    def run(self, once=False):
        pulse = threading.Thread(target=self.heartbeat, daemon=True)
        pulse.start()
        LOG.info("Local faster-whisper %s worker started", self.version)
        try:
            while True:
                try:
                    job = self.bridge("claim").get("job")
                    if job:
                        self.cancelled.clear()
                        with self.progress_lock:
                            self.progress = {"jobId": job["id"], "lease": job["lease"], "phase": "loading_model", "processedSeconds": 0, "segments": 0}
                        try:
                            self.transcribe(job)
                        except Exception as error:
                            message = str(error) if isinstance(error, WorkerError) else "Local speech recognition failed. Check the worker log, model installation, and recording audio."
                            LOG.exception("Job %s failed (%s): %s", job["id"], type(error).__name__, message)
                            try:
                                self.bridge("fail", jobId=job["id"], lease=job["lease"], message=message)
                            except Exception:
                                LOG.error("Could not record failure; the job will be marked interrupted after its lease expires.")
                        finally:
                            with self.progress_lock:
                                self.progress = {}
                    if once:
                        break
                    self.done.wait(2)
                except Exception as error:
                    LOG.warning("Queue unavailable (%s)", type(error).__name__)
                    if once:
                        raise
                    self.done.wait(5)
        finally:
            self.done.set()
            pulse.join(timeout=25)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--app-root", type=Path, default=Path(__file__).resolve().parent.parent)
    parser.add_argument("--php-bin", type=Path, default=Path("C:/xampp/php/php.exe"))
    parser.add_argument("--runtime", type=Path, default=Path.home() / ".webinar-transcriber")
    parser.add_argument("--prepare-model", choices=MODELS)
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    args.runtime.mkdir(parents=True, exist_ok=True)
    cache = args.runtime / "models"
    if args.prepare_model:
        from faster_whisper import WhisperModel
        WhisperModel(args.prepare_model, device="cpu", compute_type="int8", cpu_threads=2, download_root=str(cache))
        print(f"Local model prepared: {args.prepare_model}")
        return
    log_file = args.runtime / "worker.log"
    logging.basicConfig(filename=str(log_file), level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    # One daemon per project. The DB queue also serializes claims across project copies.
    identity = hashlib.sha256(str(args.app_root.resolve()).casefold().encode()).hexdigest()[:16]
    lock = (args.runtime / ("worker-" + identity + ".lock")).open("a+b")
    if os.name == "nt":
        import msvcrt
        lock.seek(0); lock.write(b"0"); lock.flush(); lock.seek(0)
        try:
            msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
        except OSError:
            print("A transcription worker is already running for this project.")
            return
    try:
        Worker(args.app_root, args.php_bin, cache).run(once=args.once)
    finally:
        lock.close()


if __name__ == "__main__":
    main()
