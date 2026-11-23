param([Parameter(Mandatory=$true)][string]$BackupPath,[string]$Subnet='10.254.248.0/28')
$ErrorActionPreference='Stop'
$workspace=(Resolve-Path "$PSScriptRoot/..").Path
$backup=(Resolve-Path -LiteralPath $BackupPath).Path
$metadata=Get-Content -LiteralPath (Join-Path $backup 'backup.json') -Raw | ConvertFrom-Json
foreach($artifact in @(@('database.dump','database_sha256'),@('files.tar','files_sha256'))){if((Get-FileHash -LiteralPath (Join-Path $backup $artifact[0]) -Algorithm SHA256).Hash -ne $metadata.($artifact[1])){throw 'Backup integrity check failed.'}}
$testId='jsc-ticketing-recovery-'+[guid]::NewGuid().ToString('N').Substring(0,12)
$testPath=Join-Path $workspace ".test-runtime/$testId";New-Item -ItemType Directory -Path $testPath -Force | Out-Null
$secret=Join-Path $testPath 'db_password.txt';$bytes=New-Object byte[] 32;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($bytes)}finally{$rng.Dispose()};[IO.File]::WriteAllText($secret,[Convert]::ToBase64String($bytes),(New-Object Text.UTF8Encoding($false)))
$networkCreated=$false;$volumeCreated=$false;$databaseCreated=$false
try{
    docker network create --internal --subnet $Subnet $testId | Out-Null;if($LASTEXITCODE -ne 0){throw 'Recovery network creation failed.'};$networkCreated=$true
    docker volume create "$testId-files" | Out-Null;if($LASTEXITCODE -ne 0){throw 'Recovery storage creation failed.'};$volumeCreated=$true
    docker run -d --rm --name "$testId-db" --network $testId --network-alias postgres --env POSTGRES_DB=ticketing_test --env POSTGRES_USER=ticketing --env POSTGRES_PASSWORD_FILE=/run/secrets/db_password --mount "type=bind,source=$secret,target=/run/secrets/db_password,readonly" postgres:16.15-alpine | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Recovery database creation failed.'};$databaseCreated=$true
    $ready=$false;for($i=0;$i -lt 60;$i++){docker exec "$testId-db" pg_isready -h 127.0.0.1 -U ticketing -d ticketing_test *> $null;if($LASTEXITCODE -eq 0){$ready=$true;break};Start-Sleep -Milliseconds 500};if(!$ready){throw 'Recovery database did not become ready.'}
    docker cp (Join-Path $backup 'database.dump') "${testId}-db:/tmp/database.dump";if($LASTEXITCODE -ne 0){throw 'Recovery dump copy failed.'}
    docker exec "$testId-db" pg_restore -U ticketing -d ticketing_test --no-owner --no-acl --exit-on-error /tmp/database.dump;if($LASTEXITCODE -ne 0){throw 'PostgreSQL restore failed.'}
    $runtime=@('--rm','--network',$testId,'--read-only','--tmpfs','/tmp','--cap-drop','ALL','--security-opt','no-new-privileges:true','--env','APP_ENV=test','--env','AUTH_MODE=password','--env','DB_DSN=pgsql:host=postgres;port=5432;dbname=ticketing_test','--env','DB_USER=ticketing','--env','DB_PASSWORD_FILE=/run/secrets/db_password','--mount',"type=bind,source=$secret,target=/run/secrets/db_password,readonly",'--mount',"type=volume,source=$testId-files,target=/var/lib/ticketing/private")
    docker run @runtime --mount "type=bind,source=$backup,target=/backup,readonly" $metadata.api_image tar -xf /backup/files.tar -C /var/lib/ticketing/private
    if($LASTEXITCODE -ne 0){throw 'Private file restore failed.'}
    $restored=docker run @runtime $metadata.api_image php bin/recovery-manifest.php;if($LASTEXITCODE -ne 0){throw 'Restored integrity manifest failed.'}
    $expected=(Get-Content -LiteralPath (Join-Path $backup 'manifest.json') -Raw | ConvertFrom-Json) | ConvertTo-Json -Depth 50 -Compress
    $actual=($restored -join "`n" | ConvertFrom-Json) | ConvertTo-Json -Depth 50 -Compress
    if($expected -cne $actual){throw 'Restored row counts, content digests, sequences or private files differ.'}
    docker run @runtime $metadata.api_image php bin/recovery-check.php;if($LASTEXITCODE -ne 0){throw 'Restored application probe failed.'}
    Write-Output 'PASS: separate database restore, every table content/count, sequences, private file hashes and authorized workflow execution. Source environment unchanged.'
}finally{
    if($testId -notmatch '^jsc-ticketing-recovery-[a-f0-9]{12}$'){throw 'Unexpected recovery resource name; refusing cleanup.'}
    if($databaseCreated){docker rm -f "$testId-db" *> $null}
    if($volumeCreated){docker volume rm "$testId-files" *> $null}
    if($networkCreated){docker network rm $testId *> $null}
}
