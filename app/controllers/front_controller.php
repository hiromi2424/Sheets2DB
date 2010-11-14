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

		App::import('Model', 'ConnectionManager');
		$db =& ConnectionManager::getDataSource('default');
		$result = $db->query('SET FOREIGN_KEY_CHECKS=0');
		$this->_incrementSuccess($result);

		$this->set('countTables', count($tables));
		foreach ($tables as $name => $table) {
			$result = $db->query("DROP TABLE IF EXISTS `$name`");
			$this->_incrementSuccess($result);
			$sql = 'CREATE TABLE IF NOT EXISTS `' . trim($name) . "` (\n";
			$fieldDefs = $foreignKeys = array();
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
					if (trim($row[1]) == 'primary') {
						$fieldDef .= 'primary key ';
					} elseif (trim($row[1]) == 'foreign') {
						$parts = explode('_', $row[2]);
						array_pop($parts);
						array_push($parts, Inflector::pluralize(array_pop($parts)));
						$ref = implode('_', $parts);
						if (strpos($ref, '.') === false) {
							$ref .= '.id';
						}
						$foreignKeys[$row[2]] = $ref;
					} elseif (preg_match('/foreign\((.+?)\)/', $row[1], $matche)) {
						$ref = $matche[1];
						if (strpos($ref, '.') === false) {
							$ref .= '.id';
						}
						$foreignKeys[$row[2]] = $ref;
					}
				}
				$fieldDefs[$index] = $fieldDef;
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
			$sql .= ") ENGINE=InnoDB  DEFAULT CHARSET=utf8";
			$result = $db->query($sql);
			$this->_incrementSuccess($result);
			$this->_incrementCreateTableSuccess($result);
		}
		$result = $db->query('SET FOREIGN_KEY_CHECKS=1');
		$this->_incrementSuccess($result);

		$this->set('countSuccessTables', $this->_countSuccessTables);
		$this->set('countSuccessQueries', $this->_countSuccessQueries);
		$this->set('countQueries', $this->_countQueries);
	}

	function _getType($type) {
		$type = strtolower(trim($type));
		if ($type == 'int') {
			return 'int unsigned ';
		}
		if ($type == 'bool') {
			return 'tinyint(1) ';
		}
		if (preg_match('/char\((.+?)\)/', $type, $matche)) {
			$length = $matche[1];
			return "varchar($length) ";
		}
		if (preg_match('/float\((.+?)\)/', $type, $matche)) {
			return "$type ";
		}
		if ($type == 'text') {
			return 'text ';
		}
		if ($type == 'datetime') {
			return 'datetime ';
		}
		trigger_error('No valid type specified ' . $type);
		return 'undefined ';
	}

	function _getNull($null, $type) {
		if ($null == 'no' && $type !== 'text') {
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