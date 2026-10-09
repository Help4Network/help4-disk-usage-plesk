#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-H4SecurityDescriptor {
    param($Descriptor, [string[]]$Trusted, [ValidateSet('private', 'protected', 'ancestor')][string]$Role)
    if ($null -eq $Descriptor.Owner -or $Descriptor.Owner.Value -notin $Trusted) { throw 'untrusted_owner' }
    if ($null -eq $Descriptor.DiscretionaryAcl -or
        (([int]$Descriptor.ControlFlags -band [int][Security.AccessControl.ControlFlags]::DiscretionaryAclPresent) -eq 0)) {
        throw 'null_dacl'
    }
    foreach ($ace in $Descriptor.DiscretionaryAcl) {
        if ($ace -isnot [Security.AccessControl.CommonAce] -or $ace.IsCallback -or
            $ace.AceQualifier -notin @([Security.AccessControl.AceQualifier]::AccessAllowed,
                                      [Security.AccessControl.AceQualifier]::AccessDenied)) { throw 'unsupported_ace' }
        if ($ace.AceQualifier -eq [Security.AccessControl.AceQualifier]::AccessDenied) { continue }
        if ($ace.SecurityIdentifier.Value -in $Trusted) { continue }
        $mask = [BitConverter]::ToUInt32([BitConverter]::GetBytes([int]$ace.AccessMask), 0)
        if ($Role -eq 'private' -and $mask -ne 0) { throw 'foreign_private_access' }
        # Inspect inherit-only grants on protected trees too; new files inherit them.
        if ($Role -eq 'ancestor' -and
            (([int]$ace.AceFlags -band [int][Security.AccessControl.AceFlags]::InheritOnly) -ne 0)) { continue }
        $writeMask = [uint32]0x500D0156
        if ($Role -eq 'ancestor') { $writeMask = [uint32]0x500D0152 }
        if ($mask -band $writeMask) { throw 'foreign_write_access' }
    }
}

function Assert-H4Budget {
    param($State)
    if ($State.Clock.Elapsed.TotalSeconds -ge $State.Seconds) { throw 'time_limit' }
    if ($State.Objects -ge $State.Maximum) { throw 'object_limit' }
}

function Get-H4LocalPath {
    param([string]$Path)
    if ($Path.Length -gt 4096 -or $Path -notmatch '^[A-Za-z]:[\\/]' -or
        $Path.Substring(2).Contains(':') -or $Path -match '[\x00-\x1f<>"|?*]') { throw 'unsafe_path' }
    $parts = @($Path.Substring(3).TrimEnd([char[]]'\/') -split '[\\/]')
    if ($parts.Count -eq 0 -or @($parts | Where-Object { $_ -in @('', '.', '..') -or $_ -match '[. ]$' -or
        $_ -match '^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)' }).Count) {
        throw 'unsafe_path'
    }
    return [IO.Path]::GetFullPath($Path).TrimEnd([char[]]'\')
}

function Assert-H4Object {
    param([string]$Path, [string]$Role, [string[]]$Trusted, $State)
    Assert-H4Budget $State
    $key = $Role + ':' + $Path
    if ($State.Seen.Contains($key)) { return }
    [void]$State.Seen.Add($key)
    $State.Objects++
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (([int]$item.Attributes -band [int][IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'reparse_path' }
    $acl = Get-Acl -LiteralPath $Path -ErrorAction Stop
    $raw = [Security.AccessControl.RawSecurityDescriptor]::new($acl.GetSecurityDescriptorBinaryForm(), 0)
    Test-H4SecurityDescriptor $raw $Trusted $Role
}

function Assert-H4Ancestors {
    param([string]$Path, [string[]]$Trusted, $State)
    $parents = [Collections.Generic.Stack[string]]::new()
    $parent = [IO.Directory]::GetParent($Path)
    while ($null -ne $parent) {
        $parents.Push($parent.FullName)
        $parent = $parent.Parent
    }
    # Check from the volume root before addressing a descendant through a junction.
    while ($parents.Count) { Assert-H4Object $parents.Pop() 'ancestor' $Trusted $State }
}

function Get-H4AclPreflight {
    param([string]$PrivateDirectory, [string[]]$ProtectedPaths, [string]$PanelUser = 'psaadm',
          [int]$MaxObjects = 512, [int]$MaxSeconds = 10)
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) { throw 'windows_required' }
    if ($MaxObjects -lt 1 -or $MaxObjects -gt 4096 -or $MaxSeconds -lt 1 -or $MaxSeconds -gt 10 -or
        @($ProtectedPaths).Count -lt 1 -or @($ProtectedPaths).Count -gt 8) { throw 'invalid_limits' }
    if ($PanelUser -notmatch '^[A-Za-z0-9_.-]{1,64}$') { throw 'invalid_panel_user' }
    $state = @{ Clock = [Diagnostics.Stopwatch]::StartNew(); Seconds = $MaxSeconds;
        Objects = 0; Maximum = $MaxObjects;
        Seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase) }
    $account = @(Get-LocalUser -Name $PanelUser -ErrorAction Stop)
    if ($account.Count -ne 1 -or -not $account[0].Enabled -or $account[0].PrincipalSource -ne 'Local') {
        throw 'panel_user_unavailable'
    }
    $privateTrusted = @('S-1-5-18', 'S-1-5-32-544', $account[0].SID.Value)
    $protectedTrusted = $privateTrusted + 'S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464'
    $private = Get-H4LocalPath $PrivateDirectory
    $targets = @($private) + @($ProtectedPaths | ForEach-Object { Get-H4LocalPath $_ })
    foreach ($target in $targets) {
        if ([IO.DriveInfo]::new([IO.Path]::GetPathRoot($target)).DriveFormat -ne 'NTFS') { throw 'ntfs_required' }
        Assert-H4Ancestors $target $protectedTrusted $state
    }
    Assert-H4Object $private 'private' $privateTrusted $state
    if (-not [IO.Directory]::Exists($private)) { throw 'private_directory_required' }
    # Audit storage is flat; do not recursively inspect an unexpected customer tree.
    foreach ($child in [IO.Directory]::EnumerateFileSystemEntries($private)) {
        Assert-H4Object $child 'private' $privateTrusted $state
        if ([IO.Directory]::Exists($child)) { throw 'unexpected_private_directory' }
    }
    $queue = [Collections.Generic.Queue[string]]::new()
    foreach ($target in ($targets | Select-Object -Skip 1)) { $queue.Enqueue($target) }
    while ($queue.Count) {
        $target = $queue.Dequeue()
        Assert-H4Object $target 'protected' $protectedTrusted $state
        if ([IO.Directory]::Exists($target)) {
            foreach ($child in [IO.Directory]::EnumerateFileSystemEntries($target)) {
                Assert-H4Budget $state
                Assert-H4Object $child 'protected' $protectedTrusted $state
                if ([IO.Directory]::Exists($child)) { $queue.Enqueue($child) }
            }
        }
    }
    return [pscustomobject]@{ schema = 1; ok = $true; objects_checked = $state.Objects;
        scope = 'private flat storage and selected protected trees'; native_panel_validated = $false;
        continuous_enforcement = $false; built_by = 'https://help4network.com' }
}

Export-ModuleMember -Function Get-H4AclPreflight, Test-H4SecurityDescriptor
