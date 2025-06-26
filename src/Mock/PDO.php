<?php
namespace Main\Mock;
class PDO extends \PDO
{
    use \Main\Mock\Traits\QueryData;

    public function __construct() {
        // Initialize the parent PDO class with dummy parameters
        parent::__construct('sqlite::memory:');
    }

    public function prepare($statement, $driver_options = array()) {
        // Return a mock statement object
        return new PDOStatement();
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        // Return a mock result set
        return new PDOStatement();
    }

    public function exec($statement) {
        // Return a mock result set
        return new PDOStatement();
    }

}

class PDOStatement {
    public function execute($input_parameters = null) {
        return true;
    }

    public function fetch($fetch_style = null, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0) {
        return [];
    }

    public function fetchAll($fetch_style = null, $fetch_argument = null, $ctor_args = null) {
        return [];
    }
}
