<?php

/**
 * Ruckusing
 *
 * @category  Ruckusing
 * @package   Task
 * @subpackage Test
 * @author    Cody Caughlan <codycaughlan % gmail . com>
 * @link      https://github.com/ruckus/ruckusing-migrations
 */

/**
 * Task_Test_Migrate
 *
 * @category Ruckusing
 * @package  Task
 * @subpackage Test
 * @author   Cody Caughlan <codycaughlan % gmail . com>
 * @link      https://github.com/ruckus/ruckusing-migrations
 */
class Task_Test_Migrate extends Ruckusing_Task_Base implements Ruckusing_Task_Interface
{
    /**
     * migrator util
     *
     * @var Ruckusing_Util_Migrator
     */
    private $_migrator_util = null;

    /**
     * Current Adapter
     *
     * @var Ruckusing_Adapter_Base
     */
    private $_adapter = null;

    /**
     * Return executed string
     *
     * @var string
     */
    private $_return = '';

    /**
     * migrator directories
     *
     * @var string
     */
    private $_migratorDirs = null;

    /**
     * The task arguments
     *
     * @var array
     */
    private $_task_args = array();

    /**
     * Creates an instance of Task_Info_Migrates
     *
     * @param Ruckusing_Adapter_Base $adapter The current adapter being used
     *
     * @return Task_Info_Migrate
     */
    public function __construct($adapter)
    {
        parent::__construct($adapter);
        $this->_adapter = $adapter;
        $this->_migrator_util = new Ruckusing_Util_Migrator($this->_adapter);
    }

    /**
     * Primary task entry point
     *
     * @param array $args The current supplied options.
     */
    public function execute($args)
    {
        if (!$this->_adapter->{"supports_migrations"}()) {
            throw new Ruckusing_Exception(
                    "This database does not support migrations.",
                    Ruckusing_Exception::MIGRATION_NOT_SUPPORTED
            );
        }
        $this->_task_args = $args;
        $this->_return .= "Started: " . date('Y-m-d g:ia T') . "\n\n";
        $this->_return .= "[info:migrate]: \n";
        try {
            // Check that the schema_version table exists, and if not, automatically create it
            $this->verify_environment();

            $target_version = null;
            $style = STYLE_REGULAR;

            //did the user specify an explicit version?
            if (array_key_exists('version', $this->_task_args)) {
                $target_version = trim($this->_task_args['version']);
            }

            // did the user specify a relative offset, e.g. "-2" or "+3" ?
            if ($target_version !== null) {
                if (preg_match('/^([\-\+])(\d+)$/', $target_version, $matches)) {
                    if (count($matches) == 3) {
                        $direction = $matches[1] == '-' ? 'down' : 'up';
                        $steps = intval($matches[2]);
                        $style = STYLE_OFFSET;
                    }
                }
            }
            //determine our direction and target version
            $current_version = $this->_migrator_util->get_max_version();
            $this->_return .= "\n\tCurrent Version: $current_version\n";

            if ($style == STYLE_REGULAR) {
                if (is_null($target_version)) {
                    $this->prepare_to_migrate($target_version, 'up');
                } elseif ($current_version > $target_version) {
                    $this->prepare_to_migrate($target_version, 'down');
                } else {
                    $this->prepare_to_migrate($target_version, 'up');
                }
            }

            if ($style == STYLE_OFFSET) {
                $this->migrate_from_offset($steps, $current_version, $direction);
            }

            // Completed - display accumulated output
            if (!empty($output)) {
                $this->_return .= "\n\n";
            }
        } catch (Ruckusing_Exception $ex) {
            if ($ex->getCode() == Ruckusing_Exception::MISSING_SCHEMA_INFO_TABLE) {
                $this->_return .= "\tSchema info table does not exist.";
            } else {
                throw $ex;
            }
        }
        $this->_return .= "\n\nFinished: " . date('Y-m-d g:ia T') . "\n\n";

        return $this->_return;
    }

    /**
     * Migrate to a specific version using steps from current version
     *
     * @param integer $steps  number of versions to jump to
     * @param string  $current_version current version
     * @param $string $direction direction to migrate to 'up'/'down'
     */
    private function migrate_from_offset($steps, $current_version, $direction)
    {
        $migrations = $this->_migrator_util->get_migration_files($this->_migratorDirs, $direction);

        $current_index = $this->_migrator_util->find_version($migrations, $current_version, true);
        $current_index = $current_index !== null ? $current_index : -1;

        $this->_return .= "\ncurrent_index: " . $current_index;
        $this->_return .= "\nsteps: " . $steps . " $direction";

        // If we are not at the bottom then adjust our index (to satisfy array_slice)
        if ($current_index == -1 && $direction === 'down') {
            $available = array();
        } else {
            if ($direction === 'up') {
                $current_index += 1;
            } else {
                $current_index += $steps;
            }
            // check to see if we have enough migrations to run - the user
            // might have asked to run more than we have available
            $available = array_slice($migrations, $current_index, $steps);
        }

        $target = end($available);
        $this->_return .= "\n------------- TARGET ------------------\n";
        $this->_return .= print_r($target, true);

        $this->prepare_to_migrate(isset($target['version']) ? $target['version'] : null, $direction);
    }

    /**
     * Prepare to do a migration
     *
     * @param string $destination version to migrate to
     * @param string $direction direction to migrate to 'up'/'down'
     */
    private function prepare_to_migrate($destination, $direction)
    {
        try {
            $this->_return .= "\tMigrating " . strtoupper($direction);
            if (!is_null($destination)) {
                $this->_return .= " to: {$destination}\n";
            } else {
                $this->_return .= ":\n";
            }
            $migrations = $this->_migrator_util->get_runnable_migrations(
                    $this->_migratorDirs,
                    $direction,
                    $destination
            );
            if (count($migrations) == 0) {
                $this->_return .= "\nNo relevant migrations to run. Exiting...\n";
                return;
            } else {
                foreach ($migrations as $key => $migration) {
                    $this->_return .= sprintf("========= %s (%s) ======== \n", $migration['class'], $migration['version']);
                }
            }
        } catch (Exception $ex) {
            throw $ex;
        }

    }

    /**
     * Return the usage of the task
     *
     * @return string
     */
    public function help()
    {
        $output =<<<USAGE

\tTask: test:migrate

\tShows which migrations will be executed.

\tUses the same arguments as the db:migrate task.

USAGE;

        return $output;
    }

    /**
     * Check the environment
     */
    private function verify_environment()
    {
        if ($this->_adapter->{"table_exists"}($this->_adapter->get_schema_version_table_name()) ) {
            $this->_return .= "\n\tSchema version table exists.";
        }

        $this->_migratorDirs = $this->get_framework()->migrations_directories();

        // check if migrations directory exists
        foreach ($this->_migratorDirs as $name => $path) {
            if (is_dir($path)) {
                $this->_return .= sprintf("\n\tUsing Migrations directory (%s).", $path);
            }
        }
    }
}
