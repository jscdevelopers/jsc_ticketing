param(
    [ValidateSet('offline', 'development', 'production')]
    [string]$Environment = 'development',
    [switch]$Build,
    [switch]$ValidateOnly,
    [switch]$Stop
)
$ErrorActionPreference = 'Stop'
$workspacePath = (Resolve-Path "$PSScriptRoot/..").Path
$profilePath = Join-Path $workspacePath ".env.$Environment"
$composePath = Join-Path $workspacePath 'deploy/compose.environments.yml'
$composeArguments = @('compose', '--project-directory', $workspacePath, '-p', "jsc-ticketing-$Environment", '--env-file', $profilePath, '-f', $composePath)
if ($Environment -eq 'offline') {
    $composeArguments += @('-f', (Join-Path $workspacePath 'deploy/compose.offline.yml'))
}
$configurationText = docker @composeArguments config --format json
if ($LASTEXITCODE -ne 0) { throw "Invalid $Environment configuration." }
$configuration = $configurationText | ConvertFrom-Json
if ($ValidateOnly) {
    Write-Output "PASS: $Environment configuration."
    return
}
if ($Stop) {
    docker @composeArguments down
    if ($LASTEXITCODE -ne 0) { throw 'Environment stop failed.' }
    return
}
$imageName = $configuration.services.api.image
if ($Environment -eq 'offline' -and $Build) { throw 'Build the development image online before starting offline mode.' }
if ($Environment -eq 'production' -and $Build) { throw 'Supply a separately approved production image; the current Dockerfile is for development.' }
$imageId = docker image ls --quiet $imageName
if ($LASTEXITCODE -ne 0) { throw 'Docker image inventory failed.' }
$imageMissing = [string]::IsNullOrWhiteSpace(($imageId -join ''))
if ($Environment -eq 'development' -and ($Build -or $imageMissing)) {
    docker build -t $imageName (Join-Path $workspacePath 'backend')
    if ($LASTEXITCODE -ne 0) { throw 'Development image build failed.' }
} elseif ($imageMissing) {
    throw "Required image $imageName is not cached. Prepare it before starting $Environment."
}
if ($Environment -eq 'offline') {
    $gatewayImage = $configuration.services.gateway.image
    $gatewayImageId = docker image ls --quiet $gatewayImage
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($gatewayImageId -join ''))) {
        throw "Cache the gateway online before offline use: docker pull $gatewayImage"
    }
}
$postgresImageId = docker image ls --quiet postgres:16.15-alpine
if ($LASTEXITCODE -ne 0) { throw 'Docker image inventory failed.' }
if ([string]::IsNullOrWhiteSpace(($postgresImageId -join ''))) {
    if ($Environment -eq 'offline') { throw 'Cache postgres:16.15-alpine online before starting offline mode.' }
    docker pull postgres:16.15-alpine
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL image preparation failed.' }
}
$passwordPath = $configuration.secrets.ticketing_db_password.file
if (!(Test-Path -LiteralPath $passwordPath)) {
    if ($Environment -eq 'production') { throw 'Provision the production database secret outside Git before starting.' }
    $privateRoot = [IO.Path]::GetFullPath((Join-Path $workspacePath 'secrets')) + [IO.Path]::DirectorySeparatorChar
    $resolvedPasswordPath = [IO.Path]::GetFullPath($passwordPath)
    if (!$resolvedPasswordPath.StartsWith($privateRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Automatic secret generation is restricted to this workspace secrets directory.'
    }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $resolvedPasswordPath) | Out-Null
    $randomBytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($randomBytes) } finally { $generator.Dispose() }
    $password = [Convert]::ToBase64String($randomBytes)
    $secretStream = [IO.File]::Open($resolvedPasswordPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $secretBytes = [Text.Encoding]::UTF8.GetBytes($password)
        $secretStream.Write($secretBytes, 0, $secretBytes.Length)
    } finally { $secretStream.Dispose() }
}
docker @composeArguments up -d postgres
if ($LASTEXITCODE -ne 0) { throw 'Database startup failed.' }
docker @composeArguments --profile tools run --rm migrate
if ($LASTEXITCODE -ne 0) { throw 'Migration failed; API has not been started by this command.' }
if ($Environment -eq 'offline') {
    docker @composeArguments up -d --wait --force-recreate gateway
} else {
    docker @composeArguments up -d --wait api
}
if ($LASTEXITCODE -ne 0) { throw 'API did not become healthy.' }
$port = if ($Environment -eq 'offline') { $configuration.services.gateway.ports[0].published } else { $configuration.services.api.ports[0].published }
$url = "http://127.0.0.1:$port/api/v1/health/ready"
$ready = Invoke-RestMethod $url
if (!$ready.data.ready) { throw 'HTTP readiness verification failed.' }
Write-Output "RUNNING: $Environment at http://127.0.0.1:$port (readiness verified)."
