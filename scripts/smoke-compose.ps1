param([string]$NetworkSubnet = '10.254.240.0/28')
$ErrorActionPreference = 'Stop'
$testProject = 'jsc-ticketing-smoke-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$composePath = (Resolve-Path "$PSScriptRoot/../deploy/compose.yml").Path
$networkPath = (Resolve-Path "$PSScriptRoot/../deploy/compose.network.yml").Path
$composeArguments = @('compose', '-p', $testProject, '-f', $composePath, '-f', $networkPath)
$savedPassword = $env:POSTGRES_PASSWORD
$savedPort = $env:TICKETING_API_PORT
$savedSubnet = $env:TICKETING_NETWORK_SUBNET
try {
    $env:POSTGRES_PASSWORD = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
    $env:TICKETING_API_PORT = '0'
    $env:TICKETING_NETWORK_SUBNET = $NetworkSubnet
    docker @composeArguments config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Compose validation failed.' }
    docker @composeArguments build
    if ($LASTEXITCODE -ne 0) { throw 'Development image build failed.' }
    docker @composeArguments up -d postgres
    if ($LASTEXITCODE -ne 0) { throw 'Development PostgreSQL failed to start.' }
    docker @composeArguments --profile tools run --rm migrate
    if ($LASTEXITCODE -ne 0) { throw 'Container migration failed.' }
    docker @composeArguments --profile tools run --rm migrate
    if ($LASTEXITCODE -ne 0) { throw 'Container migration repeat failed.' }
    docker @composeArguments exec -T postgres createdb -U ticketing ticketing_test
    if ($LASTEXITCODE -ne 0) { throw 'Container test database could not be created.' }
    $testSource = (Resolve-Path "$PSScriptRoot/../backend/tests").Path
    docker @composeArguments run --rm --no-deps -T --env 'DB_DSN=pgsql:host=postgres;port=5432;dbname=ticketing_test' --volume "${testSource}:/app/tests:ro" api php tests/integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Linux container integration checks failed.' }
    docker @composeArguments up -d --wait api
    if ($LASTEXITCODE -ne 0) { throw 'Development API did not become healthy.' }
    $binding = docker @composeArguments port api 8080
    $testPort = ($binding -split ':')[-1].Trim()
    $ready = Invoke-RestMethod "http://127.0.0.1:$testPort/api/v1/health/ready"
    if (!$ready.data.ready) { throw 'Database readiness check failed.' }
    docker @composeArguments exec -T api php -r 'exit(posix_getuid() === 10001 ? 0 : 1);'
    if ($LASTEXITCODE -ne 0) { throw 'Runtime user check failed.' }
    Write-Output 'PASS: Compose build, migrations, Linux integration checks, readiness and non-root runtime.'
} finally {
    # Only the uniquely named disposable test project and its volume are removed.
    if ($testProject -notmatch '^jsc-ticketing-smoke-[a-f0-9]{12}$') { throw 'Unexpected test project name; refusing cleanup.' }
    docker @composeArguments --profile tools down --volumes --remove-orphans
    $env:POSTGRES_PASSWORD = $savedPassword
    $env:TICKETING_API_PORT = $savedPort
    $env:TICKETING_NETWORK_SUBNET = $savedSubnet
}
