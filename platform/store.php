<?php

/* Eregansu: Complex object store
 *
 * Copyright 2010 Mo McRoberts.
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

/**
 * @framework Eregansu
 */

uses('model', 'uuid');

class Storable implements ArrayAccess
{
	protected static $models = array();
	protected static $refs = array();
	protected $storableClass = null;
	
	public static function objectForData($data, $model = null, $className = null)
	{
		if(!$className)
		{
			$className = 'Storable';
		}
		if(!isset(self::$models[$className]))
		{
			self::$models[$className] = $model;
		}
		return new $className($data);
	}
	
	protected function __construct($data)
	{
		if(!is_arrayish($data))
		{
			throw new Exception(gettype($data) . ' passed to Storable::__construct(), array expected');
		}
		foreach($data as $k => $v)
		{
			$this->$k = $v;
		}
		$this->loaded();
	}
	
	public function store()
	{
		if(!($data = self::$models[get_class($this)]->setData($this)))
		{
			return null;
		}
		$this->reload($data);
		return $this->uuid;
	}
	
	public function reload($data = null)
	{
		static $uuid = null;
		
		if(!$uuid && isset($this->uuid))
		{
			$uuid = $this->uuid;
		}
		$keys = array_keys(get_object_vars($this));
		foreach($keys as $k)
		{
			if($k == 'storableClass') continue;
			unset($this->$k);
		}
		if(!$data)
		{
			$data = self::$models[get_class($this)]->dataForUUID($uuid);
		}
		if($data)
		{		
			foreach($data as $k => $v)
			{
				$this->$k = $v;
			}
			$this->loaded(true);
			$uuid = $this->uuid;
		}
		return $uuid;
	}
	
	protected function loaded($reloaded = false)
	{
	}
	
	public function offsetExists($name)
	{
		return isset($this->$name);
	}
	
	public function offsetGet($name)
	{
		if(isset($this->$name) && isset($this->_refs) && in_array($name, $this->_refs))
		{
			return $this->referencedObject($this->$name, $name);
		}
		return $this->$name;
	}
	
	public function offsetSet($name, $value)
	{
		if(is_object($value))
		{
			$value = $value->uuid;
		}
		if(null !== ($uuid = UUID::isUUID($value)))
		{
			$this->referenceObject($name, $uuid);
		}
		else
		{
			$this->$name = $value;
		}
	}
	
	public function offsetUnset($name)
	{
		unset($this->$name);
	}

	protected function referenceObject($name, $uuid)
	{
		if(is_object($uuid))
		{
			$uuid = $uuid->uuid;
		}
		$this->$name = $uuid;
		if(!isset($this->_refs) || !is_array($this->_refs))
		{
			$this->_refs = array();
		}
		if(!in_array($name, $this->_refs))
		{
			$this->_refs[] = $name;
		}
	}
	
	protected function referencedObject($id, $propName = null)
	{
		$className = get_class($this);
		if(!isset(self::$refs[$className])) self::$refs[$className] = array();
		if(is_array($id))
		{
			if($propName === null)
			{
				return null;
			}
			if(!isset(self::$refs[$className][':' . $propName]))
			{
				$args = array('storableClass' => ($this->storableClass === null ? $className : $this->storableClass), 'list' => $id);
				self::$refs[$className][':' . $propName] = new StaticStorableSet(self::$models[$className], $args);
			}
			return self::$refs[$className][':' . $propName];
		}
		if(!isset(self::$refs[$className][$id]))
		{
			self::$refs[$className][$id] = self::$models[$className]->objectForUUID($id);
		}
		return self::$refs[$className][$id];
	}
}

abstract class StorableSet implements DataSet, Countable
{
	protected $model;
	public $EOF = true;
	
	public function __construct($model, $args)
	{
		$this->model = $model;	
		if(isset($args['storableClass']))
		{
			$this->storableClass = $args['storableClass'];
		}
	}
	
	public function rewind()
	{
	}
	
	public function valid()
	{
		$valid = !$this->EOF;
		return $valid;
	}
	
	public function count()
	{
		return 0;
	}

	protected function storableForEntry($entry, $rowData = null)
	{
		if(is_string($entry))
		{
			if(!($obj = $this->model->dataForUUID($entry)))
			{
				return null;
			}
			$entry = $obj;
		}
		if(is_array($rowData))
		{
			$entry['uuid'] = $rowData['uuid'];
			$entry['created'] = $rowData['created'];
			if(strlen($rowData['creator_uuid']))
			{
				$entry['creator'] = array('scheme' => $rowData['creator_scheme'], 'uuid' => $rowData['creator_uuid']);
			}
			$entry['modified'] = $rowData['modified'];
			if(strlen($rowData['modifier_uuid']))
			{
				$entry['modifier'] = array('scheme' => $rowData['modifier_scheme'], 'uuid' => $rowData['modifier_uuid']);
			}
			$entry['owner'] = $rowData['owner'];		
		}
		return call_user_func(array($this->storableClass, 'objectForData'), $entry, $this->model, $this->storableClass);	
	}
	
}

class StaticStorableSet extends StorableSet
{
	protected $list;
	protected $keys;
	protected $storableClass = 'Storable';
	protected $count;
	protected $current;
	protected $valid = true;
	public $total;

	public function __construct($model, $args)
	{
		parent::__construct($model, $args);
		$this->list = $args['list'];
		$this->rewind();
		if(isset($args['total']))
		{
			$this->total = $args['total'];
		}
		else
		{
			$this->total = count($this->list);
		}
	}

	public function count()
	{
		return count($this->list);
	}

	public function valid()
	{
		return $this->valid;
	}
	
	public function next()
	{
		if(!$this->EOF)
		{
			if(null !== ($k = array_shift($this->keys)))
			{
				$this->current = $this->storableForEntry($this->list[$k]);
				$this->count++;
				if(!count($this->keys))
				{
					$this->EOF = true;
				}
				return $this->current;
			}
		}
		$this->current = null;
		$this->EOF = true;
		$this->valid = false;
		return null;
	}
	
	public function key()
	{
		return ($this->valid ? null : $this->count);
	}
	
	public function current()
	{
		if($this->current === null)
		{
			$this->next();
		}
		return $this->current;
	}
	
	public function rewind()
	{
		$this->count = 0;
		$this->current = null;
		if(count($this->list))
		{
			$this->EOF = false;
			$this->valid = true;
			$this->keys = array_keys($this->list);
		}
		else
		{
			$this->EOF = true;
			$this->keys = array();
			$this->valid = false;
		}
	}
}

class DBStorableSet extends StorableSet
{
	protected $rs;
	protected $current;
	protected $key;
	public $offset;
	public $limit;
	public $total;
	
	public function __construct($model, $args)
	{
		$this->model = $model;
		$this->rs = $args['recordSet'];
		if(isset($args['storableClass']))
		{
			$this->storableClass = $args['storableClass'];
		}		
		$this->total = $this->rs->total;
		if(isset($args['offset'])) $this->offset = $args['offset'];
		if(isset($args['limit'])) $this->limit = $args['limit'];
		$this->rewind();
	}

	protected function updateCurrent()
	{
		$this->EOF = $this->rs->EOF;
		if(($this->data = $this->rs->fields))
		{
			if($this->data['uuid'] != $this->key)
			{
				$this->count++;
				$this->key = $this->data['uuid'];
				$data = json_decode($this->data['data'], true);
				$this->current = $this->storableForEntry($data, $this->data);
			}
		}
		else
		{
			$this->key = $this->current = null;
		}
	}

	public function valid()
	{
		$r = $this->rs->valid();
		$this->updateCurrent();
		return $r;
	}

	public function current()
	{
		$this->rs->current();
		$this->updateCurrent();
		return $this->current;
	}
	
	public function key()
	{
		$this->rs->key();
		$this->updateCurrent();
		return $this->key;
	}
		
	public function next()
	{
		$this->rs->next();
		$this->updateCurrent();
		return $this->current;
	}
	
	public function rewind()
	{	
		$this->count = 0;		
		$this->rs->rewind();
		$this->updateCurrent();
	}
	
	public function count()
	{
		return $this->count;
	}
}

class Store extends Model
{
	protected $storableClass = 'Storable';
	
	/* The name of the 'objects' table */
	protected $objects = 'object';
	protected $objects_base = 'object_base';
	protected $objects_iri = 'object_iri';
	protected $objects_tags = 'object_tags';
	
	public static function getInstance($args = null)
	{
		if(!isset($args['class'])) $args['class'] = 'Store';
		return parent::getInstance($args);
	}
	
	public function __construct($args)
	{
		if(isset($args['objectsTable'])) $this->objects = $args['objectsTable'];
		if(isset($args['objectsBaseTable'])) $this->objects_base = $args['objectsBaseTable'];
		if(isset($args['objectsIriTable'])) $this->objects_iri = $args['objectsIriTable'];
		if(isset($args['objectsTagsTable'])) $this->objects_tags = $args['objectsTagsTable'];
		parent::__construct($args);
	}
	
	public function objectForUUID($uuid)
	{
		if(!($data = $this->dataForUUID($uuid)))
		{
			return null;
		}
		$class = $this->storableClass;
		return call_user_func(array($this->storableClass, 'objectForData'), $data, $this, $this->storableClass);
	}
	
	public function dataForUUID($uuid)
	{
		if(!($row = $this->db->row('SELECT * FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid)))
		{
			return null;
		}
		$data = json_decode($row['data'], true);
		/* Ensure these are set from the outset */
		$this->retrievedMeta($data, $row);
		return $data;
	}
		
	public function setData($data, $user = null, $lazy = false, $owner = null)
	{
		if(is_object($data))
		{
			$data = get_object_vars($data);
		}
		if(isset($data['uuid']) && strlen($data['uuid']) == 36)
		{
			$uuid = $data['uuid'];
		}
		else
		{
			$uuid = UUID::generate();
		}
		$user_scheme = $user_uuid = null;
		$uuid = strtolower($uuid);
		if(isset($user))
		{
			if(!is_array($user) || !isset($user['scheme']) || !isset($user['uuid']) || !UUID::isUUID($user['uuid']))
			{
				return null;
			}
			$user_scheme = $user['scheme'];
			$user_uuid = $user['uuid'];
		}
		if(isset($data['created']))
		{
			$created = $this->db->quote($data['created']);
		}
		else
		{
			$created = $this->db->now();
		}
		if($owner !== null && isset($data['owner']) && !strcmp($data['owner'], $owner))
		{
			$owner = null;
		}			   
		unset($data['uuid']);
		unset($data['created']);
		unset($data['modified']);
		unset($data['creator']);
		unset($data['modifier']);
		unset($data['dirty']);
		unset($data['owner']);
		$json = json_encode($data);
		do
		{
			$this->db->begin();
			$entry = $this->db->row('SELECT "uuid" FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid);
			if($entry)
			{
				$this->db->exec('UPDATE {' . $this->objects . '} SET "data" = ?, "dirty" = ?, "modified" = ' . $this->db->now () . ', "modifier_scheme" = ?, "modifier_uuid" = ? WHERE "uuid" = ?', $json, 'Y', $user_scheme, $user_uuid, $uuid);
				if(isset($owner))
				{
					$this->db->exec('UPDATE {' . $this->objects . '} SET "owner" = ? WHERE "uuid" = ?', $owner, $uuid);
				}
			}
			else
			{
				$this->db->insert($this->objects, array(
					'uuid' => $uuid,
					'data' => $json,
					'@created' => $created,
					'creator_scheme' => $user_scheme,
					'creator_uuid' => $user_uuid,
					'@modified' => $this->db->now(),
					'modifier_scheme' => $user_scheme,
					'modifier_uuid' => $user_uuid,
					'dirty' => 'Y',
					'owner' => $owner,
				));
			}
		}
		while(!$this->db->commit());
		$row = $this->db->row('SELECT "uuid", "created", "creator_scheme", "creator_uuid", "modified", "modifier_scheme", "modifier_uuid", "owner" FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid);
		$this->retrievedMeta($data, $row);
		$this->stored($data, $json, $lazy);
		return $data;
	}
	
	protected function buildQuery(&$qlist, &$tables, &$query)
	{
		if(!isset($tables['base'])) $tables['base'] = $this->objects_base;
		if(!isset($tables['iri'])) $tables['iri'] = $this->objects_iri;
		foreach($query as $k => $v)
		{
			$value = $v;
			switch($k)
			{
				case 'uuid':
					unset($query[$k]);
					$qlist['obj'][] = '"obj"."uuid" = ' . $this->db->quote($value);
					break;
				case 'kind':
					unset($query[$k]);
					if(is_array($value))
					{
						$qal = array();
						foreach($value as $v)
						{
							$qal[] = '"base"."kind" = ' . $this->db->quote($v);
						}
						$qlist['base'][] = '(' . implode(' OR ', $qal) . ')';
					}
					else
					{
						$qlist['base'][] = '"base"."kind" = ' . $this->db->quote($value);
					}
					break;
				case 'tag':
					unset($query[$k]);
					$qlist['base'][] = '"base"."tag" = ' . $this->db->quote($value);
					break;
				case 'realm':
					unset($query[$k]);
					$qlist['base'][] = '"base"."realm" = ' . $this->db->quote($value);
					break;
				case 'iri':
					unset($query[$k]);
					$qlist['iri'][] = '"iri"."iri" = ' . $this->db->quote($value);
					break;
			}
		}		
	}
	
	protected function parseOrder(&$order, $key, $desc = true)
	{
		$dir = $desc ? 'DESC' : 'ASC';
		switch($key)
		{
			case 'created':
				$order['obj'][] = '"obj"."created" ' . $dir;
				return true;		
		}
		return false;
	}
	
	public function query($query)
	{
		$tables = array('obj' => $this->objects);
		$qlist = array();
		$order = array();
		$olist = array();
		$tags = array();
		$ord = array();
		$ofs = $limit = 0;		
		if(isset($query['order']))
		{
			$ord = $query['order'];
			unset($query['order']);
			if(!is_array($ord))
			{
				$ord = explode(',', str_replace(' ', ',', $ord));
			}
		}
		if(isset($query['offset']))
		{
			$ofs = intval($query['offset']);
			unset($query['offset']);
		}
		if(isset($query['limit']))
		{
			$limit = intval($query['limit']);
			unset($query['limit']);
		}
		$this->buildQuery($qlist, $tables, $query);
		foreach($ord as $o)
		{
			$o = trim($o);
			if(!strlen($o)) continue;
			$desc = true;
			if(substr($o, 0, 1) == '-')
			{
				$desc = false;
				$o = substr($o, 1);
			}
			if(!$this->parseOrder($order, $o, $desc))
			{
				trigger_error('Store::query(): Unknown ordering key ' . $o, E_USER_WARNING);
			}
		}
		foreach($order as $table => $ref)
		{
			if($table != 'obj' && !isset($qlist[$table])) $qlist[$table] = array();
			foreach($ref as $oo)
			{
				$olist[] = $oo;
			}
		}
		$tlist = array('{' . $this->objects . '} "obj"');
		$where = array();
		foreach($qlist as $table => $clauses)
		{
			if($table != 'obj')
			{
				if(is_array($tables[$table]))
				{
					$tname = $tables[$table]['name'];
					$tjoin = $tables[$table]['clause'];
				}
				else
				{
					$tname = $tables[$table];
					$tjoin = '"' . $table . '"."uuid" = "obj"."uuid"';
				}
				$tlist[] = '{' . $tname . '} "' . $table . '"';
				$where[] = $tjoin;
			}
			foreach($clauses as $c)
			{
				$where[] = $c;
			}
		}
		if(isset($query['tags']))
		{
			if(is_array($query['tags']))
			{
				$tags = $query['tags'];
			}
			else
			{
				$tags = explode(',', str_replace(' ', ',', $query['tags']));
			}
			unset($query['tags']);
		}
		if(count($query))
		{
			foreach($query as $k => $v)
			{
				trigger_error('Store::query(): Unsupported query key ' . $k, E_USER_WARNING);
			}
		}
		if(count($tags))
		{
			$tc = 0;
			foreach($tags as $t)
			{
				$t = strtolower(trim($t));
				if(!strlen($t)) continue;
				$tc++;
				$tn = '"t' . $tc . '"';
				$tlist[] = '{' . $this->objects_tags . '} ' . $tn;
				$where[] = $tn . '."tag" = ' . $this->db->quote($t);
				$where[] = $tn . '."uuid" = "obj"."uuid"';
			}
		}
		$qstr = 'SELECT /*!SQL_CALC_FOUND_ROWS*/ "obj".* FROM ( ' . implode(', ', $tlist) . ' )';
		if(count($where))
		{
			$qstr .= ' WHERE ' . implode(' AND ', $where);
		}
		$qstr .= ' GROUP BY "obj"."uuid"';
		if(count($olist))
		{
			$qstr .= ' ORDER BY ' . implode(', ', $olist);
		}
		if($limit)
		{
			if($ofs)
			{
				$qstr .= ' LIMIT ' . $ofs . ', ' . $limit;
			}
			else
			{
				$qstr .= ' LIMIT ' . $limit;
			}
		}
		if(($rs = $this->db->query($qstr)))
		{
			$query['recordSet'] = $rs;
			$query['storableClass'] = $this->storableClass;
			return new DBStorableSet($this, $query);
		}
		return null;
	}
	
	public function object($query)
	{
		if(($rs = $this->query($query)))
		{
			return $rs->next();
		}
		return null;
	}
	
	public function objectForIri($iri)
	{
		return $this->object(array('iri' => $iri));
	}
	
	public function updateObjectWithUUID($uuid)
	{
		if(!($row = $this->db->row('SELECT * FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid)))
		{
			return false;
		}
		$data = json_decode($row['data'], true);
		$this->retrievedMeta($data, $row);
		$this->stored($data, $row['data'], false);
		return true;
	}
	
	protected function retrievedMeta(&$data, $row)
	{
		$data['uuid'] = $row['uuid'];
		$data['created'] = $row['created'];
		if(strlen($row['creator_uuid']))
		{
			$data['creator'] = array('scheme' => $row['creator_scheme'], 'uuid' => $row['creator_uuid']);
		}
		$data['modified'] = $row['modified'];
		if(strlen($row['modifier_uuid']))
		{
			$data['modifier'] = array('scheme' => $row['modifier_scheme'], 'uuid' => $row['modifier_uuid']);
		}
		$data['owner'] = $row['owner'];
	}
	
	protected function stored($data, $json = null, $lazy = false)
	{
		if(!isset($data['kind']) || !strlen($data['kind']) || !isset($data['uuid']))
		{
			return false;
		}
		$uuid = strtolower(trim($data['uuid']));
		if(!strlen($uuid))
		{
			return false;
		}
		if($lazy)
		{
			return true;
		}
		if(defined('OBJECT_CACHE_ROOT'))
		{
			try
			{
				if(!file_exists(OBJECT_CACHE_ROOT))
				{
					mkdir(OBJECT_CACHE_ROOT, 0777, true);
				}
				$dir = OBJECT_CACHE_ROOT . $data['kind'] . '/' . substr($uuid, 0, 2) . '/';
				if(null == $json)
				{
					$json = json_encode($data);
				}
				if(!file_exists($dir))
				{
					mkdir($dir, 0777, true);
				}
				$f = fopen($dir . $uuid . '.json', 'w');
				fwrite($f, $json);
				fclose($f);
				try
				{
					chmod($dir . $uuid . '.json', 0666);
				}
				catch (Exception $e)
				{
				}
			}
			catch(Exception $e)
			{
				if(php_sapi_name() == 'cli')
				{
					echo str_repeat('=', 79) . "\n" . $e . "\n" . str_repeat('=', 79) . "\n";
				}				
			}
		}
		$this->db->perform(array($this, 'storedTransaction'), array('uuid' => strtolower(trim($data['uuid'])), 'data' => $data, 'json' => $json, 'lazy' => $lazy));
		return true;
	}

	public function deleteObjectWithUUID($uuid)
	{
		$this->db->exec('DELETE FROM {' . $this->objects . '} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {' . $this->objects_base . '} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {' . $this->objects_tags . '} WHERE "uuid" = ?', $uuid);
		$this->db->exec('DELETE FROM {' . $this->objects_iri . '} WHERE "uuid" = ?', $uuid);
		return true;
	}

	public /*callback*/ function storedTransaction($db, $args)
	{
		$uuid = $args['uuid'];
		$json = $args['json'];
		$lazy = $args['lazy'];
		$data = $args['data'];

		$baseinfo = array();
		
		$this->db->query('DELETE FROM {' . $this->objects_base . '} WHERE "uuid" = ?', $uuid);
		$this->db->query('DELETE FROM {' . $this->objects_tags . '} WHERE "uuid" = ?', $uuid);
		$this->db->query('DELETE FROM {' . $this->objects_iri . '} WHERE "uuid" = ?', $uuid);
		
		if($data['kind'] == 'realm' && !isset($data['realm']))
		{
			$data['realm'] = $uuid;
		}
		if(isset($data['kind'])) $baseinfo['kind'] = $data['kind']; 
		if(isset($data['realm'])) $baseinfo['realm'] = $data['realm'];
		if(isset($data['tag'])) $baseinfo['tag'] = strtolower(trim($data['tag']));
		if(count($baseinfo))
		{
			$baseinfo['uuid'] = $uuid;
			$this->db->insert($this->objects_base, $baseinfo);
		}

		if(isset($data['tags']))
		{
			if(!is_array($data['tags']))
			{
				$data['tags'] = array($data['tags']);
			}
			foreach($data['tags'] as $tag)
			{
				$tag = trim(strtolower($tag));
				if(strlen($tag))
				{
					$this->db->insert($this->objects_tags, array('uuid' => $uuid, 'tag' => $tag));
				}
			}
		}
		if(isset($data['iri']))
		{
			if(!is_array($data['iri']))
			{
				$data['iri'] = array($data['iri']);
			}
			foreach($data['iri'] as $iri)
			{
				$this->db->insert($this->objects_iri, array('uuid' => $uuid, 'iri' => $iri));
			}
		}
		$this->db->query('UPDATE {' . $this->objects . '} SET "dirty" = ? WHERE "uuid" = ?', 'N', $uuid);
		return true;
	}

	public function pendingObjectsSet($limit = null)
	{
		if(!($limit = intval($limit)))
		{
			$limit = 10;
		}
		return $this->db->query('SELECT "uuid" FROM {' . $this->objects . '} WHERE "dirty" = ? LIMIT ' . $limit, 'Y');
	}

	public function markAllAsDirty()
	{
		$this->db->query('UPDATE {' . $this->objects . '} SET "dirty" = ?', 'Y');
	}
}
