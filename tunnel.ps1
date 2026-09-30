#Requires -Version 7.0
<#
.SYNOPSIS
    Publishes the local app to the internet on a random URL using a Cloudflare quick tunnel.

.DESCRIPTION
    Starts `php artisan serve`, starts `cloudflared tunnel --url`, and prints the random
    https://<random>.trycloudflare.com hostname. Every run yields a different URL, which
    suits demo and review links but is not a deployment: the URL is unauthenticated and
    the tunnel is rate limited.

    Two things in this repo would otherwise serve a broken page to anyone opening the
    tunnel URL, so the script resolves both before publishing:

      1. public/hot. Once `npm run dev` has run, that file exists and tells @vite to emit
         asset URLs pointing at the Vite dev server (http://localhost:5174). A visitor
         cannot reach the dev server's own localhost, so they would get the HTML with no
         CSS and no JS. The script deletes the file so @vite serves hashed assets from
         public/build over the tunnel's own origin.

      2. TLS termination. cloudflared speaks https to the browser and plain http to
         localhost, forwarding the scheme in X-Forwarded-Proto. Laravel has to trust the
         local proxy to honour it; otherwise asset() emits http:// on an https page and the
         browser blocks the stylesheet as mixed content. bootstrap/app.php trusts loopback
         for this reason.

    After the tunnel is up the script fetches the page and its stylesheet through the
    public URL and reports what it found, so a broken asset URL fails here rather than in
    a visitor's browser.

.EXAMPLE
    ./tunnel.ps1

.EXAMPLE
    ./tunnel.ps1 -Port 8080 -SkipBuild

.NOTES
    Ctrl+C stops the tunnel and the local server together. Re-running with -SkipBuild
    republishes without a rebuild. Run `npm run dev` afterwards to get hot reloading back.
#>
[CmdletBinding()]
param(
    [int]$Port = 8000,
    [switch]$SkipBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ProjectRoot = $PSScriptRoot
Set-Location $ProjectRoot

function Write-Step { param([string]$Message) Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Note { param([string]$Message) Write-Host "    $Message" -ForegroundColor Green }
function Write-Caution { param([string]$Message) Write-Host "    $Message" -ForegroundColor Yellow }
function Write-Fail { param([string]$Message) Write-Host "    $Message" -ForegroundColor Red }
function Stop-WithError { param([string]$Message) Write-Fail $Message; exit 1 }

function Test-PortInUse {
    param([int]$TargetPort)

    try {
        $client = [System.Net.Sockets.TcpClient]::new()
        $connected = $client.ConnectAsync('127.0.0.1', $TargetPort).Wait(600)
        $client.Dispose()

        return $connected
    } catch {
        return $false
    }
}

function Get-TunnelUrl {
    param([string]$LogPath, [System.Diagnostics.Process]$Process, [int]$TimeoutSeconds = 60)

    $pattern = 'https://[a-zA-Z0-9-]+\.trycloudflare\.com'
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        if ($Process.HasExited) {
            return $null
        }

        if (Test-Path -LiteralPath $LogPath) {
            $match = Select-String -LiteralPath $LogPath -Pattern $pattern -AllMatches |
                ForEach-Object { $_.Matches } |
                ForEach-Object { $_.Value } |
                Select-Object -First 1

            if ($match) {
                return $match
            }
        }

        Start-Sleep -Milliseconds 500
    }

    return $null
}

$cloudflared = Get-Command cloudflared -ErrorAction SilentlyContinue
if (-not $cloudflared) {
    Stop-WithError 'cloudflared is not on PATH. Install it with: winget install --id Cloudflare.cloudflared'
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Stop-WithError 'php is not on PATH.'
}

$manifestPath = Join-Path $ProjectRoot 'public/build/manifest.json'
$hotFilePath = Join-Path $ProjectRoot 'public/hot'

if (-not $SkipBuild) {
    Write-Step 'Building frontend assets so they can be served from public/build'
    npm run build | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Stop-WithError 'npm run build failed. Fix the build before tunnelling.'
    }
}

if (-not (Test-Path -LiteralPath $manifestPath)) {
    Stop-WithError 'public/build/manifest.json is missing, so @vite has nothing to serve. Run: npm run build'
}

if (Test-Path -LiteralPath $hotFilePath) {
    $hotTarget = (Get-Content -LiteralPath $hotFilePath -Raw).Trim()
    Write-Step 'Removing public/hot so @vite serves built assets instead of the dev server'
    Write-Note "was pointing at $hotTarget, which tunnel visitors cannot reach"
    Remove-Item -LiteralPath $hotFilePath -Force
}

$appProcess = $null
$tunnelProcess = $null
$serveStdout = Join-Path $env:TEMP "lms-serve-$Port.out.log"
$serveStderr = Join-Path $env:TEMP "lms-serve-$Port.err.log"

if (Test-PortInUse -TargetPort $Port) {
    Write-Step "Port $Port is already serving, reusing that process"
} else {
    Write-Step "Starting php artisan serve on 127.0.0.1:$Port"
    $appProcess = Start-Process -FilePath 'php' `
        -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', "--port=$Port") `
        -WorkingDirectory $ProjectRoot `
        -RedirectStandardOutput $serveStdout `
        -RedirectStandardError $serveStderr `
        -PassThru `
        -WindowStyle Hidden
}

try {
    Write-Step 'Waiting for the app to answer its health check'
    $healthy = $false
    $deadline = (Get-Date).AddSeconds(30)

    while ((Get-Date) -lt $deadline) {
        if ($appProcess -and $appProcess.HasExited) {
            Stop-WithError "artisan serve exited early. See $serveStderr"
        }

        try {
            $health = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/up" -TimeoutSec 5
            if ($health.StatusCode -eq 200) {
                $healthy = $true
                break
            }
        } catch {
            Start-Sleep -Milliseconds 400
        }
    }

    if (-not $healthy) {
        Stop-WithError "the app did not answer /up within 30s. See $serveStderr"
    }

    Write-Note 'app is healthy'

    Write-Step 'Starting the Cloudflare quick tunnel'
    $tunnelStdout = Join-Path $env:TEMP "lms-tunnel-$Port.out.log"
    $tunnelStderr = Join-Path $env:TEMP "lms-tunnel-$Port.err.log"

    $tunnelProcess = Start-Process -FilePath $cloudflared.Source `
        -ArgumentList @('tunnel', '--no-autoupdate', '--url', "http://127.0.0.1:$Port") `
        -WorkingDirectory $ProjectRoot `
        -RedirectStandardOutput $tunnelStdout `
        -RedirectStandardError $tunnelStderr `
        -PassThru `
        -WindowStyle Hidden

    Write-Step 'Waiting for the random public hostname'
    $publicUrl = Get-TunnelUrl -LogPath $tunnelStderr -Process $tunnelProcess

    if (-not $publicUrl) {
        Stop-WithError "cloudflared did not report a trycloudflare.com URL. See $tunnelStderr"
    }

    $publicUrl = $publicUrl.TrimEnd('/')
    Write-Note $publicUrl

    Write-Step 'Verifying the page and its stylesheet through the public URL'

    $page = $null
    $deadline = (Get-Date).AddSeconds(60)

    while ((Get-Date) -lt $deadline) {
        if ($tunnelProcess.HasExited) {
            Stop-WithError "cloudflared stopped unexpectedly. See $tunnelStderr"
        }

        try {
            $response = Invoke-WebRequest -Uri "$publicUrl/" -TimeoutSec 20
            if ($response.StatusCode -eq 200) {
                $page = $response
                break
            }
        } catch {
            Start-Sleep -Seconds 2
        }
    }

    if (-not $page) {
        Stop-WithError 'the public URL never returned the welcome page'
    }

    Write-Note 'welcome page returned 200'

    $linkTag = [regex]::Match($page.Content, '<link[^>]*rel="stylesheet"[^>]*>').Value
    $stylesheetHref = [regex]::Match($linkTag, 'href="([^"]+)"').Groups[1].Value

    if (-not $stylesheetHref) {
        Stop-WithError 'no stylesheet link was found in the page, so styles cannot load'
    }

    $stylesheetResponse = Invoke-WebRequest -Uri $stylesheetHref -TimeoutSec 30

    if ($stylesheetResponse.StatusCode -ne 200 -or $stylesheetResponse.Content.Length -eq 0) {
        Stop-WithError "the stylesheet at $stylesheetHref did not load"
    }

    if ($stylesheetHref.StartsWith('http://')) {
        Stop-WithError @"
the stylesheet URL is http:// on an https page, so the browser will drop it as mixed
  content. Laravel is not honouring X-Forwarded-Proto, so the local proxy is not trusted.
  Check trustProxies in bootstrap/app.php, then clear the config cache.
"@
    }

    $kilobytes = [math]::Round($stylesheetResponse.RawContentLength / 1KB, 1)
    Write-Note "stylesheet loaded over https ($kilobytes KB) from $stylesheetHref"

    try {
        Set-Clipboard -Value $publicUrl
        Write-Note 'public URL copied to the clipboard'
    } catch {
        Write-Caution 'could not copy the URL to the clipboard'
    }

    Write-Host ''
    Write-Host '  Public URL   ' -NoNewline
    Write-Host $publicUrl -ForegroundColor Green
    Write-Host '  Local URL    ' -NoNewline
    Write-Host "http://127.0.0.1:$Port" -ForegroundColor DarkGray
    Write-Host ''

    Write-Caution 'This URL is public and unauthenticated. Anyone with the link can reach the app.'
    Write-Caution 'Press Ctrl+C to stop the tunnel and the local server.'

    while (-not $tunnelProcess.HasExited) {
        Start-Sleep -Seconds 2
    }
} finally {
    if ($tunnelProcess -and -not $tunnelProcess.HasExited) {
        $tunnelProcess.Kill($true)
    }

    if ($appProcess -and -not $appProcess.HasExited) {
        $appProcess.Kill($true)
    }
}
