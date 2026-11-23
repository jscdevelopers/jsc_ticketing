param([switch]$SkipBuild,[string]$Subnet='10.254.250.0/28')
$ErrorActionPreference='Stop'
$testId='jsc-ticketing-e2e-'+[guid]::NewGuid().ToString('N').Substring(0,12)
$workspace=(Resolve-Path "$PSScriptRoot/..").Path
$testDirectory=Join-Path $workspace ".test-runtime/$testId"
New-Item -ItemType Directory -Force -Path $testDirectory | Out-Null
$savedBase=$env:E2E_BASE_URL;$savedPassword=$env:E2E_PASSWORD
function New-TestSecret([string]$File){$bytes=New-Object byte[] 24;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($bytes)}finally{$rng.Dispose()};[IO.File]::WriteAllText($File,[Convert]::ToBase64String($bytes),(New-Object Text.UTF8Encoding($false)))}
$dbSecret=Join-Path $testDirectory 'db_password.txt';$staffSecret=Join-Path $testDirectory 'staff_password.txt';New-TestSecret $dbSecret;New-TestSecret $staffSecret
$networkCreated=$false;$volumeCreated=$false
try{
    if(!$SkipBuild){
        docker build -t jsc-ticketing-api:development (Join-Path $workspace 'backend');if($LASTEXITCODE -ne 0){throw 'Backend build failed.'}
        docker build -t jsc-ticketing-web:development (Join-Path $workspace 'frontend');if($LASTEXITCODE -ne 0){throw 'Frontend build failed.'}
    }
    docker network create --subnet $Subnet $testId | Out-Null;if($LASTEXITCODE -ne 0){throw 'Test network creation failed.'};$networkCreated=$true
    docker run -d --rm --name "$testId-db" --network $testId --network-alias postgres --env POSTGRES_DB=ticketing_test --env POSTGRES_USER=ticketing_test --env POSTGRES_PASSWORD_FILE=/run/secrets/db_password --mount "type=bind,source=$dbSecret,target=/run/secrets/db_password,readonly" postgres:16.15-alpine postgres -c max_connections=250 | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Test database failed to start.'}
    $ready=$false;for($i=0;$i -lt 60;$i++){docker exec "$testId-db" pg_isready -h 127.0.0.1 -U ticketing_test -d ticketing_test *> $null;if($LASTEXITCODE -eq 0){$ready=$true;break};Start-Sleep -Milliseconds 500};if(!$ready){throw 'Test database did not become ready.'}
    $dbEnvironment=@('--env','DB_DSN=pgsql:host=postgres;port=5432;dbname=ticketing_test','--env','DB_USER=ticketing_test','--env','DB_PASSWORD_FILE=/run/secrets/db_password','--env','APP_ENV=test','--env','AUTH_MODE=password','--mount',"type=bind,source=$dbSecret,target=/run/secrets/db_password,readonly")
    docker volume create "$testId-files" | Out-Null;if($LASTEXITCODE -ne 0){throw 'Private test storage creation failed.'};$volumeCreated=$true
    $dbEnvironment+=@('--env','MALWARE_SCANNER_MODE=demo','--env','ALLOW_DEMO_INTEGRATIONS=true','--mount',"type=volume,source=$testId-files,target=/var/lib/ticketing/private")
    $testSource=Join-Path $workspace 'backend/tests'
    docker run --rm --network $testId @dbEnvironment --mount "type=bind,source=$staffSecret,target=/run/secrets/staff_password,readonly" --env E2E_PASSWORD_FILE=/run/secrets/staff_password --mount "type=bind,source=$testSource,target=/app/tests,readonly" jsc-ticketing-api:development php tests/e2e-seed.php
    if($LASTEXITCODE -ne 0){throw 'UI fixture creation failed.'}
    docker run -d --rm --name "$testId-api" --network $testId --network-alias api @dbEnvironment --publish 127.0.0.1::8080 jsc-ticketing-api:development | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Test API failed to start.'}
    docker run -d --rm --name "$testId-worker" --network $testId @dbEnvironment jsc-ticketing-api:development php bin/worker.php | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Test worker failed to start.'}
    docker run -d --rm --name "$testId-web" --network $testId --publish 127.0.0.1::8080 --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges:true jsc-ticketing-web:development | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Test frontend failed to start.'}
    $binding=docker port "$testId-web" 8080/tcp;$port=($binding -split ':')[-1].Trim();$env:E2E_BASE_URL="http://127.0.0.1:$port";$env:E2E_PASSWORD=[IO.File]::ReadAllText($staffSecret)
    $httpReady=$false;for($i=0;$i -lt 30;$i++){try{$response=Invoke-RestMethod "$env:E2E_BASE_URL/api/v1/health/ready";if($response.data.ready){$httpReady=$true;break}}catch{};Start-Sleep -Milliseconds 500};if(!$httpReady){throw 'UI HTTP readiness failed.'}
    Push-Location (Join-Path $workspace 'frontend');try{npm.cmd run test:e2e;if($LASTEXITCODE -ne 0){throw 'Browser acceptance checks failed.'}}finally{Pop-Location}
}finally{
    docker rm -f "$testId-web" "$testId-worker" "$testId-api" "$testId-db" *> $null
    if($volumeCreated){docker volume rm "$testId-files" *> $null}
    if($networkCreated){docker network rm $testId *> $null}
    $env:E2E_BASE_URL=$savedBase;$env:E2E_PASSWORD=$savedPassword
    # Preserve failed-test traces in ignored .test-runtime; credentials never enter Git.
}
