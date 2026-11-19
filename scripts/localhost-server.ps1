param(
    [ValidateSet('manual', 'agent')]
    [string] $Mode = 'manual',
    [ValidateRange(1, 240)]
    [int] $IdleTimeoutMinutes = 15
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$publicRoot = Join-Path $projectRoot 'public'
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
$portText = if ($env:IEPRCARD_PORT) { $env:IEPRCARD_PORT } else { '9477' }
$port = 0

if (-not $php) {
    Write-Error 'PHP CLI não foi encontrado no PATH. Instale PHP 8.2+ e reabra o CMD.'
    exit 2
}
if ($portText -notmatch '^\d{1,5}$' -or -not [int]::TryParse($portText, [ref] $port) -or $port -lt 1 -or $port -gt 65535) {
    Write-Error 'IEPRCARD_PORT precisa ser uma porta entre 1 e 65535.'
    exit 2
}
if (-not (Test-Path $publicRoot -PathType Container)) {
    Write-Error "Diretório público não encontrado: $publicRoot"
    exit 2
}

$env:APP_ENV = 'dev'
if (-not $env:PLATFORM_HOSTNAME) { $env:PLATFORM_HOSTNAME = 'localhost' }
if (-not $env:DB_DRIVER) { $env:DB_DRIVER = 'sqlite' }
if ($env:DB_DRIVER -eq 'sqlite' -and -not $env:DB_SQLITE_PATH) {
    $varPath = Join-Path $projectRoot 'var'
    New-Item -ItemType Directory -Path $varPath -Force | Out-Null
    $env:DB_SQLITE_PATH = Join-Path $varPath 'ieprcard.sqlite'
}

$pdoExtension = switch ($env:DB_DRIVER) {
    'sqlite' { 'pdo_sqlite' }
    'mysql' { 'pdo_mysql' }
    default { $null }
}
$phpIniArguments = @()
if ($pdoExtension) {
    $loadedExtensions = @(& $php -m 2>$null)
    if ($loadedExtensions -notcontains $pdoExtension) {
        $phpRoot = & $php -r 'echo dirname(PHP_BINARY);'
        $extensionInfo = & $php -i 2>$null | Select-String '^extension_dir => ' | Select-Object -First 1
        $extensionDirectory = if ($extensionInfo) { ($extensionInfo.Line -split '=>')[1].Trim() } else { '' }
        $extensionPath = Join-Path (Join-Path $phpRoot $extensionDirectory) "php_$pdoExtension.dll"
        if (Test-Path $extensionPath -PathType Leaf) {
            $phpIniArguments = @('-d', "extension=$pdoExtension")
            Write-Host "Extensão $pdoExtension ativada apenas para este launcher."
        }
    }
}

function Start-LocalMysqlIfNeeded {
    if ($env:DB_DRIVER -ne 'mysql') { return }
    $hostName = if ($env:DB_HOST) { $env:DB_HOST } else { 'localhost' }
    if ($hostName -notin @('localhost', '127.0.0.1', '::1')) { return }

    $services = @(Get-Service -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -match '^(MySQL|MariaDB)' -or $_.DisplayName -match 'MySQL|MariaDB'
    })
    $running = @($services | Where-Object Status -eq 'Running')
    if ($running.Count -gt 0) { return }
    if ($services.Count -eq 0) {
        Write-Warning 'Nenhum serviço local MySQL/MariaDB foi encontrado. O PHP será aberto, mas o diagnóstico indicará que o banco está indisponível.'
        return
    }

    $service = $services | Select-Object -First 1
    try {
        Start-Service -Name $service.Name
        (Get-Service -Name $service.Name).WaitForStatus('Running', [TimeSpan]::FromSeconds(20))
        Write-Host "Banco iniciado: $($service.DisplayName)"
    } catch {
        Write-Warning "Não foi possível iniciar $($service.DisplayName). Talvez o CMD precise de permissão administrativa. O PHP ainda será aberto."
    }
}

Start-LocalMysqlIfNeeded

$startup = Join-Path $projectRoot 'database\start-local.php'
if (Test-Path $startup -PathType Leaf) {
    Write-Host 'Preparando banco local (se disponível)...'
    & $php @phpIniArguments $startup
    if ($LASTEXITCODE -ne 0) {
        Write-Warning 'A preparação do banco não foi concluída. A página local mostrará o diagnóstico; confira as extensões PDO do PHP.'
    }
}

$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $port)
try {
    $listener.Start()
    $listener.Stop()
} catch {
    try { $listener.Stop() } catch {}
    Write-Error "A porta $port já está em uso ou não pode ser aberta. Defina IEPRCARD_PORT com outra porta e tente novamente."
    exit 2
}

$runtimeRoot = Join-Path ([System.IO.Path]::GetTempPath()) 'IEPRCard-local'
New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
$heartbeatPath = Join-Path $runtimeRoot "heartbeat-$port.txt"
$stdoutPath = Join-Path $runtimeRoot "php-$port.out.log"
$stderrPath = Join-Path $runtimeRoot "php-$port.err.log"
[System.IO.File]::WriteAllText($heartbeatPath, [DateTime]::UtcNow.ToString('o'))
$env:IEPRCARD_LOCAL_HEARTBEAT = $heartbeatPath

$arguments = (@($phpIniArguments) + @('-S', "127.0.0.1:$port", '-t', "`"$publicRoot`"")) -join ' '
$server = Start-Process -FilePath $php -ArgumentList $arguments -PassThru -NoNewWindow `
    -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath

try {
    Start-Sleep -Milliseconds 500
    if ($server.HasExited) {
        Write-Error "O servidor PHP encerrou ao iniciar. Consulte o log: $stderrPath"
        exit 1
    }

    Write-Host ''
    Write-Host "IEPRCard local: http://localhost:$port"
    if ($Mode -eq 'manual') {
        Write-Host 'Modo manual. Digite 0 e pressione Enter, ou feche esta janela, para encerrar.'
        $inputBuffer = ''
        while (-not $server.HasExited) {
            if ([Console]::KeyAvailable) {
                $key = [Console]::ReadKey($true)
                if ($key.Key -eq [ConsoleKey]::Enter) {
                    if ($inputBuffer -eq '0') { break }
                    $inputBuffer = ''
                } elseif ($key.Key -eq [ConsoleKey]::Backspace) {
                    if ($inputBuffer.Length -gt 0) { $inputBuffer = $inputBuffer.Substring(0, $inputBuffer.Length - 1) }
                } elseif (-not [char]::IsControl($key.KeyChar)) {
                    $inputBuffer += $key.KeyChar
                }
            }
            Start-Sleep -Milliseconds 100
        }
    } else {
        Write-Host "Modo agente. Encerrará após $IdleTimeoutMinutes minuto(s) sem requisições HTTP."
        $idleLimit = [TimeSpan]::FromMinutes($IdleTimeoutMinutes)
        while (-not $server.HasExited) {
            $lastActivity = [DateTime]::MinValue
            try { $lastActivity = [DateTime]::Parse([System.IO.File]::ReadAllText($heartbeatPath)).ToUniversalTime() } catch {}
            if (([DateTime]::UtcNow - $lastActivity) -ge $idleLimit) {
                Write-Host 'Prazo sem atividade atingido; encerrando o servidor.'
                break
            }
            Start-Sleep -Seconds 2
        }
    }
} finally {
    if (-not $server.HasExited) {
        & taskkill.exe /PID $server.Id /T /F *> $null
    }
    Remove-Item -LiteralPath $heartbeatPath -Force -ErrorAction SilentlyContinue
}
