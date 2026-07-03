<?php

namespace App\Traits;

use Exception;
use Illuminate\Support\Facades\File;

trait AddonHelper
{
    public function get_addons(): array
    {
        $dir = 'Modules';
        $directories = self::getDirectories($dir);
        $addons = [];
        foreach ($directories as $directory) {
            $sub_dirs = self::getDirectories('Modules/' . $directory);
            if (in_array('Addon', $sub_dirs)) {
                $addons[] = 'Modules/' . $directory;
            }
        }

        $array = [];
        foreach ($addons as $item) {
            $full_data = include($item . '/Addon/info.php');
            $array[] = [
                'addon_name' => $full_data['name'],
                'software_id' => $full_data['software_id'],
                'is_published' => $full_data['is_published'],
            ];
        }

        return $array;
    }

    public function get_addon_admin_routes(): array
    {
        $dir = 'Modules';
        $directories = self::getDirectories($dir);
        $addons = [];
        foreach ($directories as $directory) {
            $sub_dirs = self::getDirectories('Modules/' . $directory);
            if (in_array('Addon', $sub_dirs)) {
                $addons[] = 'Modules/' . $directory;
            }
        }

        $full_data = [];
        foreach ($addons as $item) {
            $info = include(base_path($item . '/Addon/info.php'));
            if ($info['is_published']){
                $full_data[] = include(base_path($item . '/Addon/admin_routes.php'));
            }
        }

        return $full_data;
    }

    public function get_payment_publish_status(): array
    {
        $dir = 'Modules'; // Update the directory path to Modules/Gateways
        $directories = self::getDirectories($dir);
        $addons = [];
        foreach ($directories as $directory) {
            $sub_dirs = self::getDirectories($dir . '/' . $directory); // Use $dir instead of 'Modules/'
            if($directory == 'Gateways'){
                if (in_array('Addon', $sub_dirs)) {
                    $addons[] = $dir . '/' . $directory; // Use $dir instead of 'Modules/'
                }
            }
        }

        $array = [];
        foreach ($addons as $item) {
            $full_data = include($item . '/Addon/info.php');
            $array[] = [
                'is_published' => $full_data['is_published'],
            ];
        }

        return $array;
    }

    function getDirectories(string $path): array
    {
        $module_dir = base_path('Modules');

        try {
            if (!File::exists($module_dir)) {
                File::makeDirectory($module_dir);
                File::chmod($module_dir, 0777);
            }
        } catch (Exception $e) {

        }
        $directories = [];
        $path = base_path($path);
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item == '..' || $item == '.')
                continue;
            if (is_dir($path . '/' . $item))
                $directories[] = $item;
        }

        return $directories;
    }

//    public function checkSystemAddonsSymbolicLink(): void
//    {
//        $modulesName = array_keys($this->getModuleNameList());
//        if (!File::exists(base_path('public/Modules'))) {
//            File::makeDirectory(base_path('public/Modules'),0777,true);
//
//        }
//
//        foreach ($modulesName as $moduleName) {
//            if (File::exists(base_path("Modules/{$moduleName}"))) {
//                $modulePath = base_path("public/Modules/{$moduleName}");
//                if (!File::exists($modulePath) || !File::exists($modulePath.'/public') || !File::exists($modulePath.'/Resources')) {
//                    try {
//                        File::makeDirectory($modulePath, 0777, true);
//
//                        $targetPath = base_path("public/Modules/{$moduleName}/test.php");
//                        file_put_contents($targetPath, "<?php\n\nreturn [\n    'module' => '{$moduleName}',\n];\n");
//
//                        // Create symbolic links
//                        if (DOMAIN_POINTED_DIRECTORY == 'public' && function_exists('shell_exec')) {
//                            shell_exec("ln -s ../Modules/{$moduleName}/public ". $modulePath);
//                            shell_exec("ln -s ../Modules/{$moduleName}/Resources ". $modulePath);
//                        } else {
//                            shell_exec("ln -s " . base_path("Modules/{$moduleName}/public") . " " . $modulePath);
//                            shell_exec("ln -s " . base_path("Modules/{$moduleName}/Resources") . " " . $modulePath);
//                        }
//                    } catch (Exception $e) {
//                    }
//                }
//            }
//        }
//    }


    public function checkSystemAddonsSymbolicLink(): void
    {
        $modulesName = array_keys($this->getModuleNameList());
        $publicModulesPath = base_path('public/Modules');

        // Ensure public/Modules exists
        if (!File::exists($publicModulesPath)) {
            File::makeDirectory($publicModulesPath, 0775, true);
        }

        foreach ($modulesName as $moduleName) {
            $sourceModulePath = base_path("Modules/{$moduleName}");
            $targetModulePath = "{$publicModulesPath}/{$moduleName}";

            if (!File::exists($sourceModulePath)) {
                continue;
            }

            // Ensure writable public/Modules path
            if (!is_writable($publicModulesPath)) {
                \Log::warning("public/Modules is not writable. Skipping link creation for {$moduleName}");
                continue;
            }

            // If not already linked
            if (!File::exists($targetModulePath)) {
                try {
                    File::makeDirectory($targetModulePath, 0775, true);
                } catch (\Exception $e) {
                    \Log::warning("Failed to create directory {$targetModulePath}: " . $e->getMessage());
                    continue;
                }

                // Test file (for debugging)
                file_put_contents("{$targetModulePath}/test.php", "<?php return ['module' => '{$moduleName}'];");

                // Create symlinks (if allowed)
                if (function_exists('shell_exec')) {
                    try {
                        if (DOMAIN_POINTED_DIRECTORY === 'public') {
                            shell_exec("ln -s ../Modules/{$moduleName}/public {$targetModulePath}/public");
                            shell_exec("ln -s ../Modules/{$moduleName}/Resources {$targetModulePath}/Resources");
                        } else {
                            shell_exec("ln -s " . base_path("Modules/{$moduleName}/public") . " {$targetModulePath}/public");
                            shell_exec("ln -s " . base_path("Modules/{$moduleName}/Resources") . " {$targetModulePath}/Resources");
                        }
                    } catch (\Exception $e) {
                        \Log::warning("Symbolic link failed for {$moduleName}: " . $e->getMessage());
                    }
                } else {
                    \Log::info("shell_exec is disabled — skipping symlink for {$moduleName}");
                }
            }
        }
    }

    public function getModuleNameList(): array
    {
        $moduleFileJsonData = [];
        $modulesStatusesFile = base_path('modules_statuses.json');
        if (File::exists($modulesStatusesFile)) {
            $moduleFileJsonData = json_decode(File::get($modulesStatusesFile), true);
        }
        return $moduleFileJsonData;
    }
}
