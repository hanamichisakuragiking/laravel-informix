<?php

namespace Hanamichisakuragiking\LaravelInformix;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;

class InformixConnector extends Connector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     *
     * @param  array  $config
     * @return PDO
     */
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);

        $connection = $this->createConnection($dsn, $config, $options);

        return $connection;
    }

    /**
     * Create the DSN string for Informix.
     *
     * @param  array  $config
     * @return string
     */
    protected function getDsn(array $config): string
    {
        // Required parameters
        $host = $config['host'] ?? 'localhost';
        $service = $config['service'] ?? $config['port'] ?? '9088';
        $database = $config['database'];
        $server = $config['server'];
        $protocol = $config['protocol'] ?? 'onsoctcp';

        // Build base DSN
        $dsn = "informix:host={$host};service={$service};database={$database};server={$server};protocol={$protocol}";

        // Add locale settings if specified
        if (!empty($config['db_locale'])) {
            $dsn .= ";DB_LOCALE={$config['db_locale']}";
        }

        if (!empty($config['client_locale'])) {
            $dsn .= ";CLIENT_LOCALE={$config['client_locale']}";
        }

        // Add EnableScrollableCursors for better compatibility
        $dsn .= ";EnableScrollableCursors=1";

        return $dsn;
    }

    /**
     * Get the PDO options based on the configuration.
     *
     * @param  array  $config
     * @return array
     */
    public function getOptions(array $config): array
    {
        $options = $config['options'] ?? [];

        return array_merge([
            PDO::ATTR_CASE => PDO::CASE_LOWER,  // Return lowercase column names for Laravel compatibility
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_AUTOCOMMIT => true,  // Enable auto-commit for Informix logging mode
        ], $options);
    }
}
