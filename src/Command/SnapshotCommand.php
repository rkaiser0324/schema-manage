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
 * snapshot - snapshot a schema.  If `connection_name` is not specified it defaults to "default".
 *
 *      bin/cake schema_manage.snapshot <snapshot_name> [connection_name] [--dry-run]
 *
 */
class SnapshotCommand extends DiffCommand
{
    protected function buildOptionParser(ConsoleOptionParser $parser) : ConsoleOptionParser
    {
        $parser->addArguments([
            'name' => [
                'help' => 'Name for the snapshot, alphanumeric only',
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

    public function execute(Arguments $args, ConsoleIo $io)
    {
        $params = new \DBDiff\Params\DefaultParams;

        $diff_name = $args->getArguments()[0];
        $connection_name = empty($args->getArguments()[1]) ? 'default' : $args->getArguments()[1];
        $conn_before = ConnectionManager::get($connection_name);
        $conn_after = $conn_before;
        $params->snapshot = true;

        try {
            $this->_diff($io, $params, $conn_before->config(), $conn_after->config(), $diff_name, $args->getOption('dry-run'));

            $this->_cleanup($io);
        } catch (\exception $ex) {
            $io->error($ex->getMessage());
        }
    }
}
