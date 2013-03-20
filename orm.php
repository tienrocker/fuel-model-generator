<?php

namespace Fuel\Tasks;

class Orm
{

  static $string_max_lengths = array(
    'char'       => 255,
    'varchar'    => 255,
    'tinytext'   => 255,
    'tinyblob'   => 255,
    'text'       => 65536,
    'blob'       => 65536,
    'mediumtext' => 16777216,
    'mediumblob' => 16777216,
    'longtext'   => 4294967296,
    'longblob'   => 4294967296,
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

  public static $list_has_many = array();
  public static $list_many_many = array();

  public function run($db = null)
  {
    $tables = \DB::list_tables(null, $db);

    \Cli::write('Found '.count($tables).' database tables to generate models for.', 'green');

    foreach ($tables as $table)
    {
      $this->generate_model($table, $db, $tables);
    }

    foreach (self::$list_has_many as $tb1 => $tb1_val) 
    {
      $this->generate_has_many($tb1, $tb1_val);
    }

    foreach (self::$list_many_many as $key => $item) 
    {
      $this->generate_many_many($key, $item[0], $item[1]);
      $this->generate_many_many($key, $item[1], $item[0]);
    }

  }

  public function generate_has_many($model_name, $related)
  {
    $file_path = APPPATH.'classes'.DS.'model'.DS;
    $file_path .= $model_name.'.php';
    $model_has_many = '';

    foreach ($related as $model => $model_name_2) 
    {
      $column_properties = array(
        'key_from'        => 'id',
        'model_to'        => 'Model_' . \Inflector::classify($model_name_2),
        'key_to'          => $model_name . '_id',
        'cascade_save'    => true,
        'cascade_delete'  => false,
      );

      $model_has_many[$model_name_2] = $column_properties;
    }

    if ($model_has_many != '')
    {
      $model_has_many_str = str_replace(array("\n", '  ', 'array ('), array("\n\t", "\t", 'array('), \Format::forge($model_has_many)->to_php());
      $model_has_many_str = preg_replace('/=>\s+array/m', '=> array', $model_has_many_str);


      $model_str = <<<MODEL
protected static \$_has_many = {$model_has_many_str};
MODEL;


      $contents = \File::read($file_path, true);
      $contents_arr[] = explode('//_has_many;', $contents);
      $contents = $contents_arr[0][0] . $model_str . $contents_arr[0][1];
      
      \File::update(dirname($file_path), basename($file_path), $contents);
    }

    return true;
  }

  public function generate_many_many($tb_relacionamento, $tb1, $tb2)
  {
    $file_path = APPPATH.'classes'.DS.'model'.DS;
    $file_path .= $tb1.'.php';
    $model_many_many = '';


    $column_properties = array(
      'key_from' => 'id',
      'key_through_from' => $tb1 . '_id',
      'table_through' => $tb_relacionamento,
      'key_through_to' => $tb2 . '_id', 
      'model_to' => 'Model_' . \Inflector::classify($tb2),
      'key_to' => 'id',
      'cascade_save' => true,
      'cascade_delete' => false,
    );

    $model_many_many[$tb2] = $column_properties;


    if ($model_many_many != '')
    {
      $model_many_many_str = str_replace(array("\n", '  ', 'array ('), array("\n\t", "\t", 'array('), \Format::forge($model_many_many)->to_php());
      $model_many_many_str = preg_replace('/=>\s+array/m', '=> array', $model_many_many_str);


      $model_str = <<<MODEL
protected static \$_many_many = {$model_many_many_str};
MODEL;


      $contents = \File::read($file_path, true);
      $contents_arr[] = explode('//_many_many;', $contents);
      $contents = $contents_arr[0][0] . $model_str . $contents_arr[0][1];
      
      \File::update(dirname($file_path), basename($file_path), $contents);
    }

    return true;
  }

  public function generate_model($table_name, $db = null, $tables)
  {
    $model_belongs_to     = '';
    $model_belongs_to_str = '';

    $table_class = \Inflector::classify($table_name);

    // Generate the full path for the model
    $file_path = APPPATH.'classes'.DS.'model'.DS;
    //$file_path .= str_replace('_', '/', strtolower($table_class)).'.php';
    $file_path .= strtolower($table_class).'.php';


    if (strrpos($table_name, "_has_") > 0)
    {
      
      $arr_tables[] = explode('_has_', $table_name);


      if ((in_array($arr_tables[0][0], $tables)) && (in_array($arr_tables[0][1], $tables)))
      {
        self::$list_many_many[$table_name] = array($arr_tables[0][0], $arr_tables[0][1]);
        return true;
      }
    }

    if (file_exists($file_path))
    {
      \Cli::error('Model already found for database table '.$table_name);
      $answer = \Cli::prompt('Overwrite model?', array('y', 'n'));

      if ($answer == 'n')
      {
        \Cli::write('Existing model not overwritten.');
        return false;
      }
    }

    $columns = \DB::list_columns($table_name, null, $db);

    \Cli::write('Found '.count($columns)." columns for the {$table_name} database table.", 'green');

    $model_properties = array();
    foreach ($columns as $column)
    {
      // Process some of the column info to allow easier detection
      list($column_type, $column_unsigned) = explode(' ', $column['data_type'] . ' '); // Concatenated space stops an error happening when data_type has no spaces


      if ($column['key'] != 'MUL')
      {

        // A hack to detect Bool data types
        if ($column_type == 'tinyint' and $column['display'] == 1)
        {
          $column_type = 'bool';
        }

        // Basic Properties
        $column_properties = array(
          'data_type' => in_array($column_type, static::$data_typing_types) ? $column_type : 'string',
          'label'     => \Inflector::humanize($column['name']),
          'null'      => $column['null'],
        );

        if ($column['default'] != NULL)
        {
          $column_properties['default'] = $column['default'];
        }

        // Validation
        // TODO: Add thresholds rather than having rediculously high max values
        $column_validation = array();
        $column['null'] or $column_validation[] = 'required';

        if ($column_type == 'bool')
        {
          $column_validation = array('required');
        }
        elseif (key_exists($column_type, static::$string_max_lengths))
        {
          $column_validation['max_length'] = array( (int) min($column['character_maximum_length'], static::$string_max_lengths[$column_type]));
        }
        elseif ($column['type'] == 'int')
        {
          $display_max = (int) str_repeat(9, $column['display']);
          $column_validation['numeric_min'] = array( (int) $column['min']);
          $column_validation['numeric_max'] = array( (int) min($column['max'], $display_max));
        }
        elseif ($column['type'] == 'float')
        {
          $max = (float) (str_repeat(9, $column['numeric_precision'] - $column['numeric_scale']).'.'.str_repeat(9, $column['numeric_scale']));
          $min = substr($column['data_type'], -8) == 'unsigned' ? 0 : $max * -1;
          $column_validation['numeric_min'] = array($min);
          $column_validation['numeric_max'] = array($max);
        }

        // Form
        $column_form = array('type' => 'text');

        if (in_array($column['name'], array('id', 'created_at', 'updated_at', 'slug')))
        {
          $column_form['type'] = false;
          $model_properties[$column['name']] = array();
        }
        /* @TODO need to test whether these would be correctly datatyped when passed from the relevant form elements
        elseif (in_array($column['name'], array('password', 'email', 'url', 'date', 'time')))
        {
          $column_form['type'] = $column['name'];
        }*/
        else
        {
          $column['default'] and $column_form['value'] = $column['default'];

          switch ($column_type)
          {
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
              $column_form['step'] = floatval('0.'.str_repeat(9, $column['numeric_scale']));
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
            case 'datetime':
            case 'time':
            case 'timestamp':
              break;*/
          }
        }

        if (!in_array($column['name'], array('id', 'created_at', 'updated_at', 'slug')))
        {
          $column_properties['validation'] = $column_validation;
          $column_properties['form'] = $column_form;
          $model_properties[$column['name']] = $column_properties;
        }

      }
      else
      {
        $column_properties = array(
          'key_from'        => $column['name'],
          'model_to'        => 'Model_' . \Inflector::classify(str_replace(array('_id', '_pai'), '', $column['name'])),
          'key_to'          => 'id',
          'cascade_save'    => true,
          'cascade_delete'  => false,
        );

        $model_belongs_to[str_replace('_id', '', $column['name'])] = $column_properties;
        $model_properties[$column['name']] = array();

        if (strrpos($column['name'], "_pai_") === FALSE)
        {
          self::$list_has_many[str_replace('_id', '', $column['name'])][] = $table_name;
        }
      }

    }

    $model_properties_str = str_replace(array("\n", '  ', 'array ('), array("\n\t", "\t", 'array('), \Format::forge($model_properties)->to_php());
    $model_properties_str = preg_replace('/=>\s+array/m', '=> array', $model_properties_str);
    $model_properties_str = str_replace(" => array(\n\t\t),", ",", $model_properties_str);

    if ($model_belongs_to != '')
    {
      $model_belongs_to_str = str_replace(array("\n", '  ', 'array ('), array("\n\t", "\t", 'array('), \Format::forge($model_belongs_to)->to_php());
      $model_belongs_to_str = preg_replace('/=>\s+array/m', '=> array', $model_belongs_to_str);
    }

    $model_str = <<<MODEL
<?php

class Model_{$table_class} extends \Orm\Model
{

\tprotected static \$_table_name = '{$table_name}';

\tprotected static \$_properties = {$model_properties_str};

MODEL;

if ($model_belongs_to_str != "")
{
  $model_str .= <<<MODEL

\tprotected static \$_belongs_to = {$model_belongs_to_str};

MODEL;
}

$model_str .= <<<MODEL

\t//_has_many;

\t//_many_many;

\tprotected static \$_observers = array(
\t\t'Orm\Observer_Validation' => array(
\t\t\t'events' => array('before_save'),
\t\t),
\t\t'Orm\Observer_Typing' => array(
\t\t\t'events' => array('before_save', 'after_save', 'after_load'),
\t\t),
MODEL;

    if (isset($model_properties['created_at']))
    {
      $model_str .= <<<MODEL

\t\t'Orm\Observer_CreatedAt' => array(
\t\t\t'events' => array('before_insert'),
\t\t\t'mysql_timestamp' => false,
\t\t\t'property' => 'created_at',
\t\t),
MODEL;
    }

    if (isset($model_properties['updated_at']))
    {
      $model_str .= <<<MODEL

\t\t'Orm\Observer_UpdatedAt' => array(
\t\t\t'events' => array('before_save'),
\t\t\t'mysql_timestamp' => false,
\t\t\t'property' => 'updated_at',
\t\t),
MODEL;
    }

    if (isset($model_properties['slug']))
    {
      $model_str .= <<<MODEL

\t\t'Orm\Observer_Slug' => array(
\t\t\t'events' => array('before_insert'),
\t\t\t'source' => array('nome'),
\t\t\t'property' => 'slug',
\t\t),
MODEL;
    }

    $model_str .= <<<MODEL

\t);

}
MODEL;

    // Make sure the directory exists
    is_dir(dirname($file_path)) or mkdir(dirname($file_path), 0775, true);

    // Show people just how clever FuelPHP can be
    \File::update(dirname($file_path), basename($file_path), $model_str);

    return true;
  }

}
