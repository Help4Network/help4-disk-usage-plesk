#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot '../extension/plib/collector/WindowsAcl.psm1') -Force
$module = Get-Module WindowsAcl
$script:checks = 0
function Check($Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
    $script:checks++
}
function Fails([scriptblock]$Action, [string]$Reason) {
    $message = ''
    try { & $Action } catch { $message = $_.Exception.Message }
    Check ($message -eq $Reason) ('Expected sanitized failure: ' + $Reason)
}
function Descriptor([string]$Sddl) { [Security.AccessControl.RawSecurityDescriptor]::new($Sddl) }
$trusted = @('S-1-5-18', 'S-1-5-32-544')
$safe = Descriptor 'O:SYG:SYD:(A;;FA;;;SY)(A;;FA;;;BA)'
foreach ($role in @('private', 'protected', 'ancestor')) {
    Test-H4SecurityDescriptor $safe $trusted $role
    Check $true ('Trusted descriptor: ' + $role)
}
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(A;;FR;;;BU)') $trusted 'private' } 'foreign_private_access'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(A;OICIIO;FR;;;BU)') $trusted 'private' } 'foreign_private_access'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(A;;GW;;;BU)') $trusted 'protected' } 'foreign_write_access'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(A;ID;GW;;;BU)') $trusted 'protected' } 'foreign_write_access'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(A;OICIIO;GW;;;BU)') $trusted 'protected' } 'foreign_write_access'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(D;;GA;;;BU)(A;;GW;;;BU)') $trusted 'protected' } 'foreign_write_access'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:BUG:SYD:(A;;FA;;;SY)') $trusted 'protected' } 'untrusted_owner'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SY') $trusted 'private' } 'null_dacl'
Fails { Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(OA;;GW;00000000-0000-0000-0000-000000000001;;BU)') $trusted 'protected' } 'unsupported_ace'
$callback = Descriptor 'O:SYG:SYD:(A;;FA;;;SY)'
$callback.DiscretionaryAcl.InsertAce(0, [Security.AccessControl.CommonAce]::new(
    [Security.AccessControl.AceFlags]::None, [Security.AccessControl.AceQualifier]::AccessAllowed,
    1, [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545'), $true, [byte[]]@(0,0,0,0)))
Fails { Test-H4SecurityDescriptor $callback $trusted 'protected' } 'unsupported_ace'
Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(A;;FRFX;;;BU)') $trusted 'protected'
Check $true 'Read/execute on protected code is permitted'
Test-H4SecurityDescriptor (Descriptor 'O:SYG:SYD:(A;OICIIO;GW;;;BU)') $trusted 'ancestor'
Check $true 'Inherit-only ancestor grant is evaluated on selected descendants'
foreach ($right in @('0x2', '0x40', '0x10000', '0x40000', '0x80000', '0x10000000')) {
    Fails { Test-H4SecurityDescriptor (Descriptor ('O:SYG:SYD:(A;;' + $right + ';;;BU)')) $trusted 'ancestor' } 'foreign_write_access'
}
& $module { Assert-H4Budget @{ Clock = @{ Elapsed = @{ TotalSeconds = 0 } }; Seconds = 1; Objects = 0; Maximum = 1 } }
Check $true 'Available budget'
Fails { & $module { Assert-H4Budget @{ Clock = @{ Elapsed = @{ TotalSeconds = 1 } }; Seconds = 1; Objects = 0; Maximum = 1 } } } 'time_limit'
Fails { & $module { Assert-H4Budget @{ Clock = @{ Elapsed = @{ TotalSeconds = 0 } }; Seconds = 1; Objects = 1; Maximum = 1 } } } 'object_limit'
foreach ($path in @('C:\', '\\server\share\private', '\\?\C:\private', 'C:\private:stream', 'C:\..\private',
    'C:\private.\x', 'C:\private \x', 'C:\private\\x', 'C:\NUL', 'C:\com1.txt', 'C:\x?y',
    'relative', ('C:\x' + [char]10))) {
    Fails { & $module { param($p) Get-H4LocalPath $p } $path } 'unsafe_path'
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$sid = $identity.User
$user = $identity.Name.Split('\')[-1]
$base = Join-Path ([IO.Path]::GetTempPath()) ('h4-acl-test-' + [Guid]::NewGuid().ToString('N'))
$private = Join-Path $base 'audit'
$code = Join-Path $base 'code'
$junction = Join-Path $private 'junction'
function Secure([string]$Path, [bool]$Directory) {
    $acl = if ($Directory) { [Security.AccessControl.DirectorySecurity]::new() } else { [Security.AccessControl.FileSecurity]::new() }
    $acl.SetOwner($sid)
    $acl.SetAccessRuleProtection($true, $false)
    $inherit = if ($Directory) { [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit' } else { [Security.AccessControl.InheritanceFlags]::None }
    $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl',
        $inherit, [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow))
    Set-Acl -LiteralPath $Path -AclObject $acl
}
function ForeignGrant([string]$Path, [string]$Rights) {
    $acl = Get-Acl -LiteralPath $Path
    $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
        [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545'), $Rights,
        [Security.AccessControl.AccessControlType]::Allow))
    Set-Acl -LiteralPath $Path -AclObject $acl
}
try {
    [void][IO.Directory]::CreateDirectory($base)
    Secure $base $true
    [void][IO.Directory]::CreateDirectory($private)
    [void][IO.Directory]::CreateDirectory($code)
    Secure $private $true
    Secure $code $true
    $report = Join-Path $private 'report.json'
    $program = Join-Path $code 'runtime.exe'
    [IO.File]::WriteAllText($report, '{}')
    [IO.File]::WriteAllText($program, '')
    Secure $report $false
    Secure $program $false
    $before = (Get-Acl -LiteralPath $private).Sddl
    $result = Get-H4AclPreflight $private @($code) $user
    Check ($result.ok -and $result.objects_checked -gt 4 -and -not $result.native_panel_validated -and
        -not $result.continuous_enforcement) 'Native NTFS preflight snapshot'
    Check ((Get-Acl -LiteralPath $private).Sddl -eq $before) 'Preflight does not change permissions'
    ForeignGrant $report 'Read'
    Fails { Get-H4AclPreflight $private @($code) $user } 'foreign_private_access'
    Secure $report $false
    ForeignGrant $program 'Write'
    Fails { Get-H4AclPreflight $private @($code) $user } 'foreign_write_access'
    Secure $program $false
    ForeignGrant $base 'DeleteSubdirectoriesAndFiles'
    Fails { Get-H4AclPreflight $private @($code) $user } 'foreign_write_access'
    Secure $base $true
    [void][IO.Directory]::CreateDirectory((Join-Path $private 'unexpected'))
    Fails { Get-H4AclPreflight $private @($code) $user } 'unexpected_private_directory'
    [IO.Directory]::Delete((Join-Path $private 'unexpected'))
    [void](New-Item -ItemType Junction -Path $junction -Target $code)
    Fails { Get-H4AclPreflight $private @($code) $user } 'reparse_path'
    [IO.Directory]::Delete($junction)
    Fails { Get-H4AclPreflight $private @($code) $user 1 10 } 'object_limit'
    Fails { Get-H4AclPreflight $private @($code) $user 4097 10 } 'invalid_limits'
    Fails { Get-H4AclPreflight $private @($code) $user 512 11 } 'invalid_limits'
    Fails { Get-H4AclPreflight $private @() $user } 'invalid_limits'
    Fails { Get-H4AclPreflight $private @($code) '*' } 'invalid_panel_user'
    $cli = Join-Path $PSScriptRoot '../extension/plib/collector/windows-acl.ps1'
    $shell = (Get-Process -Id $PID).Path
    $body = & $shell -NoLogo -NoProfile -NonInteractive -File $cli -PrivateDirectory $private -ProtectedPathsBase64 'W10=' -PanelUser $user
    $exit = $LASTEXITCODE
    $failure = ($body -join "`n") | ConvertFrom-Json
    Check ($exit -eq 2 -and -not $failure.ok -and $failure.reason -eq 'invalid_limits') 'CLI fails closed'
    Check (($body -join "`n") -notmatch [regex]::Escape($base) -and
        ($body -join "`n") -notmatch [regex]::Escape($sid.Value)) 'CLI response omits private identities and paths'
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject @($code) -Compress)))
    $body = & $shell -NoLogo -NoProfile -NonInteractive -File $cli -PrivateDirectory $private -ProtectedPathsBase64 $encoded -PanelUser $user
    $exit = $LASTEXITCODE
    $success = ($body -join "`n") | ConvertFrom-Json
    Check ($exit -eq 0 -and $success.ok -and -not $success.native_panel_validated -and
        -not $success.continuous_enforcement) 'CLI path-list round trip succeeds'
    foreach ($json in @('null', '"not-an-array"', '[false]', '[{}]')) {
        $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($json))
        $body = & $shell -NoLogo -NoProfile -NonInteractive -File $cli -PrivateDirectory $private -ProtectedPathsBase64 $encoded -PanelUser $user
        $exit = $LASTEXITCODE
        $failure = ($body -join "`n") | ConvertFrom-Json
        Check ($exit -eq 2 -and -not $failure.ok -and $failure.reason -eq 'unsafe_path') 'CLI rejects non-array/non-string paths'
    }
} finally {
    if ([IO.Directory]::Exists($junction)) { [IO.Directory]::Delete($junction) }
    if ([IO.Directory]::Exists($base)) { Remove-Item -LiteralPath $base -Recurse -Force }
}
Write-Output ("Windows ACL policy and native NTFS preflight: {0} checks passed" -f $script:checks)
exit 0
