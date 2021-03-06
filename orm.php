<?php

namespace Fuel\Tasks;

class Orm
{

    static $string_max_lengths = array(
        'char' => 255,
        'varchar' => 255,
        'tinytext' => 255,
        'tinyblob' => 255,
        'text' => 65536,
        'blob' => 65536,
        'mediumtext' => 16777216,
        'mediumblob' => 16777216,
        'longtext' => 4294967296,
        'longblob' => 4294967296,
    );

    static $data_typing_types = array(
        'varchar',
        'tinytext',
        'text',
        'mediumtext',
        'longtext',
        'enum',
        'set',
        'bool',
        'boolean',
        'tinyint',
        'smallint',
        'int',
        'integer',
        'mediumint',
        'bigint',
        'float',
        'double',
        'decimal',
        'serialize',
        'json',
        'time_unix',
        'time_mysql',
    );

    public function run($db = null)
    {
        $tables = $this->list_tables($db);

        \Cli::write('Found ' . count($tables) . ' database tables to generate models for.', 'green');

        foreach ($tables as $table) {
            $this->generate_model($table, $db);
        }
    }

    public function generate_model($table_name, $db = null)
    {
        $table_class = \Inflector::classify($table_name);

        // Generate the full path for the model
        $file_path = APPPATH . 'classes' . DS . 'model' . DS;
        $file_path .= str_replace('_', '/', strtolower($table_class)) . '.php';

        if (file_exists($file_path)) {
            \Cli::error('Model already found for database table ' . $table_name);
            $answer = \Cli::prompt('Overwrite model?', array('y', 'n'));

            if ($answer == 'n') {
                \Cli::write('Existing model not overwritten.');
                return false;
            }
        }

        $primary_key = '';
        $key = \DB::query("SHOW KEYS FROM {$table_name} WHERE Key_name = 'PRIMARY'")->execute();
        foreach ($key as $k) $primary_key = $k['Column_name'];

        $columns = \DB::list_columns($table_name, null, $db);

        \Cli::write('Found ' . count($columns) . " columns for the {$table_name} database table.", 'green');

        $model_properties = array();
        foreach ($columns as $column) {
            // Process some of the column info to allow easier detection
            list($column_type, $column_unsigned) = explode(' ', $column['data_type'] . ' '); // Concatenated space stops an error happening when data_type has no spaces

            // A hack to detect Bool data types
            if ($column_type == 'tinyint' and $column['display'] == 1) {
                $column_type = 'bool';
            }

            if ($column_type == 'text')
                $column_type = 'serialize';

            // Basic Properties
            $column_properties = array(
                'data_type' => in_array($column_type, static::$data_typing_types) ? $column_type : 'string',
                'label' => \Inflector::humanize($column['name']),
                'null' => $column['null'],
            );

            $column['default'] and $column_properties['default'] = $column['default'];

            // Validation
            // TODO: Add thresholds rather than having rediculously high max values
            $column_validation = array();
            $column['null'] or $column_validation[] = 'required';

            if ($column_type == 'bool') {
                $column_validation = array('required');
            } elseif (key_exists($column_type, static::$string_max_lengths)) {
                $column_validation['max_length'] = array((int)min($column['character_maximum_length'], static::$string_max_lengths[$column_type]));
            } elseif ($column['type'] == 'int') {
                $display_max = (int)str_repeat(9, $column['display']);
                $column_validation['numeric_min'] = array((int)$column['min']);
                $column_validation['numeric_max'] = array((int)min($column['max'], $display_max));
            } elseif ($column['type'] == 'float') {
                $column['numeric_precision'] = isset($column['numeric_precision']) ? $column['numeric_precision'] : 9;
                $column['numeric_scale'] = isset($column['numeric_scale']) ? $column['numeric_scale'] : 2;
                $max = (float)(str_repeat(9, $column['numeric_precision'] - $column['numeric_scale']) . '.' . str_repeat(9, $column['numeric_scale']));
                $min = substr($column['data_type'], -8) == 'unsigned' ? 0 : $max * -1;
                $column_validation['numeric_min'] = array($min);
                $column_validation['numeric_max'] = array($max);
            }

            // Form
            $column_form = array('type' => 'text');

            if (in_array($column['name'], array('id', 'created_at', 'updated_at'))) {
                $column_form['type'] = false;
            } /* @TODO need to test whether these would be correctly datatyped when passed from the relevant form elements
             * elseif (in_array($column['name'], array('password', 'email', 'url', 'date', 'time')))
             * {
             * $column_form['type'] = $column['name'];
             * }*/
            else {
                $column['default'] and $column_form['value'] = $column['default'];

                switch ($column_type) {
                    case 'char':
                    case 'varchar':
                    case 'tinytext':
                    case 'tinyblob':
                        isset($column_validation['max_length']) and $column_form['maxlength'] = $column_validation['max_length'][0];
                        break;

                    case 'text':
                    case 'blob':
                    case 'mediumtext':
                    case 'mediumblob':
                    case 'longtext':
                    case 'longblob':
                        $column_form['type'] = 'textarea';
                        break;

                    case 'enum':
                        $column_form['type'] = 'select';
                        $column_form['options'] = $this->get_enum_values($table_name, $column['name']);
                        break;
                    case 'set':
                        $column_form['type'] = 'select';
                        $column_form['options'] = array();
                        break;

                    case 'bool':
                        $column_form['type'] = 'radio';
                        $column_form['options'] = array(1 => 'Yes', 0 => 'No');
                        break;


                    case 'decimal':
                    case 'double':
                    case 'float':
                        $column_form['step'] = floatval('0.' . str_repeat(9, $column['numeric_scale']));
                    // break is intentionally missing

                    case 'tinyint':
                    case 'smallint':
                    case 'int':
                    case 'mediumint':
                    case 'bigint':
                        $column_form['type'] = 'number';
                        isset($column_validation['numeric_min']) and $column_form['min'] = $column_validation['numeric_min'][0];
                        isset($column_validation['numeric_max']) and $column_form['max'] = $column_validation['numeric_max'][0];
                        break;

                    /* @TODO
                    case 'date':
                     * case 'datetime':
                     * case 'time':
                     * case 'timestamp':
                     * break;*/
                }
            }

            // fix enum
            if ($column_type == 'enum')
                $column_properties['options'] = $column_form['options'];

            $column_properties['validation'] = $column_validation;
            $column_properties['form'] = $column_form;
            $model_properties[$column['name']] = $column_properties;
        }

        $model_properties_str = str_replace(array("\n", '  ', 'array ('), array("\n\t", "\t", 'array('), \Format::forge($model_properties)->to_php());
        $model_properties_str = preg_replace('/=>\s+array/m', '=> array', $model_properties_str);

        $model_str = <<<MODEL
<?php

/**
 * Class {$table_class}

MODEL;
        foreach ($columns as $column) {
            $model_str .= <<<MODEL
 * @property {$column['type']} {$column['name']}

MODEL;
        }
        $model_str .= <<<MODEL
 */
class Model_{$table_class} extends \Orm\Model
{

	protected static \$_table_name = '{$table_name}';
	
	protected static \$_primary_key = array('{$primary_key}');

	protected static \$_properties = {$model_properties_str};

	protected static \$_observers = array(
		'Orm\\Observer_Validation' => array(
            'events' => array('before_save'),
        ),
		'Orm\\Observer_Typing' => array(
            'events' => array('before_save', 'after_save', 'after_load'),
        ),
MODEL;

        if ($table_name === 'users') {
            $model_str .= <<<MODEL

		'Orm\\Observer_User' => array(
	        'events' => array('after_save'),
	    ),
MODEL;
        }

        if (isset($model_properties['created_at'])) {
            $model_str .= <<<MODEL

		'Orm\\Observer_CreatedAt' => array(
	        'events' => array('before_insert'),
	        'mysql_timestamp' => false,
	        'property' => 'created_at',
	    ),
MODEL;
        }

        if (isset($model_properties['updated_at'])) {
            $model_str .= <<<MODEL

		'Orm\\Observer_UpdatedAt' => array(
	        'events' => array('before_save'),
	        'mysql_timestamp' => false,
	        'property' => 'updated_at',
	    ),
MODEL;
        }

        $model_str .= <<<MODEL

	);

}
MODEL;

        // Make sure the directory exists
        is_dir(dirname($file_path)) or mkdir(dirname($file_path), 0775, true);

        // Show people just how clever FuelPHP can be
        \File::update(dirname($file_path), basename($file_path), $model_str);

        return true;
    }


    function get_enum_values($table, $field)
    {

        $sql = "SHOW COLUMNS FROM `{$table}` WHERE Field = '{$field}'";
        $type = \DB::query($sql, \DB::SELECT)->as_object('stdClass')->execute()->as_array();
        preg_match("/^enum\(\'(.*)\'\)$/", $type[0]->Type, $matches);
        $enum = explode("','", $matches[1]);
        return $enum;
    }

    /**
     * @param null $db
     * @return array
     */
    function list_tables($db = null)
    {
        $data = array();
        $sql = 'SHOW TABLES';
        $results = \DB::instance($db)->query(\DB::SELECT, $sql, false)->as_array();
        foreach ($results as $result) {
            foreach ($result as $k => $v) {
                $data[] = $v;
            }
        }
        return $data;
    }
}
