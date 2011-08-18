<?php
/**
 * @package org.openpsa.core
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

/**
 * Helper class that serves as a cache for OpenPSA site information
 *
 * It locates topics for specific components used in OpenPSA and automatically
 * generates a cached version of the site structure in the config snippet
 *
 * @package org.openpsa.core
 */
class org_openpsa_core_siteconfig extends midcom_baseclasses_components_purecode
{
    /**
     * The components for which we're creating the structure information
     *
     * @var array
     */
    private $components = array();

    private $data = null;

    /**
     * The snippet we're working with
     *
     * @var midcom_db_snippet
     */
    private $snippet = null;

    /**
     * The singleton siteconfig instance
     *
     * @var org_openpsa_core_siteconfig
     */
    private static $instance = null;

    public function __construct()
    {
        $this->_component = 'org.openpsa.core';

        if (!$_MIDCOM->componentloader->is_loaded($this->_component))
        {
            $_MIDCOM->componentloader->load($this->_component);
        }

        parent::__construct();

        $this->components = $this->_config->get('siteconfig_components');
        $this->load_snippet();
    }

   public static function get_instance()
   {
       if (is_null(self::$instance))
       {
           self::$instance = new self;
       }

       return self::$instance;
   }


    private function initialize_site_structure()
    {
        $nodes = array();
        foreach ($this->components as $component)
        {
            $nodes[$component] = midcom_helper_misc::find_node_by_component($component);
        }

        if (empty($nodes))
        {
            return;
        }

        foreach ($nodes as $component => $node)
        {
            $parts = explode('.', $component);
            $last = array_pop($parts);
            $node_guid = 'false';
            $node_full_url = 'false';
            $node_relative_url = 'false';
            if (is_array($node))
            {
              $node_guid = "'" . $node[MIDCOM_NAV_OBJECT]->guid . "'";
              $node_full_url = "'" . $node[MIDCOM_NAV_FULLURL] . "'";
              $node_relative_url = "'" . $node[MIDCOM_NAV_RELATIVEURL] . "'";
            }
            $this->set_config_value($last . '_guid', $node_guid);
            $this->set_config_value($last . '_full_url', $node_full_url);
            $this->set_config_value($last . '_relative_url', $node_relative_url);
        }

        $_MIDCOM->auth->request_sudo('org.openpsa.core');
        $this->snippet->update();
        $_MIDCOM->auth->drop_sudo();

        $_MIDCOM->uimessages->add($this->_l10n->get('org.openpsa.core'), $this->_l10n->get('site structure cache created'), 'info');
    }

    /**
     * Helper function to set the values in the config snippet
     *
     * @param string $key The config key to set
     * @param string $value The config value to set
     */
    private function set_config_value($key, $value)
    {
        if (strpos($this->snippet->code, $key) !== false)
        {
            $this->snippet->code = preg_replace("/^.+?" . $key . ".+?$/m", " '" . $key . "' => " . $value . ",", $this->snippet->code);
        }
        else
        {
            $this->snippet->code = $this->snippet->code . " '" . $key . "' => " . $value . ",\n";
        }
    }

    /**
     * Save the configuration to the config snippet
     */
    private function load_snippet()
    {
        $_MIDCOM->auth->request_sudo('org.openpsa.core');
        $lib_snippetdir = new midcom_db_snippetdir();
        $lib_snippetdir->get_by_path("/org.openpsa.cache");
        if (!$lib_snippetdir->guid)
        {
            $sd = new midcom_db_snippetdir();
            $sd->name = 'org.openpsa.cache';
            if (!$sd->create())
            {
                throw new midcom_error("Failed to create snippetdir /org.openpsa.cache: " . midcom_connection::get_error_string());
            }
            $lib_snippetdir = new midcom_db_snippetdir($sd->guid);
        }

        $this->snippet = new midcom_db_snippet();
        $this->snippet->get_by_path("/org.openpsa.cache/siteconfig");

        if ($this->snippet->id == false)
        {
            $this->snippet = new midcom_db_snippet();
            $this->snippet->up = $lib_snippetdir->id;
            $this->snippet->name = 'siteconfig';
            $this->snippet->code = "//AUTO-GENERATED BY org_openpsa_core_siteconfig\n";
            $this->snippet->create();
            $this->initialize_site_structure();
        }
        $_MIDCOM->auth->drop_sudo();
        eval ("\$array = array ( {$this->snippet->code}\n );");
        $this->data = $array;
    }

    /**
     * Check if a node for a given component is available
     *
     * @param string $component the component to look for
     */
    public function node_exists($component)
    {
        $guid = $this->get_node_guid($component);
        return !empty($guid);
    }

    /**
     * Helper function to retrieve the full URL for the first topic of a given component
     *
     * @param string $component the component to look for
     * @return mixed The component URL or false
     */
    public function get_node_full_url($component)
    {
        if (!array_key_exists($component, $this->components))
        {
            return false;
        }
        $parts = explode('.', $component);
        $last = array_pop($parts);

        return $this->get($last . '_full_url');
    }

    /**
     * Helper function to retrieve the relative URL for the first topic of a given component
     *
     * @param string $component The component to look for
     * @return mixed the component URL or false
     */
    public function get_node_relative_url($component)
    {
        if (!array_key_exists($component, $this->components))
        {
            return false;
        }
        $parts = explode('.', $component);
        $last = array_pop($parts);
        return $this->get($last . '_relative_url');
    }

    /**
     * Helper function to retrieve the GUID for the first topic of a given component
     *
     * @param string $component the component to look for
     * @return mixed the component URL or false
     */
    public function get_node_guid($component)
    {
        if (!array_key_exists($component, $this->components))
        {
            return false;
        }
        $parts = explode('.', $component);
        $last = array_pop($parts);
        return $this->get($last . '_guid');
    }

    private function get($key)
    {
    	if (!array_key_exists($key, $this->data))
        {
        	return null;
        }
        return $this->data[$key];
    }

    /**
     * Load "my company" or "owner company", the group that is the main user of this instance
     *
     * @return boolean Indicating success
     */
    public function get_my_company_guid()
    {
        $my_company_guid = $this->_config->get('owner_organization');
        $return = false;

        if (   !empty($my_company_guid)
            && mgd_is_guid($my_company_guid))
        {
            $return = $my_company_guid;
        }
        else
        {
            if ($_MIDCOM->auth->admin)
            {
                $_MIDCOM->uimessages->add
                (
                    $_MIDCOM->i18n->get_string('org.openpsa.core', 'org.openpsa.core'),
                    $_MIDCOM->i18n->get_string('owner organization couldnt be found', 'org.openpsa.core'),
                    'error'
                );
            }
        }
        return $return;
    }
}
?>