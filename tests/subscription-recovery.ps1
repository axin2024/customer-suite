param(
    [Parameter(Mandatory)][string]$MysqlBin,
    [string]$ScratchRoot = [IO.Path]::GetTempPath()
)
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$MysqlBin = (Resolve-Path -LiteralPath $MysqlBin).Path
$run = New-Item -ItemType Directory -Path (Join-Path $ScratchRoot ('crm-subscription-recovery-'+[guid]::NewGuid().ToString('N')))
$data = Join-Path $run 'data'
$manifest = Join-Path $run 'subscription.json'
$evidence = Join-Path $run 'evidence.jsonl'
$backup = Join-Path $run 'crm.sql'
$legacy = Join-Path $run 'legacy'
$worker = $null
$server = $null
$portListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$portListener.Start()
try { $port = $portListener.LocalEndpoint.Port } finally { $portListener.Stop() }
$client = @('--no-defaults','--protocol=tcp','-h','127.0.0.1',"-P$port",'-u','root')
$env:CRM_TEST_DATABASE='1'
$env:CRM_DB_USER='root'
$env:CRM_DB_PASSWORD=''
$env:CRM_OUTREACH_MODE='replay'
function Select-Ledger([string]$name) {
    $env:CRM_DSN="mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4"
}
function Run-Php([string[]]$arguments) {
    & php -d extension=pdo_mysql @arguments
    if ($LASTEXITCODE) { throw "PHP failed: $($arguments[0])" }
}
function Start-Database {
    $script:server = Start-Process -FilePath "$MysqlBin/mysqld.exe" -ArgumentList @('--no-defaults',('"--datadir='+$data+'"'),
        ('"--log-error='+$run.FullName+'/mysql.log"'),"--port=$port",'--bind-address=127.0.0.1','--mysqlx=0') -WindowStyle Hidden -PassThru
    foreach ($attempt in 1..100) {
        if ($server.HasExited) { throw 'Isolated MySQL exited during startup' }
        & "$MysqlBin/mysql.exe" @client --connect-timeout=1 -Nse 'SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { return }
        Start-Sleep -Milliseconds 200
    }
    throw 'MySQL startup timed out'
}
Write-Host "Recovery files: $run"
Push-Location -LiteralPath $repo
try {
    New-Item -ItemType Directory -Path $data | Out-Null
    & "$MysqlBin/mysqld.exe" --no-defaults "--datadir=$data" --initialize-insecure "--log-error=$run/init.log"
    if ($LASTEXITCODE) { throw 'MySQL initialization failed' }
    Start-Database
    & "$MysqlBin/mysql.exe" @client -e 'CREATE DATABASE crm_test_original CHARACTER SET utf8mb4; CREATE DATABASE crm_test_restored CHARACTER SET utf8mb4;'
    if ($LASTEXITCODE) { throw 'Test schema creation failed' }
    Select-Ledger 'crm_test_original'
    $oldCommit = git rev-parse 'v1.1.0^{commit}'
    git archive v1.1.0 --format=zip "--output=$run/legacy.zip"
    if ($LASTEXITCODE) { throw 'The v1.1.0 source tag is required' }
    Expand-Archive -LiteralPath "$run/legacy.zip" -DestinationPath $legacy
    Push-Location -LiteralPath $legacy
    try {
        Run-Php @('bin/crm.php','migrate') | Out-Null
        Run-Php @('tests/handovers.php') | Out-Null
        Run-Php @('tests/management-reports.php','--evidence') | Set-Content -LiteralPath $evidence -Encoding utf8NoBOM
        $before = Run-Php @('tests/recovery-check.php',$evidence)
    } finally { Pop-Location }
    $fixture = (Get-Content -LiteralPath $evidence -TotalCount 1) | ConvertFrom-Json -AsHashtable
    $companies = @($fixture.contracts.Keys)
    $preflight = @{}
    foreach ($company in $companies) { $preflight[$company] = Run-Php @('bin/crm.php','subscription-preflight',[string]$company) }
    $applied = (Run-Php @('bin/crm.php','migrate') | ConvertFrom-Json).applied
    if ((Run-Php @('tests/recovery-check.php',$evidence)) -cne $before) { throw 'Historic records changed during upgrade' }
    $admin = & "$MysqlBin/mysql.exe" @client -Ns crm_test_original -e 'SELECT MIN(id) FROM users WHERE is_admin=1'
    foreach ($company in $companies) {
        if ((Run-Php @('bin/crm.php','subscription-preflight',[string]$company)) -cne $preflight[$company]) { throw 'Old company business digest changed' }
        do {
            $batch = Run-Php @('bin/crm.php','subscription-backfill',[string]$company,[string]$admin,'1') | ConvertFrom-Json
            if ($batch.pending -gt 0 -and $batch.checked_ids.Count -eq 0) { throw 'Backfill failed to advance' }
        } while ($batch.pending -gt 0)
        Run-Php @('bin/crm.php','subscription-pilot',[string]$company,[string]$admin,'0','active') | Out-Null
    }
    $autoOpened = & "$MysqlBin/mysql.exe" @client -Ns crm_test_original -e 'SELECT COUNT(*) FROM subscriptions'
    if ($autoOpened -ne '0') { throw 'Old agreements were automatically billed' }
    Run-Php @('tests/subscription-recovery.php','setup',$manifest)
    $worker = Start-Process -FilePath (Get-Command php).Source -ArgumentList @('-d','extension=pdo_mysql','tests/subscription-recovery.php','interrupt',('"'+$manifest+'"')) -WorkingDirectory $repo -WindowStyle Hidden -PassThru -RedirectStandardError "$run/producer.log"
    foreach ($attempt in 1..150) {
        if ($worker.HasExited) { throw 'Producer exited before reaching its commit checkpoint' }
        if (Test-Path -LiteralPath "$manifest.committed") { break }
        Start-Sleep -Milliseconds 100
    }
    if (!(Test-Path -LiteralPath "$manifest.committed")) { throw 'Missing post-commit checkpoint' }
    Stop-Process -InputObject $worker
    $worker.WaitForExit()
    Stop-Process -InputObject $server
    $server.WaitForExit()
    Start-Database
    $restarted = Run-Php @('tests/subscription-recovery.php','verify',$manifest)
    & "$MysqlBin/mysqldump.exe" @client --single-transaction --hex-blob --no-tablespaces --set-gtid-purged=OFF "--result-file=$backup" crm_test_original
    if ($LASTEXITCODE) { throw 'Dump failed' }
    & "$MysqlBin/mysql.exe" @client crm_test_restored -e ('source '+$backup.Replace('\','/'))
    if ($LASTEXITCODE) { throw 'Restore failed' }
    Select-Ledger 'crm_test_restored'
    $restored = Run-Php @('tests/subscription-recovery.php','verify',$manifest)
    if ($restored -cne $restarted -or (Run-Php @('tests/recovery-check.php',$evidence)) -cne $before) { throw 'Restored facts differ' }
    $checks = foreach ($company in $companies) { $preflight[$company] | ConvertFrom-Json }
    [ordered]@{at_utc=(Get-Date).ToUniversalTime().ToString('o'); baseline_tag='v1.1.0'; baseline_commit=$oldCommit;
        applied=$applied; old_ledger_checks=$checks; historic_attachments=(($before | ConvertFrom-Json).attachments);
        automatic_subscriptions=[int]$autoOpened; interrupted_producer=$true; restarted_mysql=$true;
        input=((Get-Content -Raw -LiteralPath $manifest | ConvertFrom-Json).payload); restored=($restored | ConvertFrom-Json);
        backup_sha256=(Get-FileHash -LiteralPath $backup).Hash.ToLowerInvariant(); backup_bytes=(Get-Item -LiteralPath $backup).Length
    } | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath "$run/subscription-recovery.json" -Encoding utf8NoBOM
    Write-Host "Verified upgrade, crash restart and restore: $run/subscription-recovery.json"
} finally {
    if ($null -ne $worker -and !$worker.HasExited) { Stop-Process -InputObject $worker; $worker.WaitForExit() }
    if ($null -ne $server -and !$server.HasExited) { & "$MysqlBin/mysqladmin.exe" @client shutdown | Out-Null }
    Pop-Location
}
