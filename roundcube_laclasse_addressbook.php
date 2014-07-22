<?php

require_once(dirname(__FILE__) . '/laclasse_addressbook_backend.php');

/**
 * Plugin to add a new address book
 * from laclasse.com webservices
 *
 * @license GPLv3+
 * @author Daniel Lacroix
 */
class roundcube_laclasse_addressbook extends rcube_plugin
{
  private $abooks = null;

  public function init()
  {
	$this->load_config();
    $this->load_abooks();

    $this->add_hook('addressbooks_list', array($this, 'address_sources'));
    $this->add_hook('addressbook_get', array($this, 'get_address_book'));

    // use this address book for autocompletion queries
    // (maybe this should be configurable by the user?)
    $config = rcmail::get_instance()->config;
    $sources = (array) $config->get('autocomplete_addressbooks', array('sql'));

    foreach($this->abooks as $abook) {
      if(!in_array($abook['id'], $sources)) {
        $sources[] = $abook['id'];
      }
    }
    $config->set('autocomplete_addressbooks', $sources);
  }

  function load_abooks()
  {
    if($this->abooks === null) {
      $this->abooks = array();

      $cfg = rcmail::get_instance()->config->all();

      $user_data = json_decode(interroger_annuaire_ENT(
        $cfg['laclasse_addressbook_api_user'].rcmail::get_instance()->user->get_username(true),
        $cfg['laclasse_addressbook_app_id'], $cfg['laclasse_addressbook_api_key'],
        array('expand' => 'true')));

      if($user_data->etablissements !== null) {
        foreach($user_data->etablissements as $etab) {
          $abook = array(
            'id' => $etab->code_uai, 'name' => $etab->nom, 
            'readonly' => true, 'groups' => true, 'autocomplete' => true);
          $this->abooks[] = $abook;
        }
      }
    }
  }

  public function address_sources($p)
  {
    foreach($this->abooks as $abook) {
      $p['sources'][$abook['id']] = $abook;
    }
    return $p;
  }

  public function get_address_book($p)
  {
    foreach($this->abooks as $abook) {
      if($p['id'] === $abook['id'])
        $p['instance'] = new laclasse_addressbook_backend($abook['id'], $abook['name']);
    }
    return $p;
  }
}
