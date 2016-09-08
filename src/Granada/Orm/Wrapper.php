<?php namespace Granada\Orm;

use Granada\ORM;
use Granada\Eager;
use Exception;
use Granada\Paginator;

/**
 * Subclass of Idiorm's ORM class that supports
 * returning instances of a specified class rather
 * than raw instances of the ORM class.
 *
 * You shouldn't need to interact with this class
 * directly. It is used internally by the Model base
 * class.
 */
class Wrapper extends ORM {

    /**
     * The wrapped find_one and find_many classes will
     * return an instance or instances of this class.
     */
    protected $_class_name;

    public $relationships = array();

    /**
     * @var null
     */
    public $_order_by_property = null;

    /**
     * @var null
     */
    public $_order_by_direction = "DESC";

    public function set_order_by_property($property)
    {
        $this->_order_by_property = $property;
    }

    public function set_order_by_direction($direction)
    {
        $this->_order_by_direction = $direction;
    }

    /**
     * Set the name of the class which the wrapped
     * methods should return instances of.
     * @param string $class_name
     */
    public function set_class_name($class_name) {
        $this->_class_name = $class_name;
    }

    /**
     * Add a custom filter to the method chain specified on the
     * model class. This allows custom queries to be added
     * to models. The filter should take an instance of the
     * ORM wrapper as its first argument and return an instance
     * of the ORM wrapper. Any arguments passed to this method
     * after the name of the filter will be passed to the called
     * filter function as arguments after the ORM class.
     */
    public function filter() {
        $args = func_get_args();
        $filter_function = array_shift($args);
        array_unshift($args, $this);
        if (method_exists($this->_class_name, $filter_function)) {
            return call_user_func_array(array($this->_class_name, $filter_function), $args);
        }
        return $this;
    }

    /**
     * Factory method, return an instance of this
     * class bound to the supplied table name.
     *
     * A repeat of content in parent::for_table, so that
     * created class is ORMWrapper, not ORM
     */
    public static function for_table($table_name, $connection_name = parent::DEFAULT_CONNECTION) {
        self::_setup_db($connection_name);
        return new self($table_name, array(), $connection_name);
    }

    /**
     * Method to create an instance of the model class
     * associated with this wrapper and populate
     * it with the supplied Idiorm instance.
     */
    protected function _create_model_instance($orm) {
        if ($orm === false) {
            return false;
        }
        $model = new $this->_class_name();
        $orm->resultSetClass = $model->get_resultSetClass();
        $orm->set_class_name($this->_class_name);
        $model->set_orm($orm);
        return $model;
    }

    /**
     *
     * Overload select_expr name
     *
     */
    public function select_raw($expr, $alias=null){
        return $this->select_expr($expr, $alias);
    }


    /**
     * Add an unquoted expression to the list of columns to GROUP BY
     */
    public function group_by_raw($expr) {
        return $this->group_by_expr($expr);
    }


    /**
     * Add an unquoted expression as an ORDER BY clause
     */
    public function order_by_raw($clause) {
        $this->_order_by[] = $clause;
        return $this;
    }

    /**
     *
     * To create and save multiple elements, easy way
     * Using an array with rows array(array('name'=>'value',...), array('name2'=>'value2',...),..)
     * or a array multiple
     *
     */
    public function insert($rows, $ignore = false)
    {
        ORM::get_db()->beginTransaction();
        try {
            foreach ($rows as $row) {
                $class = $this->_class_name;
                $class::create($row)->save($ignore);
            }
            ORM::get_db()->commit();
            return ORM::get_db()->lastInsertId();
        } catch (\PDOException $e) {
            ORM::get_db()->rollBack();
            throw $e;
        }
    }

    /**
     * Wrap Idiorm's find_one method to return
     * an instance of the class associated with
     * this wrapper instead of the raw ORM class.
     * Added: hidrate the model instance before returning
     * @param integer $id
     */
    public function find_one($id=null) {
        $result = $this->_create_model_instance(parent::find_one($id));
        if($result){
            // set result on a result set for the eager load to work
            if (is_array($this->_instance_id_column)) {
                $key = 0;
            }else {
                $key = (isset($result->{$this->_instance_id_column}) && $this->_associative_results) ? $result->id() : 0;
            }
            $results = array($key => $result);
            Eager::hydrate($this, $results, self::$_config[$this->_connection_name]['return_result_sets']);
            // return the result as element, not result set
            $result = $results[$key];
        }
        return $result;
    }

    /**
     * Tell the ORM that you are expecting multiple results
     * from your query, and execute it. Will return an array
     * of instances of the ORM class, or an empty array if
     * no rows were returned.
     * @return array|\Granada\ResultSet
     */
    public function find_many() {
        if (!count($this->_order_by) && $this->_order_by_property) {
            switch (strtolower($this->_order_by_direction)) {
                case "asc":
                    parent::order_by_asc($this->_order_by_property);
                    break;
                default:
                    parent::order_by_desc($this->_order_by_property);
            }
        }
        $instances = parent::find_many();
        return $instances ? Eager::hydrate($this, $instances, self::$_config[$this->_connection_name]['return_result_sets']) : $instances;
    }

    /**
     * Get  paginated results
     */
    public function paginate($page = null, $perPage = null, $columns = ['*'])
    {
        $perPage = intval($perPage) ?: self::$_config[$this->_connection_name]['pagination_limit'];

        $page = intval($page) ?: self::$_config[$this->_connection_name]['pagination_default_page'];

        $total = parent::count();

        parent::offset(($page - 1) * $perPage)->limit($perPage);

        $results = $this->find_many();

        return new Paginator($results->get_results(), $total, $perPage, $page);
    }

    /**
     * Override Idiorm _instances_with_id_as_key
     * Create instances of each row in the result and map
     * them to an associative array with the primary IDs as
     * the array keys.
     * Added: the array result key = primary key from the model
     * Added: Eager loading of relationships defined "with()"
     * @param array $rows
     * @return array
     */
    protected function _get_instances($rows){
        $instances = array();
        foreach($rows as $current_key => $current_row) {
            $row = $this->_create_instance_from_row($current_row);
            $row = $this->_create_model_instance($row);
            if (is_array($this->_instance_id_column)) {
                $key = $current_key;
            } else {
                $key = (isset($row->{$this->_instance_id_column}) && $this->_associative_results) ? $row->id() : $current_key;
            }
            $instances[$key] = $row;
        }

        return $instances;
    }

    /**
     * Pluck a single column from the result.
     *
     * @param  string  $column
     * @return mixed
     */
    public function pluck($column)
    {
        $result = $this->select($column)->find_one();

        if($result) {
            return $result[$column];
        }
        else {
            return null;
        }
    }

    /**
     * Wrap Idiorm's create method to return an
     * empty instance of the class associated with
     * this wrapper instead of the raw ORM class.
     */
    public function create($data=null) {
        $model = $this->_create_model_instance(parent::create(null));
        if($data !== null) $model->set($data);
        return $model;
    }

    /**
     * Added: Set the eagerly loaded models on the queryable model.
     *
     * @return Wrapper
     */
    public function with()
    {
        $this->relationships  = array_merge($this->relationships, func_get_args());
        return $this;
    }

    /**
     * Added: Reset relation deletes the relationship "where" condition.
     *
     * @return Wrapper
     */
    public function reset_relation()
    {
        array_shift($this->_where_conditions);
        return $this;
    }

    /**
     * Added: Return pairs as result array('keyrecord_value'=>'valuerecord_value',.....)
     *
     */
    public function find_pairs($key = false, $value = false)
    {
        $key = ($key) ? $key : 'id';
        $value = ($value) ? $value : 'name';
        return self::assoc_to_keyval($this->select_raw("$key,$value")->order_by_asc($value)->find_array(), $key, $value);
    }


    /**
     * Converts a multi-dimensional associative array into an array of key => values with the provided field names
     *
     * @param   array   the array to convert
     * @param   string  the field name of the key field
     * @param   string  the field name of the value field
     * @param boolean|string $key_field
     * @param boolean|string $val_field
     * @return  array
     */
    public static function assoc_to_keyval($assoc = null, $key_field = null, $val_field = null)
    {
        if(empty($assoc) OR empty($key_field) OR empty($val_field))
        {
            return null;
        }

        $output = array();
        foreach($assoc as $row)
        {
            if(isset($row[$key_field]) AND isset($row[$val_field]))
            {
                $output[$row[$key_field]] = $row[$val_field];
            }
        }

        return $output;
    }

    /**
     *
     * Overrides __call to check for filter_$method names defined
     * You can now define filters methods on the Granada Model as
     * public static function filter_{filtermethodname} and call it from a static call
     * ModelName::filtermethodname->......
     *
     */
    public function __call($method, $parameters){
        if(method_exists($this->_class_name,'filter_'.$method)){
            array_unshift($parameters, $this);
            return call_user_func_array(array($this->_class_name,'filter_'.$method), $parameters);
        }
        else {
            throw new Exception(" no static $method found or static method 'filter_$method' not defined in ".$this->_class_name);
        }
    }
}
