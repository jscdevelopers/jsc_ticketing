param([ValidateSet('development','offline')][string]$Environment='offline')
$ErrorActionPreference='Stop'
$workspace=(Resolve-Path "$PSScriptRoot/..").Path
$privateDirectory=Join-Path $workspace "secrets/$Environment"
New-Item -ItemType Directory -Force -Path $privateDirectory | Out-Null
$passwordFile=Join-Path $privateDirectory 'jsc_passwords.json'
if(!(Test-Path -LiteralPath $passwordFile)){
    $passwords=[ordered]@{}
    $aliases=@{'requester.demo'='registry.harare';'agent.demo'='ict.harare';'manager.demo'='station.manager';'designer.demo'='workflow.designer';'publisher.demo'='workflow.publisher'}
    $legacy=Join-Path $privateDirectory 'demo_passwords.json'
    if(Test-Path -LiteralPath $legacy){$initial=Get-Content -LiteralPath $legacy -Raw | ConvertFrom-Json;foreach($old in $aliases.Keys){if($initial.$old){$passwords[$aliases[$old]]=$initial.$old}}}
    $structure=Get-Content -LiteralPath (Join-Path $workspace 'backend/resources/jsc-structure.json') -Raw | ConvertFrom-Json
    $names=@('registry.harare','ict.harare','station.manager','workflow.designer','workflow.publisher','national.oversight','configuration.admin')
    foreach($station in $structure.stations){$names+='registry.'+$station[0].ToLowerInvariant()}
    foreach($category in $structure.categories){$names+='officer.'+$category[2].ToLowerInvariant()}
    foreach($username in ($names | Select-Object -Unique)){if(!$passwords.Contains($username)){$bytes=New-Object byte[] 24;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($bytes)}finally{$rng.Dispose()};$passwords[$username]=[Convert]::ToBase64String($bytes)}}
    $stream=[IO.File]::Open($passwordFile,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    try{$content=[Text.Encoding]::UTF8.GetBytes(($passwords | ConvertTo-Json));$stream.Write($content,0,$content.Length)}finally{$stream.Dispose()}
}
$arguments=@('compose','--project-directory',$workspace,'-p',"jsc-ticketing-$Environment",'--env-file',(Join-Path $workspace ".env.$Environment"),'-f',(Join-Path $workspace 'deploy/compose.environments.yml'))
if($Environment -eq 'offline'){$arguments+=@('-f',(Join-Path $workspace 'deploy/compose.offline.yml'))}
docker @arguments --profile tools run --rm --no-deps --env JSC_PASSWORDS_FILE=/run/secrets/jsc_passwords --volume "${passwordFile}:/run/secrets/jsc_passwords:ro" migrate php bin/jsc-samples.php
if($LASTEXITCODE -ne 0){throw 'JSC sample installation failed.'}
Write-Output "Credentials: $passwordFile (private; existing account passwords preserved)."
