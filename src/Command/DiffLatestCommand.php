<?php
namespace SchemaManage\Command;

use Cake\Console\Arguments;
use Cake\Console\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Use DBDiff to generate schema diffs in SQL, as an alternative to the Migrations plugin.  Unless the `--dry-run` option is added, files are output to:
 *
 *      ROOT/config/SchemaDiffs/<connection_name>/*.sql
 *
 * diff_latest - get a diff from the schema constructed by all the diff files (i.e., the "working tree").  If `connection_name` is not specified it defaults to "default".
 *
 *      bin/cake schema_manage.diff_latest <diff_name> <connection_name> [--dry-run]
 *
 */
class DiffLatestCommand extends DiffCommand
{
    protected function buildOptionParser(ConsoleOptionParser $parser) : ConsoleOptionParser
    {
        $parser->addArguments([
            'name' => [
                'help' => 'Name for the diff, alphanumeric only',
                'required' => true
            ],
            'connection' => [
                'help' => 'Name of the connection, defaults to "default"'
            ]
        ]);

        $parser->addOptions([
            'dry-run' => [
                'help' => 'Dry run',
                'short' => 'd',
                'boolean' => true
            ]
            ]);

        return $parser;
    }

    public function _diff_latest($connection_name, ConsoleIo $io)
    {
        $connection = ConnectionManager::get(empty($connection_name) ? 'default' : $connection_name);
        $config = $connection->config();

        ConnectionManager::setConfig('no_db', [
            'url' => sprintf('mysql://%s:%s@%s/', $config['username'], $config['password'], $config['host'])
            ]);
        $this->_connection_no_db = ConnectionManager::get('no_db');

        $this->_tempdb_name = 'aaa_tempdb_' . strftime('%Y%m%d%H%M%S');
        $io->out("Creating temporary database " .$this->_tempdb_name);
        $this->_connection_no_db->query("CREATE DATABASE IF NOT EXISTS " . $this->_tempdb_name);

        ConnectionManager::setConfig('tempdb', [
            'url' => sprintf('mysql://%s:%s@%s/%s', $config['username'], $config['password'], $config['host'], $this->_tempdb_name)
            ]);
        $connection_tempdb = ConnectionManager::get('tempdb');

        $io->out("Populating schema in database " .$this->_tempdb_name);

        $dir = ROOT . '/config/SchemaDiffs/' . $config['name'] . '/';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0600, true)) {
                throw new \exception(sprintf("Cannot create directory %s", $dir));
            }
        }
        foreach (glob($dir . '*.sql') as $filename) {
            $io->out("Applying diff from " .$filename);
            $contents = file_get_contents($filename);
            if ($matches = preg_split('@#(.+?)[\r\n]@', $contents, null, PREG_SPLIT_NO_EMPTY)) {
                $up_sql = $matches[0];

                // Execute each statement individually for better error handling
                foreach (preg_split('@;[\r\n]@', $up_sql, null, PREG_SPLIT_NO_EMPTY) as $s) {
                    $buffer = $connection_tempdb->prepare($s);
                    try {
                        $statement = $buffer->execute();
                    } catch (\exception $ex) {
                        throw new \exception(sprintf("SQL error in %s\n%s\n%s", $filename, $ex->getMessage(), $s));
                    }
                }
            } else {
                throw new \exception("Cannot parse " . $filename);
            }
        }
        return $connection_tempdb;
    }

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $params = new \DBDiff\Params\DefaultParams;

        $diff_name = $args->getArguments()[0];
        $connection_name = empty($args->getArguments()[1]) ? 'default' : $args->getArguments()[1];
        $conn_before = $this->_diff_latest($connection_name, $io);
        $conn_after = ConnectionManager::get($connection_name);

        try {
            $this->_diff($io, $params, $conn_before->config(), $conn_after->config(), $diff_name, $args->getOption('dry-run'));

            $this->_cleanup($io);
        } catch (\exception $ex) {
            $io->error($ex->getMessage());
        }
    }
}
