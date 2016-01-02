<?php
namespace Spider\Commands\Languages\OrientSQL;
use Spider\Commands\Bag;
use Spider\Commands\Command;
use Spider\Commands\Languages\AbstractProcessor;
use Spider\Commands\Languages\ProcessorInterface;

/**
 * Class AbstractOrientSqlProcessor
 * @package Spider\Commands\Languages\OrientSQL
 */
abstract class AbstractOrientSqlProcessor extends AbstractProcessor
{
    /** @var Bag Command bag */
    protected $bag;

    /**
     * A map of operators from the Command Bag to Orient SQL
     * @var array
     */
    protected $operatorsMap = [
        Bag::COMPARATOR_EQUAL => '=',
        Bag::COMPARATOR_GT => '>',
        Bag::COMPARATOR_LT => '<',
        Bag::COMPARATOR_LE => '<=',
        Bag::COMPARATOR_GE => '>=',
        Bag::COMPARATOR_NE => '<>',
        Bag::COMPARATOR_WITHOUT => 'WITHOUT',
        Bag::COMPARATOR_IN => "IN",

        Bag::CONJUNCTION_AND => 'AND',
        Bag::CONJUNCTION_OR => 'OR',

        Bag::ORDER_DESC => 'DESC',
        Bag::ORDER_ASC => 'ASC',
    ];

    /**
     * Map of orient fields
     * @var array
     */
    protected $fieldsMap = [
        Bag::ELEMENT_ID => "@rid",
    ];

    /** @var ProcessorInterface Command processor */
    protected $processor;

    /** @var string Current script */
    protected $script;

    /**
     * AbstractOrientSqlProcessor constructor.
     * @param CommandProcessor $processor
     */
    public function __construct(CommandProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Initialize the Command Processor
     * @param Bag $bag
     */
    protected function init(Bag $bag)
    {
        $this->bag = $bag;
    }

    /**
     * Begin the current script without a space
     * @param string $clause
     * @return string
     */
    protected function startScript($clause, &$script)
    {
        $script .= (string) $clause;
        return $script;
    }

    /**
     * Add to the current script with a space before
     * @param $clause
     * @param $script
     * @return string
     * @throws \Exception
     */
    protected function addToScript($clause, &$script)
    {
        if (!is_string($clause)) {
            throw new \Exception("Only strings can be added to script");
        }

        $script .= " " . $clause;
        return $script;
    }

    /**
     * Returns current script
     * @return string
     */
    protected function getScript()
    {
        return $this->script;
    }

    /**
     * Append where constraints to current script
     * @param array $constraints
     * @param $script
     * @throws \Exception
     */
    protected function appendWheres(array $constraints = null, &$script)
    {
        if (is_null($constraints)) {
            return;
        }

        $statement = '';
        $beginningIndex = 0;
        foreach ($constraints as $index => $constraint) {
            if ($constraint[0] === Bag::ELEMENT_LABEL || $constraint[0] === Bag::ELEMENT_TYPE) {
                $beginningIndex++;
                continue;
            }

            /* Skip the Element Type, not needed for Orient */
            if ($constraint[0] === Bag::ELEMENT_TYPE || $constraint[0] === Bag::ELEMENT_LABEL) {
                continue;
            }

            if ($index !== $beginningIndex) { // don't add conjunction to the first clause
                $statement .= " " . (string)$this->toSqlOperator($constraint[3]);
            }

            $statement .= " " . (string)$this->toOrientField($constraint[0]); // field
            $statement .= " " . (string)$this->toSqlOperator($constraint[1]); // operator
            $statement .= " " . $this->castValue($constraint[2], $constraint[0]); // value
        }

        if ($statement !== '') {
            $this->addToScript("WHERE", $script);
            $this->addToScript(ltrim($statement), $script);
        }
    }

    /**
     * @param $type
     * @param array $data
     * @param $script
     * @throws \Exception
     */
    protected function appendContent($type, array $data, &$script)
    {
        unset($data[Bag::ELEMENT_TYPE]);
        unset($data[Bag::ELEMENT_LABEL]);
        unset($data[Bag::EDGE_OUTV]);
        unset($data[Bag::EDGE_INV]);

        if (!empty($data)) {
            $this->addToScript($type, $script);
            $this->addToScript(json_encode($data), $script);
        }
    }

    /**
     * Append target to current script
     * @param string $prefix
     * @param $script
     * @throws \Exception
     */
    protected function appendTarget($prefix = "from", &$script)
    {
        // Set the Default
        $target = 'V';

        if (!is_null($this->bag->where)) {
            // Search through wheres for label (class) or id
            foreach ($this->bag->where as $index => $value) {
                if ($value[0] === Bag::ELEMENT_LABEL || $value[0] === Bag::ELEMENT_ID) {
                    $target = $value[2];
                    unset($this->bag->where[$index]);
                    $this->bag->where = array_values($this->bag->where);
                }
            }
        }

        if ($prefix !== "") {
            $this->addToScript(strtoupper($prefix), $script); // FROM
        }
        $this->addToScript($target, $script); // ID or Class
    }

    /**
     * @param $default
     * @param array $wheres
     * @param $script
     * @throws \Exception
     */
    protected function appendClass($default, array $wheres, &$script)
    {
        if (isset($wheres[Bag::ELEMENT_LABEL])) {
            $this->addToScript($wheres[Bag::ELEMENT_LABEL], $script);

        } elseif ($label = $this->hasLabelInWheres($wheres)) {
            $this->addToScript($label, $script);

//        } elseif ($items = $this->hasPointerInWheres($wheres)) {
//            $this->addToScript($items, $script);

        } elseif (!is_null($default)) {
            $this->addToScript($default, $script);
        }
    }

    /**
     * Append Limit to current script
     * @param Bag $bag
     * @param $script
     * @throws \Exception
     */
    protected function appendLimit(Bag $bag, &$script)
    {
        if ($bag->limit) {
            $this->addToScript("LIMIT " . (string)$bag->limit, $script);
        }
    }

    /**
     * Cast a value from the Command Bag to one
     * usable by Orient SQL (a string)
     * @param $value
     * @param $field
     * @return string
     */
    protected function castValue($value, $field)
    {
        if ($value === true) {
            $value = 'true';

        } elseif ($value === false) {
            $value = 'false';

        } elseif (is_string($value) && !isset($this->fieldsMap[$field])) {
            $value = "'$value'";
        } elseif (is_array($value)) {
            $value = '['.implode(",", $value).']';
        }

        return (string)$value;
    }

    /**
     * Map a Command Bag operator to its Orient SQL counterpart
     * @param $operator
     * @return mixed
     */
    protected function toSqlOperator($operator)
    {
        return $this->operatorsMap[$operator];
    }

    /**
     * Map a Command Bag operator to its Orient SQL counterpart
     * @param $field
     * @return mixed
     */
    protected function toOrientField($field)
    {
        if (isset($this->fieldsMap[$field])) {
            return $this->fieldsMap[$field];
        }

        return $field;
    }

    /**
     * @param $script
     * @return Command
     */
    protected function createCommand($script)
    {
        $command = new Command($script);
        $command->setScriptLanguage('orientSQL');
        return $command;
    }

//    protected function hasPointerInWheres($wheres)
//    {
//        $type = false;
//        foreach ($wheres as $constraint) {
//            if (in_array(SqlBatch::UPDATE_STATEMENT, $constraint)) {
//                $type = SqlBatch::UPDATE_STATEMENT;
//
//            } elseif (in_array(SqlBatch::CREATE_STATEMENT, $constraint)) {
//                $type = SqlBatch::CREATE_STATEMENT;
//
//            } elseif (in_array(SqlBatch::SELECT_STATEMENT, $constraint)) {
//                $type = SqlBatch::SELECT_STATEMENT;
//
//            } elseif (in_array(SqlBatch::DELETE_STATEMENT, $constraint)) {
//                $type = SqlBatch::DELETE_STATEMENT;
//
//            }
//        }
//        return ($type === false) ? false : implode(",", $this->processor->batch->transactionVariables[$type]);
//    }

    /**
     * @param $wheres
     * @return bool
     */
    protected function hasLabelInWheres($wheres)
    {
        foreach ($wheres as $constraint) {
            if ($constraint[0] === Bag::ELEMENT_LABEL) {
                return $constraint[2];
            }
        }

        return false;
    }

    /**
     * @param Bag $bag
     * @return Command
     */
    protected function processEmbedded(Bag $bag)
    {
        return $this->processor->process($bag, true);
    }
}
