<?php

/**
 * Directus - awesome content management framework for everyone
 *
 * @copyright   2012 RANGER
 * @link        http://www.getdirectus.com
 * @license     http://www.getdirectus.com/license
 * @version     6.0.0
 *
 * This file is part of Directus.
 *
 * Directus is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Directus is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Directus. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * DB
 *
 * This class connects to a MYSQL database and supplies data for the REST api
 * Will eventually be implemented as a singleton.
 *
 * @package Directus
 * @since   6.0.0
 */

require dirname(__FILE__) . '/mysql.php';

class DB extends MySQL {

  var $user_token = 'lcjREKokJYNLkIjY7LUqnCs0wnWSvStvb2PTgw4HWu0=';
  var $user_id = 1;


  function set_entry_relational($tbl_name, $data, $parent_activity_id=null) {
    // These columns are aliases and doesn't have corresponding
    // columns in the DB, for example 'alias' and 'relational'
    $alias_types = array('ONETOMANY','MANYTOMANY','ALIAS');
    $alias_columns = array();
    $alias_meta = array();
    $alias_data = array();

     // Gram the schema so we can see what's possible
    $schema = $this->get_table($tbl_name);

    // Grab relational columns
    foreach($schema as $column) {
      if (in_array($column['type'], $alias_types)) {
        array_push($alias_columns, $column['column_name']);
        $alias_meta[$column['column_name']] = $column;
      }
    }

    // Seperate relational data
    foreach($data as $column_name => $value) {
      if (in_array($column_name, $alias_columns)) {
        $alias_data[$column_name] = $value;
        unset($data[$column_name]);
      }
    }

    // Update local (non-relational) data
    $id = $this->set_entry($tbl_name, $data);

    // Log it
    $action = isset($data['id']) ? 'UPDATE' : 'ADD';
    $master_item = find($schema,'master',true);
    $identifier = isset($master_item) ? $data[$master_item['column_name']] : null;
    $activity_id = $this->log_activity('ENTRY',$tbl_name, $action, $id, $identifier, $data, $parent_activity_id);

    // Update the related columns
    foreach($alias_meta as $column_name => $item) {

      if (!isset($alias_data[$column_name])) continue;

      $data = $alias_data[$column_name];
      $table_related = $item['table_related'];
      $junction_key_right = $item['junction_key_right'];

      switch($item['type']) {
        case 'ONETOMANY':
          foreach ($data as $foreign_table_row) {
            $foreign_table_row[$junction_key_right] = $id;
            $this->set_entry_relational('ENTRY',$table_related, $foreign_table_row, $activity_id);
          }
          break;

        case 'MANYTOMANY':
          $junction_table = $item['junction_table'];
          $junction_key_left = $item['junction_key_left'];
          foreach($data as $junction_table_row) {

            // Delete?
            if (isset($junction_table_row['active']) && ($junction_table_row['active'] == '0')) {
              $junction_table_id = intval($junction_table_row['id']);
              $this->dbh->exec("DELETE FROM $junction_table WHERE id=$junction_table_id");
              $this->log_activity($junction_table, 'DELETE', $junction_table_id, 'TEST', null, $activity_id);
              continue;
            }

            // Update foreign table
            $foreign_id = $this->set_entry_relational($table_related, $junction_table_row['data'], $activity_id);

            $junction_table_data = array(
              $junction_key_left => $id,
              $junction_key_right => $foreign_id
            );

            if (isset($junction_table_row['id'])) $junction_table_data['id'] = $junction_table_row['id'];

            // Update junction table
            $this->set_entry_relational($junction_table, $junction_table_data, $activity_id);
          }
          break;
      }
    }
    return $id;
  }

  function set_media($data) {
    $id = $this->set_entry('directus_media', $data);
    $this->log_activity('MEDIA', 'directus_media', 'ADD', $id, $data['title'], $data);
    return $id;
  }

  function set_settings($data) {
    $collection = $data['id'];
    $keys = array('collection' => $collection);
    unset($data['id']);
    $this->set_entries('directus_settings', to_name_value($data, $keys));
    $this->log_activity('SETTINGS','directus_settings', 'UPDATE', null, $collection, $data);
  }

  function set_table_settings($data) {

    $table_settings = array(
      'table_name' => $data['table_name'],
      'hidden' => (int)$data['hidden'],
      'single' => (int)$data['single'],
      'inactive_by_default' => (int)$data['inactive_by_default'],
      'is_junction_table' => (int)$data['is_junction_table'],
      'footer' => (int)$data['footer']
    );

    $this->set_entry('directus_tables', $table_settings);

    $column_settings = array();

    foreach ($data['columns'] as $col) {
      array_push($column_settings, array(
        'table_name' => $table_settings['table_name'],
        'column_name'=>$col['column_name'],
        'ui'=>$col['ui'],
        'hidden_input'=>$col['hidden_input'],
        'required'=>$col['required'],
        'master'=>$col['master'],
        'sort'=>$col['sort'],
        'comment'=>$col['comment']
      ));
    }

    $this->set_entries('directus_columns', $column_settings);

  }

  function set_ui_options($data, $tbl_name, $column_name, $ui_name) {
    $id = $data['id'];
    unset($data['id']);
    $keys = array('table_name' => $tbl_name, 'column_name' => $column_name, 'ui_name' => $ui_name);
    $this->set_entries('directus_ui', to_name_value($data, $keys));
    $this->log_activity('UI','directus_ui', 'UPDATE', $id, $tbl_name .','. $column_name . ',' . $ui_name, $data);
  }

  function log_activity($type, $tbl_name, $action, $row_id, $identifier, $data, $parent_id=null) {
    return $this->set_entry('directus_activity', array(
      'type' => $type,
      'identifier' => $identifier,
      'table_name' => $tbl_name,
      'action' => $action,
      'row_id' => $row_id,
      'user' => 1,
      'data' => json_encode($data),
      'parent_id' => $parent_id,
      'datetime' => gmdate('Y-m-d H:i:s')
    ));
  }
};