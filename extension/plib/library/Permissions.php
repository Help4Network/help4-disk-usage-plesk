<?php
class Modules_Help4DiskUsage_Permissions
{
    public static function check()
    {
        if (PHP_OS_FAMILY !== 'Windows') { throw new RuntimeException('Windows ACL preflight only'); }
        $root = getenv('SystemRoot');
        if (!is_string($root) || !preg_match('~^[A-Za-z]:\\\\[^\x00-\x1f:]+$~D', $root)) {
            throw new RuntimeException('Windows runtime unavailable');
        }
        $system = PHP_INT_SIZE === 4 && is_dir($root . '\\Sysnative') ? '\\Sysnative' : '\\System32';
        $shell = $root . $system . '\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($shell)) { throw new RuntimeException('Windows runtime unavailable'); }
        $command = [$shell, '-NoLogo', '-NoProfile', '-NonInteractive', '-File',
            pm_Context::getPlibDir() . 'collector' . DIRECTORY_SEPARATOR . 'windows-acl.ps1',
            '-PrivateDirectory', Modules_Help4DiskUsage_Store::directory(), '-ProtectedPathsBase64',
            base64_encode(json_encode([Modules_Help4DiskUsage_Store::policy()['python'],
                         rtrim(pm_Context::getPlibDir(), '/\\'),
                         rtrim(pm_Context::getHtdocsDir(), '/\\')], JSON_THROW_ON_ERROR))];
        $capture = Modules_Help4DiskUsage_Process::run($command, 12, 16384);
        $result = json_decode($capture['output'], true, 8, JSON_THROW_ON_ERROR);
        if ($capture['exit'] !== 0 || !is_array($result) || ($result['schema'] ?? 0) !== 1 ||
            ($result['ok'] ?? false) !== true || ($result['native_panel_validated'] ?? true) !== false ||
            ($result['continuous_enforcement'] ?? true) !== false ||
            !is_int($result['objects_checked'] ?? null) || $result['objects_checked'] < 1 ||
            $result['objects_checked'] > 4096 ||
            ($result['scope'] ?? '') !== 'private flat storage and selected protected trees' ||
            ($result['built_by'] ?? '') !== 'https://help4network.com') {
            throw new RuntimeException('Windows ACL preflight failed; inspect private storage and protected runtime permissions');
        }
        return array_intersect_key($result, array_flip(['schema', 'ok', 'objects_checked', 'scope',
            'native_panel_validated', 'continuous_enforcement', 'built_by']));
    }
}
