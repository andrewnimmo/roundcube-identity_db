<?php
/**
 * Identity DB
 *
 * Updates user's identities on each login from a database request 
 *
 * @author Alice Gaudon
 * @license MIT
 */
class identity_db extends rcube_plugin
{
    private $rc;
    private $config;
    private $db;

    function init()
    {
        $this->rc = rcmail::get_instance();
	$this->load_config();
	$this->config = $this->rc->config->get('identity_db');

        $this->add_hook('login_after', array($this, 'login_after'));
    }

    function login_after($args)
    {
        if (!$this->config['update_on_login']) {
	    return $args;
        }

	$user = $this->rc->user;

        $current_identities = $this->rc->user->list_emails();
        $target_identities = $this->fetch_aliases($user->data['username']);

        // If enabled, remove unknown identities
        if ($this->config['remove_unknown_identities']) {
            foreach ($current_identities as $existing_identity) {
                if (!in_array($existing_identity['email'], $target_identities)) {
                    // Remove
                    $id = $existing_identity['identity_id'];
                    $hook_result = $this->rc->plugins->exec_hook('identity_delete', $id);

                    if (!$hook_result['abort']) {
                        $this->rc->user->delete_identity($id);
                    }
                }
            }
        }

        $identity_name = '';

        // Add new identities
        foreach ($target_identities as $new_identity) {
            $exists = false;
            foreach ($current_identities as $existing_identity) {
                $identity_name = $existing_identity['name']; // Make it a bit random. TODO: pick it from the alias request OR a custom request
                if ($existing_identity['email'] == $new_identity) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;

            // Add
            $hook_result = $this->rc->plugins->exec_hook('identity_create', array(
                'login'  => false, // Most often triggered when the use was created long ago
                'record' => array(
                    'user_id'  => $this->rc->user->ID,
                    'standard' => $new_identity == $user->data['username'] ? 1 : 0,
                    'email'    => $new_identity,
                    'name'     => $identity_name
                ),
            ));

            if (!$hook_result['abort'] && $hook_result['record']['email']) {
                $user->insert_identity($hook_result['record']);
            }
        }

        return $args;
    }

    function fetch_aliases($username) {
        $aliases = array();

        $dbh = $this->get_dbh();

        $result = $dbh->query(preg_replace('/%u/', $dbh->escape($username), $this->config['identities_query']));

        while ($row = $dbh->fetch_array($result)) {
            array_push($aliases, $row[0]);
        }

        if(!in_array($username, $aliases)) {
            array_push($aliases, $username);
        }

        return $aliases;
    }

    function get_dbh() {
        if (!$this->db) {
            if ($dsn = $this->config['dsn']) {
                $this->db = rcube_db::factory($dsn);
                $this->db->set_debug((bool)$this->rc->config->get('sql_debug'));
                $this->db->db_connect('r'); // We only need to read
            } else {
                $this->db = $this->rc->get_dbh();
            }
        }

        return $this->db;
    }
}
