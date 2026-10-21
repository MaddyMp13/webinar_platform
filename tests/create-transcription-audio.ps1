param([Parameter(Mandatory=$true)][string]$Destination)
$ErrorActionPreference='Stop'
# Synthetic speech for an isolated QA webinar; never assigned to a real campaign.
$taskVoice=New-Object -ComObject SAPI.SpVoice
$taskStream=New-Object -ComObject SAPI.SpFileStream
$taskStream.Open($Destination,3,$false)
$taskVoice.AudioOutputStream=$taskStream
$taskVoice.Rate=-1
$null=$taskVoice.Speak('Today we discuss data recovery. A recovery plan uses verified backups. Test the restoration procedure before deployment. Keep a second copy of important files.')
$taskStream.Close()
