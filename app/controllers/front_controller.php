<?php

class FrontController extends AppController {
	var $uses = array();
	var $destination = null;
	var $db = null;

	var $_countSuccessQueries = 0;
	var $_countQueries = 0;
	var $_countSuccessTables = 0;

	const NAME = 1;
	const TYPE = 2;
	const INDEX = 3;
	const NIL = 4;
	const DEFAULTS = 5;
	const OTHERS = 6;
	const COMMENT = 7;
	const RECORD_START = 9;

	function index() {
		if (!$this->configured) {
			$this->redirect(array('action' => 'config'));
		}

		if (!empty($this->data)) {
			$destination = rawurlencode($this->data['destination']);
			$database = !empty($this->data['database']) ? $this->data['database'] : null;
			$this->redirect(array('action' => 'process', $destination, $database));
		}

		$databases = Configure::read('Sheets.databases');
		$this->set(compact('databases'));
	}

	function config() {
		if (!empty($this->data['email']) && !empty($this->data['password'])) {
			$this->_storeConfig('login', $this->data);
			
			$this->redirect(array('action' => 'index'));
		}

		$this->set('email', Configure::read('Gdata.login.email'));
		$this->set('password', Configure::read('Gdata.login.password'));
	}

	function process($destination, $database = 'default') {
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
			$this->Session->setFlash(__('Google Spreadsheet not found', true));
			$this->redirect(array('action' => 'index'));
			return;
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

				if ($col == 1) {
					continue;
				}

				$table[$col][$row] = $text;
			}

			if (empty($table)) {
				continue;
			}
			$tables[$worksheetName] = $table;
		}


		$this->set('gdata_elapsed_time', microtime(true) - $gdata_start);
		$database_start = microtime(true);

		App::import('Model', 'ConnectionManager');
		$this->db =& ConnectionManager::getDataSource($database);
		$result = $this->db->query('SET FOREIGN_KEY_CHECKS=0');
		$this->_incrementSuccess($result);

		$this->set('countTables', count($tables));
		foreach ($tables as $name => $table) {
			$_name = $this->db->config['prefix'] . $name;
			$result = $this->db->query("DROP TABLE IF EXISTS `$_name`");
			$this->_incrementSuccess($result);
			$sql = 'CREATE TABLE IF NOT EXISTS `' . trim($_name) . "` (\n";
			$fieldDefs = $foreignKeys = $indexColmuns = array();
			$records = array();

			foreach ($table as $index => $row) {
				$fieldDef = '`' . $row[self::NAME] . '` ';
				$fieldDef .= $this->_getType($row[self::TYPE]);
				if (!empty($row[self::NIL])) {
					$fieldDef .= $this->_getNull($row[self::NIL], $this->_getType($row[self::TYPE]));
				}
				if (isset($row[self::DEFAULTS])) {
					$fieldDef .= $this->_getDefault($row[self::DEFAULTS]);
				}
				if (!empty($row[self::OTHERS])) {
					$fieldDef .= $this->_getOthers($row[self::OTHERS]);
				}
				if (!empty($row[self::COMMENT])) {
					$fieldDef .= $this->_getComment($row[self::COMMENT]);
				}

				if (!empty($row[self::INDEX])) {
					$colmun_index = strtolower(trim($row[self::INDEX]));
					if ($colmun_index == 'primary') {
						$fieldDef .= 'PRIMARY KEY ';
					} elseif ($colmun_index == 'unique') {
						$fieldDef .= ' UNIQUE ';
					} elseif ($colmun_index == 'index') {
						$indexColmuns[] = $row[self::NAME];
					} elseif ($colmun_index == 'foreign') {
						$parts = explode('_', $row[self::NAME]);
						array_pop($parts);
						array_push($parts, Inflector::pluralize(array_pop($parts)));
						$ref = implode('_', $parts);
						if (strpos($ref, '.') === false) {
							$ref .= '.id';
						}
						$foreignKeys[$row[self::NAME]] = $ref;
					} elseif (preg_match('/foreign\((.+?)\)/', $colmun_index, $matche)) {
						$ref = $matche[1];
						if (strpos($ref, '.') === false) {
							$ref .= '.id';
						}
						$foreignKeys[$row[self::NAME]] = $ref;
					}
				}
				$fieldDefs[$index] = $fieldDef;

				if (isset($row[self::RECORD_START])) {
					$records[$row[self::NAME]] = $this->_getRecordRow($row);
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
			$result = $this->db->query($sql);
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
				$this->db->insertMulti(trim($name), $fields, $recordsDef);
			}
		}
		$result = $this->db->query('SET FOREIGN_KEY_CHECKS=1');
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
		if (preg_match('/^(.*?int)(.*?)((un)?signed)?$/', $type, $matche)) {
			$type = $matche[1] . ' ';
			$option = $matche[2];
			$sign = !empty($matche[3]) ? strtoupper($matche[3]) : 'UNSIGNED';
			return  "$type$option$sign ";
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

		if (strtolower($type) === 'binary') {
			$type = 'blob';
		}

		$detected = false;
		if (in_array(strtolower($type), array(
			'text',
			'blob',
			'datetime',
			'float',
		))) {
			$detected = true;
		}

		foreach (array(
			'text',
			'blob',
			'date',
			'time',
		) as $typePattern) {
			if (strpos($type, $typePattern) !== false) {
				$detected = true;
			}
		}

		if (!$detected) {
			trigger_error('No valid type specified ' . $type);
		}
		return strtoupper($type) . ' ';
	}

	function _getNull($null, $type) {
		if ($null == 'no' && !preg_match('/(text|blob)/i', $type)) {
			return 'NOT NULL ';
		}
		return '';
	}

	function _getDefault($default) {
		if (strtolower(trim($default)) == 'null') {
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
		$type = $this->_getType($row[self::TYPE]);
		for ($i = self::RECORD_START; isset($row[$i]); $i++) {
			$value = $row[$i];
			switch (true) {
				case $type == 'TINYINT(1) ':
				case $type == 'INT UNSIGNED ':
				case preg_match('/float\((.+?)\)/', $type):
					break;
				case $value == 'now':
					$value = 'now()';
					break;
				case strtolower($value) == 'null':
					$value = 'NULL';
					break;
				default:
					$value = $this->db->value($value);
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
