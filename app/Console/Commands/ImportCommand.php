<?php

namespace App\Console\Commands;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'labman:import {path : YAML file to import} {--replace : Remove servers and services that are not in the file}';

    protected $description = 'Import servers, services and settings from a YAML file (upsert by name)';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        try {
            $config = YamlConfig::parse((string) file_get_contents($path));
        } catch (ConfigException $e) {
            foreach ($e->errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $result = YamlConfig::import($config, (bool) $this->option('replace'));

        $this->info("Imported {$result['servers']} server(s) and {$result['services']} service(s)".($result['removed'] ? "; removed {$result['removed']}" : '').'.');

        return self::SUCCESS;
    }
}
