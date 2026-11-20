$ErrorActionPreference = 'Stop'
$testContainer = 'jsc-ticketing-test-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$testKeys = @('POSTGRES_PASSWORD', 'DB_DSN', 'DB_USER', 'DB_PASSWORD', 'DB_PASSWORD_FILE')
$savedEnvironment = @{}
foreach ($key in $testKeys) { $savedEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, 'Process') }
try {
    $env:POSTGRES_PASSWORD = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
    docker run --detach --rm --name $testContainer --env POSTGRES_PASSWORD --env POSTGRES_USER=ticketing_test --env POSTGRES_DB=ticketing_test --publish 127.0.0.1::5432 postgres:16.15-alpine | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Isolated PostgreSQL container could not start.' }
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        docker exec $testContainer pg_isready -h 127.0.0.1 -U ticketing_test -d ticketing_test *> $null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (!$ready) { throw 'Test PostgreSQL did not become ready.' }
    $binding = docker port $testContainer 5432/tcp
    $testPort = ($binding -split ':')[-1].Trim()
    $env:DB_DSN = "pgsql:host=127.0.0.1;port=$testPort;dbname=ticketing_test"
    $env:DB_USER = 'ticketing_test'
    $env:DB_PASSWORD = $env:POSTGRES_PASSWORD
    $env:DB_PASSWORD_FILE = $null
    php "$PSScriptRoot/../backend/tests/integration.php"
    if ($LASTEXITCODE -ne 0) { throw 'Backend integration tests failed.' }
} finally {
    docker rm --force $testContainer *> $null
    foreach ($key in $testKeys) { [Environment]::SetEnvironmentVariable($key, $savedEnvironment[$key], 'Process') }
}
