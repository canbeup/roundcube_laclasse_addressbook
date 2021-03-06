<?php

require_once dirname(__FILE__) . '/laclasse_addressbook_backend.php';

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
    public $task = 'addressbook|mail';
    private $profilesTypes = null;

    public function init()
    {
        // error_log('roundcube_laclasse_addressbook::init');
        $this->load_config();

        $this->add_hook('ready', array($this, 'ready'));
        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));

    }

    public function ready($args)
    {
        // error_log('roundcube_laclasse_addressbook::ready ' . $args['task'] . ' ' . $args['action']);

        if ($args['action'] == 'autocomplete') {
            // use this address book for autocompletion queries
            // (maybe this should be configurable by the user?)
            $config = rcmail::get_instance()->config;
            $sources = (array) $config->get('autocomplete_addressbooks', array('sql'));
            $this->load_abooks();
            foreach ($this->abooks as $abook) {
                if (!in_array($abook['id'], $sources)) {
                    $sources[] = $abook['id'];
                }
            }
            $config->set('autocomplete_addressbooks', $sources);
        }
    }

    private function load_abooks()
    {
        if ($this->abooks === null) {
            // error_log('/!\ roundcube_laclasse_addressbook::load_abooks');
            $this->abooks = array();

            $cfg = rcmail::get_instance()->config->all();
            $username = rcmail::get_instance()->user->get_username(true);

            if ((gettype($username) === 'string') && ($username != '')) {
                $username = strtoupper($username);
                $user_data = json_decode(interroger_annuaire_ENT(
                    $cfg['laclasse_addressbook_api_user'] . $username,
                    $cfg['laclasse_addressbook_app_id'],
                    $cfg['laclasse_addressbook_api_key'], array()));

                $user_structures = json_decode(interroger_annuaire_ENT(
                    $cfg['laclasse_addressbook_api_etab'],
                    $cfg['laclasse_addressbook_app_id'], $cfg['laclasse_addressbook_api_key'],
                    array("expand" => "false", "profiles.user_id" => $username, "seenBy" => $username)));

                foreach ($user_structures as $structure) {
                    $found = false;
                    foreach ($this->abooks as $a) {
                        if ($a->id === $structure->id) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $abook = array(
                            'id' => $structure->id, 'name' => $structure->name,
                            'readonly' => true, 'groups' => true, 'autocomplete' => true,
                            'user' => $user_data);
                        $this->abooks[] = $abook;
                    }
                }

                // Ask directory for all the free groups the user is part of
                $freeGroups = json_decode(interroger_annuaire_ENT(
                    $cfg['laclasse_addressbook_api_group'],
                    $cfg['laclasse_addressbook_app_id'],
                    $cfg['laclasse_addressbook_api_key'],
                    array('expand' => 'false', 'type' => 'GPL', 'sort_dir' => 'asc', 'sort_col' => 'name', 'users.user_id' => $username, "seenBy" => $username)
                ));

                if (isset($freeGroups) && !empty($freeGroups)) {
                    foreach ($freeGroups as $freeGroup) {
                        $abook = array(
                            'id' => $freeGroup->id,
                            'name' => $freeGroup->name,
                            'readonly' => true,
                            'groups' => false,
                            'autocomplete' => true,
                            'user' => $user_data);
                        $this->abooks[] = $abook;
                    }
                }
            }
        }
    }

    private function load_profiles_types()
    {
        if ($this->profilesTypes === null) {
            $cfg = rcmail::get_instance()->config->all();
            $this->profilesTypes = json_decode(interroger_annuaire_ENT(
                $cfg['laclasse_addressbook_api_profiles_types'],
                $cfg['laclasse_addressbook_app_id'], $cfg['laclasse_addressbook_api_key'], array()));
        }
    }

    public function address_sources($p)
    {
        // error_log('roundcube_laclasse_addressbook::address_sources');
        $this->load_abooks();
        foreach ($this->abooks as $abook) {
            $p['sources'][$abook['id']] = $abook;
        }
        return $p;
    }

    public function get_address_book($p)
    {
        // error_log('roundcube_laclasse_addressbook::get_address_book');
        $this->load_abooks();
        $this->load_profiles_types();
        foreach ($this->abooks as $abook) {
            if ($p['id'] == $abook['id']) {
                $p['instance'] = new laclasse_addressbook_backend($abook['id'], $abook['name'], $abook['user'], $this->profilesTypes);
            }
        }
        return $p;
    }
}
