param([ValidateSet('development','offline')][string]$Environment='development')
$ErrorActionPreference='Stop'
$workspace=(Resolve-Path "$PSScriptRoot/..").Path
$privateDirectory=Join-Path $workspace "secrets/$Environment"
New-Item -ItemType Directory -Force -Path $privateDirectory | Out-Null
$passwordFile=Join-Path $privateDirectory 'demo_passwords.json'
if(!(Test-Path -LiteralPath $passwordFile)){
    $passwords=[ordered]@{}
    foreach($username in @('requester.demo','agent.demo','manager.demo','designer.demo','publisher.demo')){
        $bytes=New-Object byte[] 24;$rng=[Security.Cryptography.RandomNumberGenerator]::Create()
        try{$rng.GetBytes($bytes)}finally{$rng.Dispose()}
        $passwords[$username]=[Convert]::ToBase64String($bytes)
    }
    $stream=[IO.File]::Open($passwordFile,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    try{$content=[Text.Encoding]::UTF8.GetBytes(($passwords | ConvertTo-Json));$stream.Write($content,0,$content.Length)}finally{$stream.Dispose()}
}
$arguments=@('compose','--project-directory',$workspace,'-p',"jsc-ticketing-$Environment",'--env-file',(Join-Path $workspace ".env.$Environment"),'-f',(Join-Path $workspace 'deploy/compose.environments.yml'))
if($Environment -eq 'offline'){$arguments+=@('-f',(Join-Path $workspace 'deploy/compose.offline.yml'))}
docker @arguments --profile tools run --rm --no-deps --env DEMO_PASSWORDS_FILE=/run/secrets/demo_passwords --volume "${passwordFile}:/run/secrets/demo_passwords:ro" migrate php bin/demo.php
if($LASTEXITCODE -ne 0){throw 'Local demo setup failed.'}
Write-Output "Private initial demo credentials: $passwordFile (ignored by Git). No existing password is overwritten."
