<?php

namespace SchemaManage\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use Cake\Core\Configure;
use stdClass;

/**
 * Generate schema diffs in SQL, as an alternative to the Migrations plugin.  Unless the `--dry-run` option is added, files are output to:
 *
 *      ROOT/config/SchemaDiffs/<connection_after>/*.sql
 *
 * diff - diff between two schemas.  Note that the order of connections in the command is reversed, compared to standard DBDiff - but it makes more sense.
 *
 *      bin/cake schema_manage.diff <diff_name> <connection_before> <connection_after> [--dry-run]
 *
 */
class DiffCommand extends \Cake\Command\Command
{
    protected $_connection_no_db;

    protected $_tempdb_name;

    public function initialize(): void
    {
        parent::initialize();

        require dirname(__FILE__) .'/../../vendor/autoload.php';
    }

    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->addArguments([
            'name' => [
                'help' => 'Name for the diff, alphanumeric only',
                'required' => true
            ],
            'conn_before' => [
                'help' => 'Name of the connection before',
                'required' => true
            ],
            'conn_after' => [
                'help' => 'Name of the connection after',
                'required' => true
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
        $diff_name = $args->getArguments()[0];
        $conn_before = ConnectionManager::get($args->getArguments()[1]);
        $conn_after = ConnectionManager::get($args->getArguments()[2]);

        try {
            $this->_diff($io, $conn_before->config(), $conn_after->config(), $diff_name, $args->getOption('dry-run'));

            $this->_cleanup($io);
        } catch (\exception $ex) {
            $io->error($ex->getMessage());
        }
    }

    protected function _cleanup($io)
    {
        if (!empty($this->_tempdb_name)) {
            $io->out("Dropping temporary database " .$this->_tempdb_name);
            $this->_connection_no_db->query("DROP DATABASE " . $this->_tempdb_name);
        }

        $io->success('Done.');
    }

    protected function _diff($io, $config_before, $config_after, $diff_name, $dry_run = false)
    {
        if (empty($config_before)) {
            throw new \exception("No configuration for 'before' connection; snapshot not implemented yet");
        }

        $output_path = sprintf(ROOT . '/config/SchemaDiffs/%s/%s_%s.sql', $config_after['name'], strftime('%Y%m%d%H%M%S'), $diff_name);
        if (!is_dir(dirname($output_path))) {
            if (!mkdir(dirname($output_path), 0600, true)) {
                throw new \exception(sprintf("Cannot create directory %s", dirname($output_path)));
            }
        }

        $cmd = sprintf(
            "schemadiff diff --source '%s:%s@tcp(%s:%s)/%s' --target '%s:%s@tcp(%s:%s)/%s'",
            $config_before['username'],
            $config_before['password'],
            $config_before['host'],
            $config_before['port'] ?? 3306,
            $config_before['database'],
            $config_after['username'],
            $config_after['password'],
            $config_after['host'],
            $config_after['port'] ?? 3306,
            $config_after['database']
        );

        //$io->info($cmd);

        $output = [];
        exec($cmd, $output);
        if (empty($output)) {
            $io->out("<comment>No output from schemadiff command - schemas are identical</comment>");
            return;
        }

        $result = "#---------- UP ----------\n" . implode("\n", $output);

        $cmd = sprintf(
            "schemadiff diff --target '%s:%s@tcp(%s:%s)/%s' --source '%s:%s@tcp(%s:%s)/%s'",
            $config_before['username'],
            $config_before['password'],
            $config_before['host'],
            $config_before['port'] ?? 3306,
            $config_before['database'],
            $config_after['username'],
            $config_after['password'],
            $config_after['host'],
            $config_after['port'] ?? 3306,
            $config_after['database'],
            $output_path
        );

        $output = [];
        exec($cmd, $output);
        $result .= "\n#---------- DOWN ----------\n" . implode("\n", $output);

        if (!file_put_contents($output_path, $result)) {
            throw new \exception("Cannot write output file " . $output_path);
        }

        if ($dry_run && file_exists($output_path)) {
            $io->out(file_get_contents($output_path));
            if (!unlink($output_path)) {
                throw new \exception("Cannot delete output file " . $output_path);
            }
        }
    }
}
