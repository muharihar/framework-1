<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\ORM;

use Spiral\Components\DBAL\Builders\Common\HavingTrait;
use Spiral\Components\DBAL\Builders\Common\JoinTrait;
use Spiral\Components\DBAL\Builders\Common\WhereTrait;
use Spiral\Components\DBAL\Database;
use Spiral\Components\DBAL\QueryBuilder;
use Spiral\Components\DBAL\QueryCompiler;
use Spiral\Components\ORM\Selector\Loaders\PrimaryLoader;
use Spiral\Core\Component;

class Selector extends QueryBuilder
{
    use WhereTrait, JoinTrait, HavingTrait, Component\LoggerTrait;

    const INLOAD   = 1;
    const POSTLOAD = 2;

    const PRIMARY_MODEL = 0;

    /**
     * ORM component. Used to access related schemas.
     *
     * @invisible
     * @var ORM
     */
    protected $orm = null;

    /**
     * Database instance to fetch data from.
     *
     * @var Database
     */
    protected $database = null;

    /**
     * Set of nested loaders used to normalize loaded relations in correct set of data and modify query
     * with required joins. Loaders separated by two primary sets - inload and postload loaders, inload
     * will join data to primary query (for example has-one, belongs-to-parent relations), postload
     * usually used to fetch has-many relations and will generate another query. Most of relations
     * (except polymorphic) can be loaded both ways.
     *
     * Attention, using inload with has-many records will make limit() and offset() (pagination methods)
     * useless due structure of resulted query. Additionally you can't use count() query in combination
     * with such queries.
     *
     * Some loaded may return non array by Entity data like preloading belongs-to to ensure that
     * multiple models will receive identical parents (in terms of reference and data).
     *
     * @var PrimaryLoader
     */
    protected $loader = null;


    protected $countColumns = 0;

    public $columns = array();


    //    /**
    //     * Set of loaders and nested loaded used to normalize loaded relations in correct set of nested
    //     * data. Loaded separated by two primary sets - inload and postload loaders, inload will join data
    //     * to primary query (for example has-one, belongs-to-parent relations), postload usually used to
    //     * fetch has-many relations. Most of relations (except polymorphic) can be loaded both ways.
    //     *
    //     * Attention, using inload with has-many records will make limit() and offset() (pagination methods)
    //     * useless due structure of resulted query. Additionally you can't use count() query in combination
    //     * with such queries.
    //     *
    //     * Some loaded may return non array by Entity data like preloading belongs-to to ensure that
    //     * multiple models will receive identical parents (in terms of reference and data).
    //     *
    //     * @var Loader[]
    //     */
    //    protected $loaders = array();
    //

    public function __construct(array $schema, ORM $orm, Database $database, array $query = array())
    {
        $this->orm = $orm;

        $this->database = $database;

        //We always has one loader
        $this->loader = new PrimaryLoader($schema, $this->orm);
    }

    /**
     * TODO: Write description
     *
     * @param string|array $relation
     * @param array        $options
     * @return static
     */
    public function with($relation, $options = array())
    {
        if (is_array($relation))
        {
            foreach ($relation as $name => $options)
            {
                //Multiple relations or relation with addition load options
                $this->with($name, $options);
            }

            return $this;
        }

        //Nested loader
        $this->loader->addLoader($relation, $options);

        return $this;
    }

    protected function buildQuery()
    {
        $this->countColumns = 0;
        $this->columns = array();

        $this->loader->clarifySelector($this);
    }

    /**
     * Get or render SQL statement.
     *
     * @param QueryCompiler $compiler
     * @return string
     */
    public function sqlStatement(QueryCompiler $compiler = null)
    {
        if (empty($compiler))
        {
            //In cases where compiled does not provided externally we can get compiler from related
            //database, external compilers are good for testing
            $compiler = $this->database->getDriver()->queryCompiler($this->database->getPrefix());
        }

        $this->buildQuery();

        $statement = $compiler->select(
            array($this->loader->getTable() . ' AS ' . $this->loader->getTableAlias()),
            false, //todo: check if required
            $this->columns,
            $this->joins,
            $this->whereTokens,
            $this->havingTokens,
            array()
        //$this->orderBy,
        //$this->limit,
        //$this->offset
        );

        return $statement;
    }

    /**
     * Fetching data.
     *
     * @return array
     */
    public function fetchData()
    {
        $result = $this->database->query($statement = $this->sqlStatement(), $this->getParameters());

        benchmark('selector::parseResult', $statement);
        $data = $this->loader->parseResult($result, $rowsCount);
        benchmark('selector::parseResult', $statement);
        $this->logCounts(count($data), $rowsCount);

        //Moved out of benchmark to see memory usage
        $this->loader->clean();
        $result->close();

        return $data;
    }

    protected function logCounts($dataCount, $rowsCount)
    {
        $logLevel = 'debug';
        $logLevels = array(
            1000 => 'critical',
            500  => 'alert',
            100  => 'notice',
            10   => 'warning',
            1    => 'debug'
        );

        $dataRatio = $rowsCount / $dataCount;
        if ($dataRatio == 1)
        {
            //No need to log it, everything seems fine
            return;
        }

        foreach ($logLevels as $ratio => $logLevel)
        {
            if ($dataRatio > $ratio)
            {
                break;
            }
        }

        self::logger()->$logLevel(
            "Query resulted with {rowsCount} row(s) grouped into {dataCount} records.",
            compact('dataCount', 'rowsCount')
        );
    }

    /**
     * Generate set of columns required to represent desired model. Do not use this method by your own.
     * Method will return columns offset.
     *
     * @param string $tableAlias
     * @param array  $columns Original set of model columns.
     * @return int
     */
    public function addColumns($tableAlias, array $columns)
    {
        $offset = count($this->columns);

        foreach ($columns as $column)
        {
            $this->columns[] = $tableAlias . '.' . $column . ' AS c' . (++$this->countColumns);
        }

        return $offset;
    }
}