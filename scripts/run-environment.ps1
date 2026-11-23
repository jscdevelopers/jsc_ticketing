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
$webImage = $configuration.services.web.image
$webImageId = docker image ls --quiet $webImage
if ($LASTEXITCODE -ne 0) { throw 'Docker image inventory failed.' }
if ($Environment -eq 'development' -and ($Build -or [string]::IsNullOrWhiteSpace(($webImageId -join '')))) {
    docker build -t $webImage (Join-Path $workspacePath 'frontend')
    if ($LASTEXITCODE -ne 0) { throw 'Frontend image build failed.' }
} elseif ([string]::IsNullOrWhiteSpace(($webImageId -join ''))) { throw "Prepare the web image $webImage before starting $Environment." }
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
# Upgrade the earlier offline gateway only when both ownership labels match.
if ($Environment -eq 'offline') {
    $legacyGateway = docker ps -aq --filter 'label=com.docker.compose.project=jsc-ticketing-offline' --filter 'label=com.docker.compose.service=gateway'
    if ($legacyGateway) {
        docker rm -f $legacyGateway | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Legacy offline gateway could not be replaced.' }
    }
    $legacyNetwork = docker network ls -q --filter 'name=jsc-ticketing-offline_gateway' --filter 'label=com.docker.compose.project=jsc-ticketing-offline'
    if ($legacyNetwork) {
        $legacyDetails = (docker network inspect $legacyNetwork | ConvertFrom-Json)[0]
        if ($legacyDetails.Name -eq 'jsc-ticketing-offline_gateway' -and @($legacyDetails.Containers.PSObject.Properties).Count -eq 0) {
            docker network rm $legacyNetwork | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'Empty legacy offline network could not be replaced.' }
        }
    }
}
docker @composeArguments up -d --wait --remove-orphans api worker web
if ($LASTEXITCODE -ne 0) { throw 'API did not become healthy.' }
$port = $configuration.services.web.ports[0].published
$url = "http://127.0.0.1:$port/api/v1/health/ready"
$ready = Invoke-RestMethod $url
if (!$ready.data.ready) { throw 'HTTP readiness verification failed.' }
Write-Output "RUNNING: $Environment at http://127.0.0.1:$port (readiness verified)."
