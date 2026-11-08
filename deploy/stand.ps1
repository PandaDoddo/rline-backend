param(
    [ValidateSet('Publish', 'Promote', 'Status', 'Start', 'Stop')][string]$Action = 'Status',
    [ValidateSet('main', 'testing', 'demo')][string]$Stand = 'testing',
    [ValidatePattern('^[a-zA-Z0-9_-]{1,80}$')][string]$Release,
    [string]$FrontendPath = (Join-Path $PSScriptRoot '../../frontend'),
    [string]$DockerContext = 'desktop-linux'
)
$ErrorActionPreference = 'Stop'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    $env:PATH = (Join-Path $env:LOCALAPPDATA 'Programs/DockerDesktop/resources/bin') + ';' + $env:PATH
}
function Compose {
    & docker --context $DockerContext compose @args
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed ($LASTEXITCODE)" }
}
$previousDist = $env:STAND_DIST_DIR
Push-Location (Join-Path $PSScriptRoot '..')
try {
    switch ($Action) {
        Publish {
            if (-not $Release) { $Release = Get-Date -Format 'yyyyMMdd-HHmmss' }
            $frontend = (Resolve-Path $FrontendPath).Path
            $env:STAND_DIST_DIR = Join-Path $frontend 'dist'
            Push-Location $frontend
            try {
                & npm.cmd run build -- "--base=/releases/$Release/"
                if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed; current stand was not changed.' }
            } finally { Pop-Location }
            Compose --profile stand-tools run --rm --no-deps stand-manager publish $Release
            Compose --profile stand-tools run --rm --no-deps stand-manager promote $Stand $Release
            Compose --profile stands up -d --wait stands worker
        }
        Promote {
            if (-not $Release) { throw 'Specify -Release' }
            Compose --profile stand-tools run --rm --no-deps stand-manager promote $Stand $Release
        }
        Status { Compose --profile stand-tools run --rm --no-deps stand-manager status }
        Start { Compose --profile stands up -d --wait stands worker }
        Stop { Compose --profile stands stop stands }
    }
} finally {
    $env:STAND_DIST_DIR = $previousDist
    Pop-Location
}
