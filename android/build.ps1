<#
  Build the PlastiLink Android test app (APK) without Gradle.

    powershell -ExecutionPolicy Bypass -File android\build.ps1 -ServerUrl http://192.168.1.20/PlastiLink/

  -ServerUrl  The address the app opens (must end with a slash). Use https:// for a live site.
              A plain http:// address is allowed ONLY for that one host (network security config), so use it for local testing only.
  -Tools      Folder holding the JDK and Android SDK (default C:\Android-build): jdk\, sdk\build-tools\34.0.0, sdk\platforms\android-34
  Output:     deploy\dist\plastilink-test.apk (signed with a test key kept in <Tools>\keys, never in the project)
#>
param(
    [Parameter(Mandatory = $true)][string]$ServerUrl,
    [string]$Tools = 'C:\Android-build',
    [string]$Version = '1.0.0',
    [int]$VersionCode = 1
)
$ErrorActionPreference = 'Continue'

if ($ServerUrl -notmatch '^https?://[^/\s]+/.*/$|^https?://[^/\s]+/$') { throw 'ServerUrl must be a full address ending with a slash, e.g. http://192.168.1.20/PlastiLink/' }
$uri = [uri]$ServerUrl
$project = Split-Path -Parent $PSScriptRoot
$src = Join-Path $project 'android'
$work = Join-Path $src 'build'
$bt = Join-Path $Tools 'sdk\build-tools\34.0.0'
$jar = Join-Path $Tools 'sdk\platforms\android-34\android.jar'
$env:JAVA_HOME = Join-Path $Tools 'jdk'
$env:Path = "$env:JAVA_HOME\bin;$bt;" + $env:Path
foreach ($need in @("$bt\aapt2.exe", "$bt\d8.bat", "$bt\zipalign.exe", "$bt\apksigner.bat", $jar, "$env:JAVA_HOME\bin\javac.exe")) {
    if (-not (Test-Path $need)) { throw "Missing build tool: $need" }
}
function Run([string]$what) { if ($LASTEXITCODE -ne 0) { throw "$what failed (exit $LASTEXITCODE)" } }

# Fresh working copy with generated settings
if (Test-Path $work) { Remove-Item $work -Recurse -Force }
New-Item -ItemType Directory -Force "$work\res", "$work\classes", "$work\dex", "$work\gen" | Out-Null
Copy-Item "$src\res\*" "$work\res" -Recurse
New-Item -ItemType Directory -Force "$work\res\xml" | Out-Null

$start = $ServerUrl + 'dashboard'
@"
package org.plastilink.app;

final class Config {
    static final String START_URL = "$start";
    static final String VERSION = "$Version";
    private Config() {}
}
"@ | Set-Content "$work\gen\Config.java" -Encoding ascii

if ($uri.Scheme -eq 'http') {
    @"
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <base-config cleartextTrafficPermitted="false" />
    <domain-config cleartextTrafficPermitted="true">
        <domain includeSubdomains="false">$($uri.Host)</domain>
    </domain-config>
</network-security-config>
"@ | Set-Content "$work\res\xml\network_security_config.xml" -Encoding ascii
} else {
    @"
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <base-config cleartextTrafficPermitted="false" />
</network-security-config>
"@ | Set-Content "$work\res\xml\network_security_config.xml" -Encoding ascii
}

# 1. resources, 2. compile Java, 3. dex, 4. package, 5. align, 6. sign
& "$bt\aapt2.exe" compile --dir "$work\res" -o "$work\compiled.zip"; Run 'aapt2 compile'
& "$bt\aapt2.exe" link -o "$work\base.apk" -I $jar --manifest "$src\AndroidManifest.xml" -A "$src\assets" `
    --min-sdk-version 24 --target-sdk-version 34 --version-code $VersionCode --version-name $Version "$work\compiled.zip"; Run 'aapt2 link'

$javaFiles = @(Get-ChildItem "$src\src" -Recurse -Filter *.java | ForEach-Object { $_.FullName }) + "$work\gen\Config.java"
& javac --release 8 -Xlint:-options -encoding UTF-8 -cp $jar -d "$work\classes" $javaFiles; Run 'javac'
$classes = @(Get-ChildItem "$work\classes" -Recurse -Filter *.class | ForEach-Object { $_.FullName })
& "$bt\d8.bat" --release --min-api 24 --lib $jar --output "$work\dex" $classes; Run 'd8'
Push-Location "$work\dex"; & jar --update --file "$work\base.apk" classes.dex; $jarExit = $LASTEXITCODE; Pop-Location
if ($jarExit -ne 0) { throw 'adding classes.dex failed' }
& "$bt\zipalign.exe" -f -p 4 "$work\base.apk" "$work\aligned.apk"; Run 'zipalign'

# Test signing key: created once, kept outside the project
$keys = Join-Path $Tools 'keys'
New-Item -ItemType Directory -Force $keys | Out-Null
$ks = Join-Path $keys 'plastilink-test.jks'
$pw = Join-Path $keys 'keystore.pass'
if (-not (Test-Path $ks)) {
    $bytes = New-Object byte[] 18; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    [Convert]::ToBase64String($bytes).Replace('+', 'A').Replace('/', 'B').Replace('=', 'C') | Set-Content $pw -Encoding ascii
    & keytool -genkeypair -keystore $ks -storepass:file $pw -keypass:file $pw -alias plastilink-test -keyalg RSA -keysize 2048 -validity 10000 -dname 'CN=PlastiLink test, O=PlastiLink, C=UG'; Run 'keytool'
}
$dist = Join-Path $project 'deploy\dist'
New-Item -ItemType Directory -Force $dist | Out-Null
$apk = Join-Path $dist 'plastilink-test.apk'
& "$bt\apksigner.bat" sign --ks $ks --ks-pass "file:$pw" --ks-key-alias plastilink-test --out $apk "$work\aligned.apk"; Run 'apksigner sign'
& "$bt\apksigner.bat" verify --verbose $apk | Select-Object -First 4
Remove-Item $work -Recurse -Force

$hash = (Get-FileHash $apk -Algorithm SHA256).Hash.ToLower()
Write-Output ("Built {0} ({1} KB)" -f $apk, [int]((Get-Item $apk).Length / 1KB))
Write-Output "Opens:  $start"
Write-Output "SHA-256: $hash"
