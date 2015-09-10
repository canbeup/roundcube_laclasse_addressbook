<?php

require_once(dirname(__FILE__) . '/laclasse_api.inc.php');

/**
 * Laclasse.com custom address book
 *
 * @author Daniel Lacroix
 */
class laclasse_addressbook_backend extends rcube_addressbook
{
  public $primary_key = 'ID';
  public $readonly = true;
  public $groups = true;

  private $current_group = null;
  private $filter = null;
  private $result = null;
  private $id;
  private $name;
  private $data;
  private $persons;
  private $allGroups;
  private $allProfils;
  private $cfg;

  public function __construct($id, $name)
  {
	$this->id = $id;
    $this->name = $name;
	$this->allGroups = array();
	$this->allProfils = array();
    $cfg = rcmail::get_instance()->config->all();
    $this->cfg = $cfg;
	$this->data = json_decode(interroger_annuaire_ENT(
      $cfg['laclasse_addressbook_api_etab'].$id.'/users',
      $cfg['laclasse_addressbook_app_id'], $cfg['laclasse_addressbook_api_key'],
        array('expand' => 'true')));

	$this->load_persons();
    $this->ready = true;
  }

  public function get_name()
  {
    return $this->name;
  }

  public function set_search_set($filter)
  {
    $this->filter = $filter;
  }

  public function get_search_set()
  {
    return $this->filter;
  }

  public function reset()
  {
    $this->result = null;
    $this->filter = null;
  }

  function load_persons()
  {
	$this->persons = array();

	foreach($this->data->data as $record) {
		$email = null;
		if($record->emails !== null) {
			foreach($record->emails as $emailRecord) {
				if(($email === null) || ($emailRecord->principal === "1") || ($emailRecord->type === "Ent"))
					$email = $emailRecord->adresse;
			}
		}

		// search groups
		$groups = array();
		if($record->eleve_regroupements !== null) {
			foreach($record->eleve_regroupements as $groupRecord) {
				// if its a new group for us, add it
				if(!array_key_exists($groupRecord->id, $this->allGroups)) {
					$this->allGroups[$groupRecord->id] = array('ID' => $groupRecord->id, 'name' => $groupRecord->libelle);
				}
				array_push($groups, $groupRecord->id);
			}
		}

		// handle profils like groups
		if($record->profils !== null) {
			foreach($record->profils as $groupRecord) {
				// if its a new group for us, add it
				if(!array_key_exists($groupRecord->id, $this->allProfils)) {
					$this->allProfils[$groupRecord->id] = array('ID' => $groupRecord->id, 'name' => $groupRecord->libelle);
				}
				array_push($groups, $groupRecord->id);
			}
		}

		$photo = null;
		if(($record->avatar !== null) && ($record->avatar !== 'empty') && (strpos($record->avatar, '/default_avatar/') === false)) {
			$photo = $this->cfg['laclasse_addressbook_api_avatar'].$record->avatar;
		}

		array_push($this->persons, array(
			'ID' => $record->{'id_ent'},
			'name' => $record->{'prenom'}.' '.$record->{'nom'},
			'firstname' => $record->{'prenom'},
			'surname' => $record->{'nom'},
			'email' => $email,
			'photo' => $photo,
			'groups' => $groups
		));
	}
  }

  function filter_field($all, $search, $field)
  {
    $res = array();
    $search = strtolower($search);
    foreach($all as $item) {
      if(array_key_exists($field, $item)) {
        $value = $item[$field];
        if(is_array($value)) {
          $match = false;
          foreach($value as $sub_value)
            $match = $match || (strtolower($sub_value) === $search);
          if($match)
            array_push($res, $item);
        }
        else if(strtolower($value) === $search)
          array_push($res, $item);
	  }
    }
    return $res;
  }

  function filter_result($all, $search, $fields)
  {
	if($fields === '*')
		$fields = array('name','email','firstname','surname');

	if($search === null)
		return $all;
	else {
		$words = explode(' ', $search);
		$res = array();
		foreach($all as $item) {
			$match = true;
			foreach($words as $word) {
				$word = strtolower($word);
				$word_match = false;
				foreach($fields as $field) {
					$word_match = $word_match || (strrpos(strtolower($item[$field]), $word) !== false);
				}
				$match = $match && $word_match;
			}
			if($match)
				array_push($res, $item);
		}
		return $res;
	}
  }

  function array_to_result($all, $page_size = 2000, $list_page = 1)
  {
	$slice = array_slice($all, ($list_page-1) * $page_size, $page_size);

	$res = new rcube_result_set(count($all), ($list_page-1) * $page_size);
	foreach($slice as $item) {
		$res->add($item);
	}
	return $res;
  }

  function set_group($gid)
  {
    if(!$gid)
      $this->current_group = null;
    else
      $this->current_group = $gid;
  }

  static function group_cmp($a, $b)
  {
    return strcmp($a["name"], $b["name"]);
  }

  function list_groups($search = null)
  {
	$allProfils = array();
	foreach($this->allProfils as $group) {
		array_push($allProfils, $group);
	}
	usort($allProfils, 'laclasse_addressbook_backend::group_cmp');

	$allGroups = array();
	foreach($this->allGroups as $group) {
		array_push($allGroups, $group);
	}
	usort($allGroups, 'laclasse_addressbook_backend::group_cmp');

	return $this->filter_result(array_merge($allProfils, $allGroups), $search, array('name'));
  }

  public function list_records($cols=null, $subset=0)
  {
    $all = $this->persons;
	// filter by the current group (if set)
    if($this->current_group !== null) {
      $all = $this->filter_field($all, $this->current_group, 'groups');
    }

	// filter by a keywords string if set
	if($this->filter !== null) {
		$all = $this->filter_result($all, $this->filter, '*');
	}

	$this->result = $this->array_to_result($all, $this->page_size, $this->list_page);
    return $this->result;
  }

  public function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array())
  {
	$this->filter = $value;
	$res = $this->filter_result($this->persons, $value, $fields);
	$this->result = $this->array_to_result($res);
    return $this->result;
  }

  public function count()
  {
    return new rcube_result_set(1, ($this->list_page-1) * $this->page_size);
  }

  public function get_result()
  {
    return $this->result;
  }

  public function get_record($id, $assoc=false)
  {
	$this->result = $this->array_to_result($this->filter_field($this->persons, $id, 'ID'));
	return $assoc ? $this->result->first() : $this->result;
  }

  /**
   * Get group assignments of a specific contact record
   *
   * @param mixed Record identifier
   *
   * @return array List of assigned groups as ID=>Name pairs
   */
  function get_record_groups($id)
  {
	$res = $this->filter_field($this->persons, $id, 'ID');
	if(count($res) > 0) {
      $groups = array();
      foreach($res[0]['groups'] as $group)
        $groups[$group] = $group;
      return $groups;
    }
    else 
      return array();
  }

  function create_group($name)
  {
    return false;
  }

  function delete_group($gid)
  {
    return false;
  }

  function rename_group($gid, $newname)
  {
    return $newname;
  }

  function add_to_group($group_id, $ids)
  {
    return false;
  }

  function remove_from_group($group_id, $ids)
  {
     return false;
  }
}
