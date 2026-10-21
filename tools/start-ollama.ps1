param([string]$Model = 'qwen2.5:1.5b')
$ErrorActionPreference = 'Stop'
$taskOllamaCommand = Get-Command ollama -ErrorAction SilentlyContinue
$taskOllamaPath = if ($taskOllamaCommand) { $taskOllamaCommand.Source } else { Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe' }
if (-not (Test-Path -LiteralPath $taskOllamaPath)) { throw 'Install Ollama for Windows from https://ollama.com/download/windows, then run this script again.' }
if ($Model -notmatch '^[A-Za-z0-9_.:/-]{1,100}$' -or $Model -match '(:cloud|-cloud)$') { throw 'Choose a local model name.' }
try { $null = Invoke-RestMethod 'http://127.0.0.1:11434/api/tags' -TimeoutSec 2 }
catch {
    $env:OLLAMA_HOST = '127.0.0.1:11434'
    $env:OLLAMA_NO_CLOUD = '1'
    Start-Process -FilePath $taskOllamaPath -ArgumentList 'serve' -WindowStyle Hidden | Out-Null
    $taskReady = $false
    for ($taskAttempt = 0; $taskAttempt -lt 15; $taskAttempt++) {
        Start-Sleep -Seconds 1
        try { $null = Invoke-RestMethod 'http://127.0.0.1:11434/api/tags' -TimeoutSec 1; $taskReady = $true; break } catch {}
    }
    if (-not $taskReady) { throw 'Ollama did not start. Check its logs in LOCALAPPDATA\Ollama.' }
}
& $taskOllamaPath pull $Model
if ($LASTEXITCODE -ne 0) { throw 'Model download failed. Check your connection and run this script again.' }
Write-Output "Local model ready: $Model. Match this name in config/config.local.php."
