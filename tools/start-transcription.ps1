param([string]$PhpBin='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
$taskProject=Split-Path -Parent $PSScriptRoot
$taskRuntime=Join-Path $env:USERPROFILE '.webinar-transcriber'
$taskPython=Join-Path $taskRuntime 'venv\Scripts\python.exe'
if(-not(Test-Path -LiteralPath $taskPython)){throw 'Run tools/setup-transcription.ps1 first to install faster-whisper and the local model.'}
if(-not(Test-Path -LiteralPath $PhpBin)){throw 'Set -PhpBin to your XAMPP PHP executable.'}
& $PhpBin (Join-Path $PSScriptRoot 'migrate-transcription.php')
if($LASTEXITCODE -ne 0){throw 'Start XAMPP MySQL and check the database configuration.'}
$taskArguments=@(('"'+(Join-Path $PSScriptRoot 'transcription_worker.py')+'"'),'--app-root',('"'+$taskProject+'"'),'--php-bin',('"'+$PhpBin+'"'),'--runtime',('"'+$taskRuntime+'"'))
Start-Process -FilePath $taskPython -ArgumentList $taskArguments -WindowStyle Hidden | Out-Null
Write-Output 'Local transcription worker started. Its status is shown in the transcript manager.'
