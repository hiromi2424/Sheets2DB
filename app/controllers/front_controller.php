<?php

class FrontController extends AppController {
	var $uses = array();
	var $destination = null;

	var $_countSuccessQueries = 0;
	var $_countQueries = 0;
	var $_countSuccessTables = 0;

	function index() {
		if (!$this->configured) {
			$this->redirect(array('action' => 'config'));
		}

		if (!empty($this->data)) {
			$destination = rawurlencode($this->data['destination']);
			$this->redirect(array('action' => 'process', $destination));
		}
	}

	function config() {
		if (!empty($this->data['email']) && !empty($this->data['password'])) {
			$this->_storeConfig('login', $this->data);
			
			$this->redirect(array('action' => 'config'));
		}

		$this->set('email', Configure::read('Gdata.login.email'));
		$this->set('password', Configure::read('Gdata.login.password'));
	}

	function process($destination) {
		if (!$this->configured) {
			$this->redirect(array('action' => 'config'));
		}

		$this->destination = $destination;

		$gdata_start = microtime(true);

		$client = new Zend_Gdata_SpreadSheets($this->gdata_client);
		$feed = $client->getSpreadsheetFeed();
		$search = $this->destination;

		$spreadsheetKey = null;
		foreach($feed->entries as $index => $entry) {
			if ($entry->title->text == $search) {
				$id = explode('/', $entry->id->text);
				$spreadsheetKey = end($id);
			}
		}

		if ($spreadsheetKey === null) {
			die('見つからないよ。');
		}

		$query = new Zend_Gdata_Spreadsheets_DocumentQuery();
		$query->setSpreadsheetKey($spreadsheetKey);
		$feed = $client->getWorksheetFeed($query);

		$tables = array();
		foreach ($feed->entries as $entry) {
			$worksheetName = $entry->title->text;
			if ($worksheetName == '表紙') {
				continue;
			}
			$worksheetId = end(explode('/', $entry->id->text));

			$query = new Zend_Gdata_Spreadsheets_CellQuery();
			$query->setSpreadsheetKey($spreadsheetKey);
			$query->setWorksheetId($worksheetId);
			$cellFeed = $client->getCellFeed($query);

			$table = array();
			foreach ($cellFeed->entries as $cellEntry) {
				$text = $cellEntry->cell->text;
				$row = $cellEntry->cell->row;
				$col = $cellEntry->cell->col;

				if ($row < 3) {
					continue;
				}

				$table[$row][$col] = $text;
			}
			
			if (empty($table[3][2])) {
				continue;
			}
			foreach ($table as $index => $row) {
				if (empty($row[2])) {
					unset($table[$index]);
				}
			}
			if (empty($table)) {
				continue;
			}
			$tables[$worksheetName] = $table;
		}
		$this->set('gdata_elapsed_time', microtime(true) - $gdata_start);
		$database_start = microtime(true);

		App::import('Model', 'ConnectionManager');
		$db =& ConnectionManager::getDataSource('default');
		$result = $db->query('SET FOREIGN_KEY_CHECKS=0');
		$this->_incrementSuccess($result);

		$this->set('countTables', count($tables));
		foreach ($tables as $name => $table) {
			$result = $db->query("DROP TABLE IF EXISTS `$name`");
			$this->_incrementSuccess($result);
			$sql = 'CREATE TABLE IF NOT EXISTS `' . trim($name) . "` (\n";
			$fieldDefs = $foreignKeys = $indexColmuns = array();
			$records = array();
			foreach ($table as $index => $row) {
				$fieldDef = '`' . $row[2] . '` ';
				$fieldDef .= $this->_getType($row[3]);
				$fieldDef .= $this->_getNull($row[4], $this->_getType($row[3]));
				if (isset($row[5])) {
					$fieldDef .= $this->_getDefault($row[5]);
				}
				if (!empty($row[6])) {
					$fieldDef .= $this->_getOthers($row[6]);
				}
				if (!empty($row[7])) {
					$fieldDef .= $this->_getComment($row[7]);
				}
				if (!empty($row[1])) {
					$colmun_index = strtolower(trim($row[1]));
					if ($colmun_index == 'primary') {
						$fieldDef .= 'PRIMARY KEY ';
					} elseif ($colmun_index == 'unique') {
						$fieldDef .= ' UNIQUE ';
					} elseif ($colmun_index == 'index') {
						$indexColmuns[] = $row[2];
					} elseif ($colmun_index == 'foreign') {
						$parts = explode('_', $row[2]);
						array_pop($parts);
						array_push($parts, Inflector::pluralize(array_pop($parts)));
						$ref = implode('_', $parts);
						if (strpos($ref, '.') === false) {
							$ref .= '.id';
						}
						$foreignKeys[$row[2]] = $ref;
					} elseif (preg_match('/foreign\((.+?)\)/', $colmun_index, $matche)) {
						$ref = $matche[1];
						if (strpos($ref, '.') === false) {
							$ref .= '.id';
						}
						$foreignKeys[$row[2]] = $ref;
					}
				}
				$fieldDefs[$index] = $fieldDef;

				if (isset($row[9])) {
					$records[$row[2]] = $this->_getRecordRow($row);
				}
			}
			$sql .= implode(",\n", $fieldDefs);
			if (!empty($foreignKeys)) {
				$sql .= ",\n";
				foreach ($foreignKeys as $from => $to) {
					list($toTable, $toField) = explode('.', $to);
					$foreignKeys[$from] = "FOREIGN KEY ($from) REFERENCES $toTable($toField)";
				}
				$sql .= implode(",\n", $foreignKeys);
			}

			if (!empty($indexColmuns)) {
				$sql .= ",\n";
				foreach ($indexColmuns as $i => $colmun) {
					$indexColmuns[$i] = " INDEX (`$colmun`) ";
				}
				$sql .= implode(",\n", $indexColmuns);
			}

			$sql .= ") ENGINE=InnoDB  DEFAULT CHARSET=utf8";
			$result = $db->query($sql);
			$this->_incrementSuccess($result);
			$this->_incrementCreateTableSuccess($result);

			if (!empty($records)) {
				$fields = array_keys($records);
				$recordsDef = array();
				foreach ($records as $field => $values) {
					foreach ($values as $index => $value) {
						$recordsDef[$index][array_search($field, $fields)] = $value;
					}
				}

				foreach ($recordsDef as $index => $values) {
					$recordsDef[$index] = '(' . implode(', ', $recordsDef[$index]) . ')';
				}
				$db->insertMulti(trim($name), $fields, $recordsDef);
			}
		}
		$result = $db->query('SET FOREIGN_KEY_CHECKS=1');
		$this->_incrementSuccess($result);

		$this->set('database_elapsed_time', microtime(true) - $database_start);

		$this->set('countSuccessTables', $this->_countSuccessTables);
		$this->set('countSuccessQueries', $this->_countSuccessQueries);
		$this->set('countQueries', $this->_countQueries);
	}

	function _getType($type) {
		$type = strtolower(trim($type));
		if ($type == 'int') {
			return 'INT UNSIGNED ';
		}
		if ($type == 'bool' || $type == 'boolean') {
			return 'TINYINT(1) ';
		}
		if (preg_match('/char\((.+?)\)/', $type, $matche)) {
			$length = $matche[1];
			return "VARCHAR($length) ";
		}
		if (preg_match('/float\((.+?)\)/', $type, $matche)) {
			return "$type ";
		}
		if ($type == 'text') {
			return 'TEXT ';
		}
		if ($type == 'datetime') {
			return 'DATETIME ';
		}
		if ($type == 'blob' || $type == 'binary') {
			return 'BLOB ';
		}
		if (strpos($type, 'text') !== false || strpos($type, 'blob') !== false) {
			return strtoupper($type) . ' ';
		}
		if (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
			return strtoupper($type) . ' ';
		}
		trigger_error('No valid type specified ' . $type);
		return 'undefined ';
	}

	function _getNull($null, $type) {
		if ($null == 'no' && !preg_match('/(text|blob)/i', $type)) {
			return 'NOT NULL ';
		}
		return '';
	}

	function _getDefault($default) {
		if (trim($default) == 'null') {
			$default = 'NULL';
		} elseif (!is_numeric($default)) {
			$default = "'" . $default . "'";
		}
		return "DEFAULT $default ";
	}

	function _getOthers($others) {
		if (preg_match('/auto.+?increment/', $others, $matche)) {
			return "AUTO_INCREMENT ";
		}
		return '';
	}

	function _getcomment($comment) {
		return "COMMENT '$comment' ";
	}

	function _incrementSuccess($result) {
		if ($result) {
			$this->_countSuccessQueries ++;
		}
		$this->_countQueries ++;
	}

	function _incrementCreateTableSuccess($result) {
		if ($result) {
			$this->_countSuccessTables ++;
		}
	}

	function _getRecordRow($row) {
		$result = array();
		$type = $this->_getType($row[3]);
		for ($i = 9; isset($row[$i]); $i++) {
			$value = $row[$i];
			switch (true) {
				case $type == 'TINYINT(1) ':
				case $type == 'INT UNSIGNED ':
				case preg_match('/float\((.+?)\)/', $type):
				case $value == 'null':
					break;
				case $value == 'now':
					$value = 'now()';
					break;
				default:
					$value = "'$value'";
			}
			$result[] = $value;
		}
		return $result;
	}

	function _debug($object) {
		if (is_object($object)) {
			$cloned = clone $object;
			if (property_exists($cloned, 'service')) {
				unset($cloned->service);
			}
			if (property_exists($cloned, 'link')) {
				unset($cloned->link);
			}
			if (property_exists($cloned, '_namespaces')) {
				unset($cloned->namespaces);
			}
			var_dump($cloned);
		} else {
			var_dump($object);
		}
	}
}