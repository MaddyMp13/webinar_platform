param([ValidateSet('tiny','base','small','medium','large-v3','turbo')][string]$Model='base')
$ErrorActionPreference='Stop'
$taskProject=Split-Path -Parent $PSScriptRoot
$taskRuntime=Join-Path $env:USERPROFILE '.webinar-transcriber'
$taskPython=Join-Path $taskRuntime 'venv\Scripts\python.exe'
if(-not(Test-Path -LiteralPath $taskPython)) {
    New-Item -ItemType Directory -Path $taskRuntime -Force | Out-Null
    python -m venv (Join-Path $taskRuntime 'venv')
    if($LASTEXITCODE -ne 0){throw 'Install Python 3.9 or newer, then retry setup.'}
}
& $taskPython -m pip install --disable-pip-version-check -r (Join-Path $PSScriptRoot 'transcription-requirements.txt')
if($LASTEXITCODE -ne 0){throw 'Could not install faster-whisper. Check the Python/pip output.'}
& $taskPython (Join-Path $PSScriptRoot 'transcription_worker.py') --runtime $taskRuntime --prepare-model $Model
if($LASTEXITCODE -ne 0){throw 'Model preparation failed. Check your internet connection and retry.'}
& (Join-Path $PSScriptRoot 'start-transcription.ps1')
