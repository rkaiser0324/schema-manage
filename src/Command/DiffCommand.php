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
        $params = new stdClass();

        // Notice the "backward" syntax here, compared to DBDiff
        $params->server2 = [
        'user'     => $config_before['username'],
        'password' => $config_before['password'],
        'host'     => $config_before['host'],
        'port'     => 3306
    ];

        $params->server1 = [
        'user'     => $config_after['username'],
        'password' => $config_after['password'],
        'host'     => $config_after['host'],
        'port'     => 3306
    ];

        $output_path = sprintf(ROOT . '/config/SchemaDiffs/%s/%s_%s.sql', $config_after['name'], strftime('%Y%m%d%H%M'), $diff_name);
        if (!is_dir(dirname($output_path))) {
            if (!mkdir(dirname($output_path), 0600, true)) {
                throw new \exception(sprintf("Cannot create directory %s", dirname($output_path)));
            }
        }
        $params->output = $output_path;
        $params->template='templates/simple-db-migrate.tmpl';
        $params->nocomments = true;

        $params->include='all';
        $params->input = [
    'kind' => 'db',
    'source' => ['server' => 'server1', 'db' =>  $config_after['database']],
    'target' => ['server' => 'server2', 'db' => $config_before['database']],
];

        exec("python3 -m venv venv \
    && ( \
        source /tmp/mysql8-utilities-python3/venv/bin/activate \
        && mysqldbcompare \
            --server1='root:dev@mysql' \
            --server2='root:dev@mysql' \
            --run-all-tests \
            --skip-row-count \
            --skip-data-check \
            --difftype=sql \
            --changes-for=server2 \
            digipowers_ravn_dev:digipowers_ravn > diff.sql \
        )");

        $this->_processSql('diff.sql', $output_path);
        if ($dry_run && file_exists($output_path)) {
            $io->out(file_get_contents($output_path));
            if (!unlink($output_path)) {
                throw new \exception("Cannot delete output file " . $output_path);
            }
        }
    }

    private function _processSql($inputFile, $outputFile)
    {
        // Read all lines from the input file
        $lines = file($inputFile, FILE_IGNORE_NEW_LINES);
        $result = [];
        $i = 0;

        while ($i < count($lines)) {
            $currentLine = trim($lines[$i]);

            // Check if current line matches ALTER TABLE pattern
            if (preg_match('/^ALTER TABLE `[^`]+`\.`[^`]+`$/', $currentLine)) {
                // Look backwards to check for the pattern: newline, comment lines, newline
                $alterTableIndex = $i;
                $patternFound = false;

                // Check if next line exists and matches AUTO_INCREMENT pattern
                if (($i + 1) < count($lines)) {
                    $nextLine = trim($lines[$i + 1]);
                    if (preg_match('/^AUTO_INCREMENT=\d+;$/', $nextLine)) {
                        // Now check backwards for the pattern
                        $j = $i - 1;

                        // Must have a newline (empty line) before ALTER TABLE
                        if ($j >= 0 && trim($lines[$j]) === '') {
                            $j--;
                            $foundComments = false;

                            // Look for comment lines (lines starting with #)
                            while ($j >= 0 && preg_match('/^#/', trim($lines[$j]))) {
                                $foundComments = true;
                                $j--;
                            }

                            // Must have found at least one comment line and have a newline before comments
                            if ($foundComments && $j >= 0 && trim($lines[$j]) === '') {
                                $patternFound = true;

                                // Remove all lines from the pattern start to after AUTO_INCREMENT
                                // $j points to the line before the first newline
                                // We want to remove from $j+1 (first newline) to $i+1 (AUTO_INCREMENT line)

                                // Remove the pattern from result if it was already added
                                $linesToRemove = ($i + 2) - ($j + 1);
                                for ($k = 0; $k < $linesToRemove; $k++) {
                                    if (count($result) > 0) {
                                        array_pop($result);
                                    }
                                }

                                // Skip to after AUTO_INCREMENT line
                                $i = $alterTableIndex + 2;
                                continue;
                            }
                        }
                    }
                }
            }

            // If we reach here, add the line to result
            $result[] = $lines[$i];
            $i++;
        }

        // Strip all lines that are newlines or start with "#"
        $resultArr = [];
        foreach ($result as $line) {
            $trimmedLine = trim($line);
            // Skip empty lines or lines starting with #
            if ($trimmedLine !== '' && !preg_match('/^#/', $trimmedLine)) {
                $resultArr[] = $line;
            }
        }

        $resultStr = implode(PHP_EOL, $resultArr);

        // Strip out ",\nAUTO_INCREMENT=number;" patterns within lines
        $resultStr = preg_replace('/,\s*\nAUTO_INCREMENT=\d+;/', ';', $resultStr);

        // Write the result to output file
        file_put_contents($outputFile, $resultStr . PHP_EOL);

        echo "Processed file saved as: $outputFile\n";
    }
}
