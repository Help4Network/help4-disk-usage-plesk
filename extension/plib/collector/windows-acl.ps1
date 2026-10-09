#Requires -Version 5.1
param([string]$PrivateDirectory = '', [string]$ProtectedPathsJson = '[]', [string]$PanelUser = 'psaadm',
      [int]$MaxObjects = 512, [int]$MaxSeconds = 10)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try {
    Import-Module (Join-Path $PSScriptRoot 'WindowsAcl.psm1') -Force -WarningAction SilentlyContinue
    $paths = @($ProtectedPathsJson | ConvertFrom-Json -ErrorAction Stop)
    if (@($paths | Where-Object { $_ -isnot [string] }).Count) { throw 'unsafe_path' }
    Get-H4AclPreflight $PrivateDirectory $paths $PanelUser $MaxObjects $MaxSeconds | ConvertTo-Json -Compress
    exit 0
} catch {
    $reason = $_.Exception.Message
    if ($reason -notin @('windows_required', 'invalid_limits', 'invalid_panel_user', 'panel_user_unavailable',
        'unsafe_path', 'ntfs_required', 'reparse_path', 'untrusted_owner', 'null_dacl', 'unsupported_ace',
        'foreign_private_access', 'foreign_write_access', 'private_directory_required',
        'unexpected_private_directory', 'time_limit', 'object_limit')) { $reason = 'metadata_unavailable' }
    @{ schema = 1; ok = $false; reason = $reason; native_panel_validated = $false;
       continuous_enforcement = $false; built_by = 'https://help4network.com' } | ConvertTo-Json -Compress
    exit 2
}
