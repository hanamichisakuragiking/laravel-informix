<?php

namespace Hanamichisakuragiking\LaravelInformix;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use PDO;

class InformixServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // Register the Informix connection resolver
        Connection::resolverFor('informix', function ($connection, $database, $prefix, $config) {
            $connector = new InformixConnector();
            $pdo = $connector->connect($config);
            
            // Enable auto-commit for Informix logging mode databases
            // This must be set after connection as PDO_INFORMIX ignores it in constructor options
            $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
            
            // Set column names to lowercase for Laravel compatibility
            // PDO_INFORMIX returns UPPERCASE column names by default
            $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
            
            return new InformixConnection($pdo, $database, $prefix, $config);
        });
    }

    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        // Publish configuration if needed
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/informix.php' => config_path('informix.php'),
            ], 'config');
        }
    }
}
