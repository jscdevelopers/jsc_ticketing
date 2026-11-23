param([ValidateSet('development','offline')][string]$Environment='development')
$ErrorActionPreference='Stop'
$workspace=(Resolve-Path "$PSScriptRoot/..").Path
$backupId=(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8)
$backupRoot=[IO.Path]::GetFullPath((Join-Path $workspace 'backups'))+[IO.Path]::DirectorySeparatorChar
$backupPath=[IO.Path]::GetFullPath((Join-Path $backupRoot "$Environment-$backupId"))
if(!$backupPath.StartsWith($backupRoot,[StringComparison]::OrdinalIgnoreCase)){throw 'Unexpected backup target.'}
New-Item -ItemType Directory -Path $backupPath -Force | Out-Null
$arguments=@('compose','--project-directory',$workspace,'-p',"jsc-ticketing-$Environment",'--env-file',(Join-Path $workspace ".env.$Environment"),'-f',(Join-Path $workspace 'deploy/compose.environments.yml'))
if($Environment -eq 'offline'){$arguments+=@('-f',(Join-Path $workspace 'deploy/compose.offline.yml'))}
$configuration=docker @arguments config --format json | ConvertFrom-Json
if($LASTEXITCODE -ne 0){throw 'Invalid backup environment.'}
$database=docker @arguments ps -q postgres
if(!$database){throw 'Start the local environment before backing up.'}
$running=@();foreach($service in @('api','worker','web')){if(docker @arguments ps -q --status running $service){$running+=$service}}
$paused=$false
try{
    if($running.Count){$paused=$true;docker @arguments stop @running;if($LASTEXITCODE -ne 0){throw 'Could not pause local writes.'}}
    $dumpFile="/tmp/jsc-$backupId.dump"
    docker exec $database pg_dump -U ticketing -d ticketing --format=custom --file $dumpFile
    if($LASTEXITCODE -ne 0){throw 'Database dump failed.'}
    docker cp "${database}:$dumpFile" (Join-Path $backupPath 'database.dump')
    if($LASTEXITCODE -ne 0){throw 'Database dump copy failed.'}
    docker exec $database rm $dumpFile
    if($LASTEXITCODE -ne 0){throw 'Temporary dump cleanup failed.'}
    $fileVolume=$configuration.volumes.ticketing_files.name
    docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges:true --mount "type=volume,source=$fileVolume,target=/var/lib/ticketing/private,readonly" --mount "type=bind,source=$backupPath,target=/backup" $configuration.services.api.image tar -cf /backup/files.tar -C /var/lib/ticketing/private .
    if($LASTEXITCODE -ne 0){throw 'Private file archive failed.'}
    $manifest=docker @arguments --profile tools run --rm --no-deps -T --volume "${fileVolume}:/var/lib/ticketing/private:ro" migrate php bin/recovery-manifest.php
    if($LASTEXITCODE -ne 0){throw 'Recovery manifest failed.'}
    [IO.File]::WriteAllText((Join-Path $backupPath 'manifest.json'),($manifest -join "`n"),(New-Object Text.UTF8Encoding($false)))
    $imageId=docker image inspect --format '{{.Id}}' $configuration.services.api.image;if($LASTEXITCODE -ne 0){throw 'Backup image identification failed.'}
    $metadata=[ordered]@{api_tag=$configuration.services.api.image;environment=$Environment;created_utc=[DateTime]::UtcNow.ToString('o');api_image=$imageId.Trim();database_sha256=(Get-FileHash -LiteralPath (Join-Path $backupPath 'database.dump') -Algorithm SHA256).Hash;files_sha256=(Get-FileHash -LiteralPath (Join-Path $backupPath 'files.tar') -Algorithm SHA256).Hash}
    [IO.File]::WriteAllText((Join-Path $backupPath 'backup.json'),($metadata | ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
    Write-Output "BACKUP: $backupPath (database, private files and integrity manifest; protect outside Git)."
}finally{
    if($paused){docker @arguments up -d --wait @running;if($LASTEXITCODE -ne 0){Write-Warning 'Backup finished, but local services need operator restart.'}}
}
