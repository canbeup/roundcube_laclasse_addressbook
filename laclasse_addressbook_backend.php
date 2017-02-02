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
  private $user;

  public function __construct($id, $name, $user_data)
  {
    $this->user = $user_data;
	$this->id = $id;
    $this->name = $name;
	$this->allGroups = array();
	$this->allProfils = array();
    $cfg = rcmail::get_instance()->config->all();
    $this->cfg = $cfg;
	$this->data = json_decode(interroger_annuaire_ENT(
      $cfg['laclasse_addressbook_api_etab'].$id,
      $cfg['laclasse_addressbook_app_id'], $cfg['laclasse_addressbook_api_key'],
      array('expand' => 'true')));

	// check if the user is only a student ('ELV') in the current structure
	$this->profil_elv = true;
	foreach($this->user->profils as $p) {
		if($p->etablissement_code_uai === $id) {
			if($p->profil_id !== 'ELV') {
                $this->profil_elv = false;
			}
		}
	}

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
	// load groups
	foreach($this->data->groups as $record) {
		$name = (($record->libelle !== null) ? $record->libelle : $record->libelle_aaf);
		$this->allGroups['ENS' . $record->id] = array('ID' => 'ENS' . $record->id, 'sortname' => $name, 'name' => $name . ' Enseignants');
		$this->allGroups['ELV' . $record->id] = array('ID' => 'ELV' . $record->id, 'sortname' => $name, 'name' => $name  . ' Élèves');
	}
	foreach($this->data->profils as $record) {
		// RIGHTS: student cant see parents emails
		if($this->profil_elv && ($record->id == 'TUT')) {
			continue;
		}

		$this->allGroups[$record->id] = array('ID' => $record->id, 'sortname' => $record->description, 'name' => $record->description);
	}

	$this->persons = array();
	foreach($this->data->users as $record) {
		$email = null;
		// RIGHTS: student cant see parents emails
		if($this->profil_elv && (count($record->profils) == 1) && ($record->profils[0] == 'TUT')) {
			continue;
		}

		if($record->emails !== null) {
			foreach($record->emails as $emailRecord) {
				if($emailRecord->main) {
					$email = $emailRecord->email;
					$main = TRUE;
				}
				else if($email === null)
					$email = $emailRecord->email;
				else if(($emailRecord->type === "Ent") && ($main === FALSE))
					$email = $emailRecord->email;
			}
		}

		// search groups
		$groups = array();
		if($record->student_in_groups !== null) {
			foreach($record->student_in_groups as $groupRecord) {
				array_push($groups, 'ELV' . $groupRecord);
			}
		}
		if($record->teacher_in_groups !== null) {
			foreach($record->teacher_in_groups as $groupRecord) {
				array_push($groups, 'ENS' . $groupRecord);
			}
		}

		// handle profils like groups
		if($record->profils !== null) {
			foreach($record->profils as $groupRecord) {
				array_push($groups, $groupRecord);
			}
		}

		$photo = null;
		if(($record->avatar !== null) && ($record->avatar !== 'empty') && (strpos($record->avatar, '/default_avatar/') === false)) {
			$photo = $this->cfg['laclasse_addressbook_api_avatar'].$record->avatar;
		}

		array_push($this->persons, array(
			'ID' => $record->{'ent_id'},
			'name' => $record->{'firstname'}.' '.$record->{'lastname'},
			'firstname' => $record->{'firstname'},
			'surname' => $record->{'lastname'},
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
		$words = array();
	    foreach(explode(' ', $search) as $word) {
			array_push($words, iconv('UTF-8', 'ASCII//TRANSLIT', strtolower($word)));
		}
		$res = array();
		foreach($all as $item) {
			$match = true;
			foreach($words as $word) {
				$word_match = false;
				foreach($fields as $field) {
					$word_match = $word_match || (strrpos(iconv('UTF-8', 'ASCII//TRANSLIT', strtolower($item[$field])), $word) !== false);
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
    return strcmp($a["sortname"], $b["sortname"]);
  }

  static function user_cmp($a, $b)
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
