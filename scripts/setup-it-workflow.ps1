param([ValidateSet('development','offline')][string]$Environment='offline')
$ErrorActionPreference='Stop'
$workspace=(Resolve-Path "$PSScriptRoot/..").Path
$privateDirectory=Join-Path $workspace "secrets/$Environment"
New-Item -ItemType Directory -Force -Path $privateDirectory | Out-Null
$passwordFile=Join-Path $privateDirectory 'it_passwords.json'
if(!(Test-Path -LiteralPath $passwordFile)){
    $passwords=[ordered]@{}
    $structure=Get-Content -LiteralPath (Join-Path $workspace 'backend/resources/jsc-structure.json') -Raw | ConvertFrom-Json
    $names=@('it.head','it.deputy','it.helpdesk','it.systems_engineer','it.systems_analyst','it.hardware_specialist','it.database_specialist','secretary','deputy.secretary')
    foreach($province in $structure.provinces){$names+='it.principal.'+$province.ToLowerInvariant().Replace(' ','_')}
    foreach($station in $structure.stations){$names+='it.officer.'+$station[0].ToLowerInvariant()}
    foreach($username in ($names | Select-Object -Unique)){if(!$passwords.Contains($username)){$bytes=New-Object byte[] 24;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($bytes)}finally{$rng.Dispose()};$passwords[$username]=[Convert]::ToBase64String($bytes)}}
    $stream=[IO.File]::Open($passwordFile,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    try{$content=[Text.Encoding]::UTF8.GetBytes(($passwords | ConvertTo-Json));$stream.Write($content,0,$content.Length)}finally{$stream.Dispose()}
}
$arguments=@('compose','--project-directory',$workspace,'-p',"jsc-ticketing-$Environment",'--env-file',(Join-Path $workspace ".env.$Environment"),'-f',(Join-Path $workspace 'deploy/compose.environments.yml'))
if($Environment -eq 'offline'){$arguments+=@('-f',(Join-Path $workspace 'deploy/compose.offline.yml'))}
docker @arguments --profile tools run --rm --no-deps --env IT_PASSWORDS_FILE=/run/secrets/it_passwords --volume "${passwordFile}:/run/secrets/it_passwords:ro" migrate php bin/it-workflow.php
if($LASTEXITCODE -ne 0){throw 'IT workflow installation failed.'}
Write-Output "Credentials: $passwordFile (private; existing account passwords preserved)."
