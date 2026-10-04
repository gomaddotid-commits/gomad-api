<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArchitectureRulesTest extends TestCase
{
    public function test_domain_and_application_boundaries_exist(): void
    {
        $this->assertDirectoryExists(app_path('Domain'));
        $this->assertDirectoryExists(app_path('Application'));
    }

    #[DataProvider('layerDirectories')]
    public function test_domain_and_application_code_do_not_depend_on_http_adapters(string $layer): void
    {
        $directory = app_path($layer);
        $this->assertFileExists($directory.'/README.md');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression(
                '/Illuminate\\\\Http\\\\Request|Inertia\\\\|(?<![A-Za-z0-9_])(?:request|session|auth)\s*\(/',
                $source,
                "{$file->getPathname()} depends on an HTTP adapter.",
            );
        }
    }

    public static function layerDirectories(): array
    {
        return [
            'domain' => ['Domain'],
            'application' => ['Application'],
        ];
    }
}
